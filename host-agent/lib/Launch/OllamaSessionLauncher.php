<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Agents\OllamaAdapter;
use HostAgent\Runtimes\OllamaHeadlessRuntime;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\SidecarStore;

/**
 * An Ollama launch is a headless session against the local server's HTTP API
 * (OllamaHeadlessRuntime): no tmux pane, no CLI argv, and no tools. A turn
 * runs in a detached worker, so a delivered prompt means "the turn started" -
 * the reply lands in the session's transcript and its status goes back to
 * idle when it finishes.
 *
 * There is no agent-side conversation store to resume from, unlike Claude and
 * Codex: the transcript OllamaTranscriptService keeps is the only copy, so a
 * conversation id Sessioneer does not already track cannot be picked up again.
 */
class OllamaSessionLauncher implements SessionLauncher
{
    use PeerLookup;

    private const AGENT_ID = OllamaHeadlessRuntime::AGENT_ID;

    public function __construct(private ?OllamaHeadlessRuntime $runtime = null, private ?OllamaAdapter $adapter = null)
    {
    }

    public function agent_id(): string
    {
        return self::AGENT_ID;
    }

    public function launch(array $request): array
    {
        $prompt = is_string($request['prompt'] ?? null) ? trim($request['prompt']) : '';
        $sessionId = is_string($request['agent_session_id'] ?? null) ? trim($request['agent_session_id']) : '';

        $refusal = $this->unsupported_options($request);

        if ($refusal !== null) {
            return ['ok' => false, 'message' => $refusal];
        }

        if ($sessionId !== '') {
            $tracked = $this->tracked($sessionId);

            if ($tracked === null) {
                return ['ok' => false, 'message' => "No tracked Ollama session found for '{$sessionId}': its conversation lives only in Sessioneer's own transcript, so an id Sessioneer does not track cannot be resumed (start a new session)"];
            }

            return $prompt === ''
                ? ['ok' => true, 'name' => $tracked, 'action' => 'reused', 'prompt_sent' => false, 'message' => "Session {$tracked} already tracks that conversation"]
                : $this->deliver($tracked, 'reused', $prompt);
        }

        $workdir = is_string($request['workdir'] ?? null) ? $request['workdir'] : '';

        if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
            return ['ok' => false, 'message' => 'A new session needs an existing absolute workdir'];
        }

        $model = is_string($request['model'] ?? null) ? trim($request['model']) : '';
        $catalog = ($this->adapter ??= new OllamaAdapter())->model_catalog();

        if ($catalog['ok'] !== true) {
            return ['ok' => false, 'message' => 'Could not read the Ollama model list to validate --model: ' . (string)($catalog['message'] ?? 'unknown error')];
        }

        $available = array_map(static fn (array $row): string => (string)$row['id'], $catalog['models'] ?? []);

        if ($model === '') {
            return ['ok' => false, 'message' => 'A new Ollama session needs a model (' . $this->available_message($available) . ')'];
        }

        if (!in_array($model, $available, true)) {
            return ['ok' => false, 'message' => "Model '{$model}' is not on this Ollama server (" . $this->available_message($available) . ')'];
        }

        $created = $this->runtime()->create(['workdir' => $workdir, 'model' => $model]);
        $name = $created['ok'] === true && is_string($created['name'] ?? null) ? $created['name'] : null;

        if ($name === null) {
            return ['ok' => false, 'message' => (string)($created['message'] ?? 'Ollama did not create a session')];
        }

        // Tracked at once, as the dashboard's New Session does, so the name resolves before the next sync.
        $this->track($name, $workdir, $created);

