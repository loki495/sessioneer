<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\CodexTranscriptService;
use HostAgent\Services\Config;
use HostAgent\Services\ProcessRunner;

/** Native Codex runtime backed by app-server plus Codex's persistent queue. */
class CodexHeadlessRuntime implements RuntimeProvider
{
    /** @var \Closure(array<int,string>):array{exit:int,stdout:string,stderr:string} */
    private \Closure $processRunner;

    private string $codexBin;

    public function __construct(
        private ?CodexBridgeClient $client = null,
        ?callable $processRunner = null,
        ?string $codexBin = null,
    )
    {
        $this->client ??= new CodexBridgeClient();
        $this->processRunner = $processRunner !== null
            ? \Closure::fromCallable($processRunner)
            : static fn(array $cmd): array => ProcessRunner::run_process($cmd);
        $this->codexBin = $codexBin ?? Config::codex_bin();
    }

    public function id(): string { return RuntimeType::HEADLESS; }
    public function isHeadless(): bool { return true; }
    public function isTmux(): bool { return false; }

    public function create(array $options): array
    {
        $workdir = is_string($options['workdir'] ?? null) ? $options['workdir'] : '';
        if ($workdir === '' || $workdir[0] !== '/') {
            return ['ok' => false, 'message' => 'create() requires an absolute workdir'];
        }

        $params = ['cwd' => $workdir, 'approvalPolicy' => 'on-request', 'sandbox' => 'workspace-write'];
        foreach (['model', 'serviceTier'] as $key) {
            if (is_string($options[$key] ?? null) && $options[$key] !== '') {
                $params[$key] = $options[$key];
            }
        }

        $result = $this->client->request('thread/start', $params);
        $thread = is_array($result['result']['thread'] ?? null) ? $result['result']['thread'] : null;
        $id = is_string($thread['id'] ?? null) ? $thread['id'] : null;

        return $result['ok'] === true && $id !== null
            ? ['ok' => true, 'id' => $id, 'session' => $thread]
            : ['ok' => false, 'message' => $result['message'] ?? 'Codex did not return a thread id'];
    }

    public function list(): array
    {
        $result = CodexTranscriptService::list_threads(false, $this->client);
        return $result['ok'] === true
            ? ['ok' => true, 'sessions' => $result['threads'] ?? []]
            : $result;
    }

    public function detail(string $sessionRef): array
    {
        $result = $this->client->request('thread/read', ['threadId' => $sessionRef, 'includeTurns' => true]);

        // A thread/start result is writable immediately, but Codex does not
        // materialize its rollout until the first user message. Asking that
        // brand-new thread for turns is therefore rejected by design. Read
        // metadata only so session.php can render its normal empty-history
        // state and expose the composer for that first message.
        $readMessage = is_string($result['message'] ?? null) ? $result['message'] : '';
        if (
            ($result['ok'] ?? false) !== true
            && (
                str_contains($readMessage, 'includeTurns is unavailable before first user message')
                || str_contains($readMessage, 'list_turns is not supported yet')
            )
        ) {
            $result = $this->client->request('thread/read', ['threadId' => $sessionRef]);
        }

        if ($result['ok'] !== true || !is_array($result['result']['thread'] ?? null)) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Codex thread not found'];
        }

        $thread = $result['result']['thread'];
        $thread['writable'] = true;
        $thread['readOnlyReason'] = null;

        // Queued turns may run in another app-server, outside our bridge.
        if (is_array($thread['turns'] ?? null)) {
            $turns = $thread['turns'];
            $latest = $turns !== [] ? $turns[array_key_last($turns)] : null;
            $thread['lastTurnError'] = is_array($latest) && ($latest['status'] ?? null) === 'failed'
                ? CodexTranscriptService::turn_error_message($latest['error'] ?? null)
                : null;
        }

