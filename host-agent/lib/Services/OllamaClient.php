<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * The one place that speaks to an Ollama server's HTTP API for a chat
 * session (AgentAdapter/model catalog, OllamaHeadlessRuntime, the health
 * box). Deliberately separate from QuickPromptRouter: that one talks to a
 * dedicated loopback router instance which keeps exactly one small model
 * loaded, so sharing it with real sessions would evict the router's model on
 * every turn.
 *
 * Two calls matter here: `/api/tags` (+ `/api/show` for capabilities) to list
 * what is actually pulled, and `/api/chat` to run one turn. Streaming is the
 * default because a local model on a small box can take minutes per reply,
 * and a silent wait reads as a hung session in the dashboard.
 *
 * Every failure is a handled result, never a throw: the session that asks has
 * to stay alive (and say why) with the server down, the model missing, or the
 * connection dropped mid-reply.
 */
final class OllamaClient
{
    /**
     * The general-purpose local server. Not the Quick Prompt router (11435):
     * see this class's own comment for why that one is not shared.
     */
    public const DEFAULT_URL = 'http://127.0.0.1:11434';

    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? Config::ollama_url(), '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * The installed version, used by the health box. null when unreachable.
     */
    public function version(): ?string
    {
        [$status, $decoded] = $this->request('GET', '/api/version', null, 4);
        $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;

        return $status === 200 && is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The models a new session can choose from: everything pulled on the
     * server, minus anything that cannot hold a conversation (an embedding
     * model pulled alongside a chat model would otherwise sit in the picker
     * and fail on the first prompt).
     *
     * @return array{ok:bool, models?:array<int, array<string, mixed>>, message?:string}
     */
    public function models(): array
    {
        [$status, $decoded] = $this->request('GET', '/api/tags', null, 6);

        if ($status === 0) {
            return ['ok' => false, 'message' => 'Ollama is not reachable at ' . $this->baseUrl];
        }

        if ($status !== 200 || !is_array($decoded) || !is_array($decoded['models'] ?? null)) {
            return ['ok' => false, 'message' => 'Ollama at ' . $this->baseUrl . ' did not list its models (HTTP ' . $status . ')'];
        }

        $rows = [];

        foreach ($decoded['models'] as $model) {
            $id = is_array($model) ? ($model['name'] ?? $model['model'] ?? null) : null;

            if (!is_string($id) || $id === '' || $this->cannot_chat($id)) {
                continue;
            }

            $details = is_array($model['details'] ?? null) ? $model['details'] : [];
            $parameterSize = is_string($details['parameter_size'] ?? null) ? $details['parameter_size'] : '';

            $rows[] = [
                'id' => $id,
                'name' => $parameterSize !== '' ? $id . ' (' . $parameterSize . ')' : $id,
                'size' => is_int($model['size'] ?? null) ? $model['size'] : null,
                'family' => is_string($details['family'] ?? null) ? $details['family'] : null,
                'modified_at' => is_string($model['modified_at'] ?? null) ? $model['modified_at'] : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

        return ['ok' => true, 'models' => $rows];
    }

    /**
     * Whether this model is served by this server, for a clear refusal before
     * a turn is appended to a session's history.
     */
    public function has_model(string $model): bool
    {
        $models = $this->models();

        if ($models['ok'] !== true) {
            return false;
        }

        foreach ($models['models'] ?? [] as $row) {
            if (($row['id'] ?? null) === $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs one turn. $messages is the whole conversation (Ollama is stateless
     * between HTTP requests), in `{role, content}` shape.
     *
     * $onChunk receives each content delta as it arrives, so a caller can
     * persist a partial answer; the returned `content` is always the complete
     * text regardless.
     *
     * @param array<int, array{role:string, content:string}> $messages
     * @param callable(string):void|null $onChunk
     * @return array{ok:bool, content:string, message?:string, done_reason?:?string, stats?:array<string, mixed>}
     */
    public function chat(string $model, array $messages, ?callable $onChunk = null, ?int $timeoutSeconds = null): array
    {
        $timeoutSeconds ??= Config::ollama_timeout_seconds();
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'keep_alive' => Config::sessioneer_config('OLLAMA_KEEP_ALIVE', '10m'),
        ];

        $bodyFile = tempnam(sys_get_temp_dir(), 'sessioneer-ollama-');
        $errFile = tempnam(sys_get_temp_dir(), 'sessioneer-ollama-err-');

        if ($bodyFile === false || $errFile === false) {
            return ['ok' => false, 'content' => '', 'message' => 'could not stage the request'];
        }

        file_put_contents($bodyFile, (string)json_encode($body));

        $cmd = [
            'curl', '--silent', '--show-error', '--no-buffer',
            '--connect-timeout', '5',
            '--max-time', (string)$timeoutSeconds,
            '--request', 'POST',
            '--header', 'Content-Type: application/json',
            '--data', '@' . $bodyFile,
            $this->baseUrl . '/api/chat',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // stderr to a file rather than a pipe: a chat turn can stream for
            // minutes, and an unread stderr pipe would deadlock the reply.
            2 => ['file', $errFile, 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($bodyFile);
            @unlink($errFile);

            return ['ok' => false, 'content' => '', 'message' => 'could not start curl'];
        }

        fclose($pipes[0]);

        $content = '';
        $stats = [];
        $doneReason = null;
        $failure = null;

        while (($line = fgets($pipes[1])) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (!is_array($event)) {
                // A non-JSON line is curl's own noise or a truncated stream
                // event; keep reading rather than failing a whole turn on it.
                continue;
            }

            if (is_string($event['error'] ?? null)) {
                $failure = $event['error'];
                break;
            }

            $delta = is_array($event['message'] ?? null) ? ($event['message']['content'] ?? null) : null;
            if (is_string($delta) && $delta !== '') {
                $content .= $delta;
                if ($onChunk !== null) {
                    $onChunk($delta);
                }
            }

            if (($event['done'] ?? false) === true) {
                $doneReason = is_string($event['done_reason'] ?? null) ? $event['done_reason'] : null;
                foreach (['eval_count', 'prompt_eval_count', 'total_duration'] as $key) {
                    if (isset($event[$key])) {
                        $stats[$key] = $event[$key];
                    }
                }
            }
        }

        fclose($pipes[1]);
        $exit = proc_close($process);
        $stderr = trim((string)@file_get_contents($errFile));
        @unlink($bodyFile);
        @unlink($errFile);

        if ($failure !== null) {
            return ['ok' => false, 'content' => $content, 'message' => $failure];
        }

        if ($exit !== 0) {
            $message = $stderr !== '' ? $stderr : 'curl exited with code ' . $exit;

            return ['ok' => false, 'content' => $content, 'message' => $this->explain_transport_failure($message)];
        }

        if ($content === '') {
            return ['ok' => false, 'content' => '', 'message' => 'the model returned no text'];
        }

        return ['ok' => true, 'content' => $content, 'done_reason' => $doneReason, 'stats' => $stats];
    }

    /**
     * Whether a pulled model can hold a conversation. Ollama reports this per
     * model in /api/show `capabilities` (`completion`, `tools`, `embedding`,
     * ...). A server too old to report capabilities gets the benefit of the
     * doubt: hiding every model on it would be worse than one clear error.
     */
    private function cannot_chat(string $model): bool
    {
        [$status, $decoded] = $this->request('POST', '/api/show', ['model' => $model], 4);

        if ($status !== 200 || !is_array($decoded)) {
            return false;
        }

        $capabilities = $decoded['capabilities'] ?? null;
        if (!is_array($capabilities)) {
            return false;
        }

        return !in_array('completion', $capabilities, true);
    }

    /**
     * A transport failure explained in the operator's terms: the two cases
     * that actually happen are the server not running and a model that is not
     * pulled, and curl's own wording for both is unhelpful.
     */
    private function explain_transport_failure(string $message): string
    {
        if (str_contains($message, 'Connection refused') || str_contains($message, 'Failed to connect')) {
            return 'Ollama is not reachable at ' . $this->baseUrl;
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'Operation timed out')) {
            return 'the model took longer than ' . Config::ollama_timeout_seconds() . 's to answer';
        }

        return $message;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0:int, 1:mixed} HTTP status (0 when the transport failed) and the decoded body
     */
    private function request(string $method, string $path, ?array $body, int $timeoutSeconds): array
    {
        $cmd = [
            'curl', '--silent', '--show-error',
            '--connect-timeout', '3',
            '--max-time', (string)$timeoutSeconds,
            '--request', $method,
            '--write-out', "\n%{http_code}",
        ];

        $bodyFile = null;

        if ($body !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'sessioneer-ollama-');
            if ($tmp !== false) {
                file_put_contents($tmp, (string)json_encode($body));
                $bodyFile = $tmp;
                $cmd[] = '--header';
                $cmd[] = 'Content-Type: application/json';
                $cmd[] = '--data';
                $cmd[] = '@' . $bodyFile;
            }
        }

        $cmd[] = $this->baseUrl . $path;

        $result = ProcessRunner::run_process($cmd);

        if ($bodyFile !== null) {
            @unlink($bodyFile);
        }

        if ($result['exit'] !== 0) {
            return [0, null];
        }

        $parts = explode("\n", trim($result['stdout']));
        $status = (int)array_pop($parts);
        $raw = implode("\n", $parts);

        return [$status, $raw === '' ? null : json_decode($raw, true)];
    }
}