        return $prompt === ''
            ? ['ok' => true, 'name' => $name, 'action' => 'created', 'prompt_sent' => false, 'message' => "Session {$name} created"]
            : $this->deliver($name, 'created', $prompt);
    }

    public function kill(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';

        if ($target === '') {
            return ['ok' => false, 'message' => 'Kill needs a session name or conversation id'];
        }

        $name = $this->tracked($target);

        if ($name === null) {
            return ['ok' => false, 'message' => "No tracked Ollama session found for '{$target}'"];
        }

        // status() heals a "working" row left by a dead worker, so a crashed turn is never mistaken for a live one.
        $status = (string)$this->runtime()->status($name)['status'];

        if ($status !== 'idle' && empty($request['force'])) {
            return ['ok' => false, 'name' => $name, 'message' => "Session {$name} is {$status}; stopping it would drop that answer (use --force)"];
        }

        $killed = $this->runtime()->kill($name);

        if ($killed['ok'] !== true) {
            return ['ok' => false, 'name' => $name, 'message' => (string)($killed['message'] ?? 'Could not stop the session')];
        }

        return ['ok' => true, 'name' => $name, 'message' => "Session {$name} stopped; its transcript is kept"];
    }

    public function list_sessions(array $request): array
    {
        $sessions = [];

        foreach ($this->runtime()->list()['sessions'] ?? [] as $entry) {
            $sessions[] = [
                'name' => $entry['name'],
                'agent_session_id' => $entry['agent_session_id'],
                'status' => $entry['status'],
                'workdir' => $entry['workdir'],
                'title' => $entry['title'],
                'model' => $entry['current_model'],
                'effort' => null,
            ];
        }

        return ['ok' => true, 'sessions' => $sessions];
    }

    public function send(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';
        $message = is_string($request['message'] ?? null) ? trim($request['message']) : '';
        $from = is_string($request['from'] ?? null) ? trim($request['from']) : '';

        if ($target === '' || $message === '') {
            return ['ok' => false, 'message' => 'Send needs a target session and a message'];
        }

        $sender = $from !== '' ? SidecarStore::read_sidecar($from) : null;

        if ($sender === null) {
            return ['ok' => false, 'message' => 'Send must run inside a Sessioneer session (SESSIONEER_SELF names none that is tracked); use --resume to message a session as yourself'];
        }

        $name = $this->tracked($target);

        if ($name === null) {
            return ['ok' => false, 'message' => "No tracked Ollama session found for '{$target}'"];
        }

        if ($name === $from) {
            return ['ok' => false, 'message' => 'A session cannot send a message to itself'];
        }

        return $this->deliver($name, 'sent', $this->peer_stamp($from, $sender) . " {$message}");
    }

    /** @param array<string, mixed> $request */
    private function unsupported_options(array $request): ?string
    {
        $profile = is_string($request['profile'] ?? null) ? trim($request['profile']) : '';
        $effort = is_string($request['effort'] ?? null) ? trim($request['effort']) : '';
        $mode = is_string($request['starting_mode'] ?? null) ? trim($request['starting_mode']) : '';

        return match (true) {
            $profile !== '' => '--profile is not supported for Ollama: a local model runs under no account',
            $effort !== '' => '--effort is not supported for Ollama: a local model has no reasoning levels',
            $mode !== '' => '--mode is not supported for Ollama: its sessions have no permission modes',
            !empty($request['no_tools']) => '--no-tools is not supported for Ollama: its sessions never have tools',
            !empty($request['enable_task_tools']) => '--task-tools is not supported for Ollama: its sessions never have tools',
            default => null,
        };
    }

    /** @param array<int, string> $available */
    private function available_message(array $available): string
    {
        return $available === [] ? 'this server has none pulled' : 'available: ' . implode(', ', $available);
    }

    private function runtime(): OllamaHeadlessRuntime
    {
        return $this->runtime ??= new OllamaHeadlessRuntime();
    }

    /** @param array<string, mixed> $created */
    private function track(string $name, string $workdir, array $created): void
    {
        $session = is_array($created['session'] ?? null) ? $created['session'] : [];
        $title = is_string($session['title'] ?? null) && $session['title'] !== '' ? $session['title'] : null;

        SidecarStore::write_sidecar($name, [
            'workdir' => $workdir,
            'spawned_at' => time(),
            'agent_session_id' => $name,
            'spawned_by_app' => true,
            'agent' => self::AGENT_ID,
            'runtime' => RuntimeType::HEADLESS,
            'title' => $title,
        ]);
    }

    /** The session name of a tracked Ollama session, from a name or a conversation id. */
    private function tracked(string $target): ?string
    {
        $name = SidecarStore::read_sidecar($target) !== null ? $target : SidecarStore::find_by_agent_session_id($target);
        $sidecar = $name !== null ? SidecarStore::read_sidecar($name) : null;

        return $name !== null && ($sidecar['agent'] ?? null) === self::AGENT_ID ? $name : null;
    }

    /** @return array<string, mixed> */
    private function deliver(string $name, string $action, string $prompt): array
    {
        $sent = $this->runtime()->send_message($name, $prompt);

        if ($sent['ok'] !== true) {
            return ['ok' => false, 'name' => $name, 'action' => $action, 'prompt_sent' => false, 'message' => "Session {$name} {$action}, but the prompt was not delivered: " . (string)($sent['message'] ?? 'unknown error')];
        }

        return ['ok' => true, 'name' => $name, 'action' => $action, 'prompt_sent' => true, 'message' => "Session {$name} {$action}; prompt delivered"];
    }
}