        return ['ok' => true, 'session' => $thread];
    }

    public function kill(string $sessionRef): array
    {
        $result = $this->client->request('thread/archive', ['threadId' => $sessionRef]);
        return $result['ok'] === true ? ['ok' => true, 'message' => 'Codex thread archived'] : $result;
    }

    public function status(string $sessionRef): array
    {
        $pending = $this->pending_prompt($sessionRef);
        if ($pending !== null) return ['ok' => true, 'status' => 'blocked', 'blocked' => $pending];
        $detail = $this->detail($sessionRef);
        if ($detail['ok'] !== true) return ['ok' => false, 'status' => 'idle', 'message' => $detail['message'] ?? 'Status unavailable'];
        $type = $detail['session']['status']['type'] ?? 'idle';
        return ['ok' => true, 'status' => $type === 'active' ? 'working' : 'idle', 'blocked' => null];
    }

    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array
    {
        // A thread/start result has no rollout until its first user message,
        // so the global queue cannot cold-resume it yet. Keep that one narrow
        // case on the private bridge that created and still has it loaded.
        $probe = $this->client->request('thread/read', ['threadId' => $sessionRef, 'includeTurns' => true]);
        $probeMessage = is_string($probe['message'] ?? null) ? $probe['message'] : '';
        if (
            ($probe['ok'] ?? false) !== true
            && (
                str_contains($probeMessage, 'includeTurns is unavailable before first user message')
                || str_contains($probeMessage, 'no rollout found for thread id')
            )
        ) {
            return $this->send_via_bridge($sessionRef, $text, $attachmentPaths);
        }

        if ($this->codexBin === '') {
            return ['ok' => false, 'message' => 'CODEX_BIN is not configured'];
        }

        $thread = is_array($probe['result']['thread'] ?? null) ? $probe['result']['thread'] : [];
        $cwd = is_string($thread['cwd'] ?? null) ? rtrim($thread['cwd'], '/') : '';
        $message = $text;
        $images = [];

        foreach ($attachmentPaths as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                $images[] = $path !== '' && $path[0] === '/' ? $path : ($cwd !== '' ? $cwd . '/' . $path : $path);
                continue;
            }

            $message = rtrim($message);
            $message .= ($message === '' ? '' : "\n") . '[Attached: ' . $path . ']';
        }

        $cmd = [$this->codexBin, 'queue', '--thread', $sessionRef, '--message', $message];
        if ($images !== []) {
            $cmd[] = '--image';
            array_push($cmd, ...$images);
        }

        $result = ($this->processRunner)($cmd);
        if ($result['exit'] !== 0) {
            $error = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
            return ['ok' => false, 'message' => $error !== '' ? $error : 'Codex could not queue the message'];
        }

        return ['ok' => true, 'message' => 'Message queued'];
    }

    /**
     * @param string[] $attachmentPaths
     * @return array<string,mixed>
     */
    private function send_via_bridge(string $sessionRef, string $text, array $attachmentPaths): array
    {
        $input = [['type' => 'text', 'text' => $text, 'text_elements' => []]];
        foreach ($attachmentPaths as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $input[] = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)
                ? ['type' => 'localImage', 'path' => $path]
                : ['type' => 'mention', 'name' => basename($path), 'path' => $path];
        }

        $result = $this->client->request('sessioneer/sendInput', ['threadId' => $sessionRef, 'input' => $input]);
        return $result['ok'] === true ? ['ok' => true, 'message' => 'Message sent'] : $result;
    }

    /**
     * Interrupts the bridge's currently tracked active turn.
     * @return array<string,mixed>
     */
    public function interrupt(string $sessionRef): array
    {
        return $this->client->request('sessioneer/interrupt', ['threadId' => $sessionRef]);
    }

    /**
     * Applies sticky model/effort settings to subsequent turns.
     * @return array<string,mixed>
     */
    public function set_mode(string $sessionRef, string $mode): array
    {
        return ['ok' => false, 'message' => 'Mode switching is not supported for headless sessions'];
    }

    public function update_settings(string $sessionRef, ?string $model = null, ?string $effort = null, ?string $provider = null): array
    {
        $params = ['threadId' => $sessionRef];
        if ($model !== null && $model !== '') $params['model'] = $model;
        if ($effort !== null && $effort !== '') $params['effort'] = $effort;
        if (count($params) === 1) return ['ok' => false, 'message' => 'No Codex settings supplied'];
        $result = $this->client->request('thread/settings/update', $params);
        return $result['ok'] === true ? ['ok' => true, 'message' => 'Codex settings updated'] : $result;
    }

    public function pending_prompt(string $sessionRef): ?array
    {
        $result = $this->client->request('sessioneer/pendingPrompt', ['threadId' => $sessionRef]);
        return $result['ok'] === true && is_array($result['prompt'] ?? null) ? $result['prompt'] : null;
    }

    public function answer_prompt(string $sessionRef, array $answers): array
    {
        return $this->client->request('sessioneer/answerPrompt', ['threadId' => $sessionRef, 'answers' => $answers]);
    }
}
