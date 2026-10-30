<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Runtimes\OpenCodeServeClient;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * OpenCode launches go through the `opencode serve` HTTP API
 * (OpenCodeServeClient): no tmux pane and no CLI argv. A session's name is its
 * `ses_*` conversation id, so a conversation id and a session name are the
 * same thing here, exactly like Codex's thread id.
 *
 * A new session is POST /api/session with its location.directory; `--model`
 * is applied afterwards with the session-scoped model endpoint because a v2
 * prompt carries no model field. An id Sessioneer already tracks receives the
 * prompt directly; an untracked id is resumed through the same create
 * endpoint (with its own id in the body) so the conversation continues rather
 * than forks. A kill interrupts first, then deletes the serve session (its
 * transcript stays in opencode.db and the id can be resumed again).
 */
class OpenCodeSessionLauncher implements SessionLauncher
{
    use PeerLookup;

    private const AGENT_ID = 'opencode';

    public function __construct(private ?OpenCodeServeClient $client = null)
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

        $model = is_string($request['model'] ?? null) ? trim($request['model']) : '';
        $parts = $this->model_parts($model);

        if (is_string($parts)) {
            return ['ok' => false, 'message' => $parts];
        }

        [$provider, $modelId] = $parts;

        if ($sessionId !== '') {
            $tracked = $this->tracked($sessionId);

            if ($tracked !== null) {
                return $prompt === ''
                    ? ['ok' => true, 'name' => $tracked, 'action' => 'reused', 'prompt_sent' => false, 'message' => "Session {$tracked} already tracks that conversation"]
                    : $this->deliver($tracked, 'reused', $prompt);
            }

            $workdir = $this->resume_workdir($sessionId, $request);

            if (is_array($workdir)) {
                return $workdir;
            }

            $resumed = $this->client()->resume_session($sessionId, $workdir);

            if (($resumed['ok']) !== true) {
                return ['ok' => false, 'message' => (string)($resumed['message'] ?? "Could not resume OpenCode session '{$sessionId}'")];
            }

            $id = is_string($resumed['id'] ?? null) && $resumed['id'] !== '' ? $resumed['id'] : $sessionId;
            $this->track($id, $workdir, is_array($resumed['session'] ?? null) ? $resumed['session'] : []);

            return $prompt === ''
                ? ['ok' => true, 'name' => $id, 'action' => 'resumed', 'prompt_sent' => false, 'message' => "Session {$id} resumed"]
                : $this->deliver($id, 'resumed', $prompt);
        }

        if ($prompt === '') {
            return ['ok' => false, 'message' => 'A new session needs a prompt'];
        }

        $workdir = is_string($request['workdir'] ?? null) ? $request['workdir'] : '';

        if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
            return ['ok' => false, 'message' => 'A new session needs an existing absolute workdir'];
        }

        $created = $this->client()->create_session($workdir);

        if (($created['ok']) !== true || !is_string($created['id'] ?? null) || $created['id'] === '') {
            return ['ok' => false, 'message' => (string)($created['message'] ?? 'OpenCode did not create a session')];
        }

        $id = $created['id'];
        $this->track($id, $workdir, is_array($created['session'] ?? null) ? $created['session'] : []);

        if ($provider !== '') {
            $applied = $this->client()->set_model($id, $provider, $modelId);

            if (($applied['ok']) !== true) {
                return ['ok' => false, 'name' => $id, 'action' => 'created', 'prompt_sent' => false, 'message' => "Session {$id} created, but its model was not set: " . (string)($applied['message'] ?? 'unknown error')];
            }
        }

        return $this->deliver($id, 'created', $prompt);
    }

    public function kill(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';

        if ($target === '') {
            return ['ok' => false, 'message' => 'Kill needs a session name or conversation id'];
        }

        $name = $this->tracked($target);

        if ($name === null) {
            return ['ok' => false, 'message' => "No tracked OpenCode session found for '{$target}'"];
        }

        $status = $this->status_of($name);

        if ($status === null) {
            return ['ok' => false, 'name' => $name, 'message' => "Could not read the status of {$name} from opencode serve"];
        }

        if ($status !== 'idle' && empty($request['force'])) {
            return ['ok' => false, 'name' => $name, 'message' => "Session {$name} is {$status}; stopping it would drop that work (use --force)"];
        }

        // Best effort: a session that is already idle can reject an interrupt,
        // and the delete below is what actually stops it.
        $this->client()->interrupt($name);
        $killed = $this->client()->delete_session($name);

        if (($killed['ok']) !== true) {
            return ['ok' => false, 'name' => $name, 'message' => (string)($killed['message'] ?? 'Could not stop the session')];
        }

        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);

        return ['ok' => true, 'name' => $name, 'message' => "Session {$name} stopped; its conversation can be resumed"];
    }

    public function list_sessions(array $request): array
    {
        $sessions = [];

        foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
            if (($row['agent'] ?? self::AGENT_ID) !== self::AGENT_ID) {
                continue;
            }

            $state = SessionStatusStore::read_status($row['session_name']);
            $sessions[] = [
                'name' => $row['session_name'],
                'agent_session_id' => $row['agent_session_id'],
                'status' => is_string($state['status'] ?? null) ? $state['status'] : 'idle',
                'workdir' => $row['workdir'],
                'title' => $row['title'],
                'model' => is_string($state['model'] ?? null) ? $state['model'] : null,
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
            return ['ok' => false, 'message' => "No tracked OpenCode session found for '{$target}'"];
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
            $profile !== '' => '--profile is not supported for OpenCode: a serve session has no account profile',
            $effort !== '' => '--effort is not supported for OpenCode: the serve has no reasoning-level control',
            $mode !== '' => '--mode is not supported for OpenCode: its sessions have no permission modes',
            !empty($request['no_tools']) => '--no-tools is not supported for OpenCode: the serve session\'s tools are not configurable here',
            !empty($request['enable_task_tools']) => '--task-tools is not supported for OpenCode: the serve session\'s tools are not configurable here',
            default => null,
        };
    }

    /**
     * `provider/model`, split on the first slash only: a model id may itself
     * contain slashes (e.g. an OpenRouter provider with a namespaced model).
     *
     * @return array{0:string, 1:string}|string the parts, or the refusal message
     */
    private function model_parts(string $model): array|string
    {
        if ($model === '') {
            return ['', ''];
        }

        $slash = strpos($model, '/');

        if ($slash === false || $slash === 0 || $slash === strlen($model) - 1) {
            return "--model for OpenCode must be provider/model (for example opencode-go/gpt-6.1)";
        }

        return [substr($model, 0, $slash), substr($model, $slash + 1)];
    }

    /**
     * The directory an untracked conversation should be resumed in: the one
     * given, or the one the serve already records for that session.
     *
     * @param array<string, mixed> $request
     * @return string|array{ok:false, message:string}
     */
    private function resume_workdir(string $sessionId, array $request): string|array
    {
        $workdir = is_string($request['workdir'] ?? null) ? $request['workdir'] : '';

        if ($workdir !== '' && $workdir[0] === '/' && is_dir($workdir)) {
            return $workdir;
        }

        $detail = $this->client()->get_session($sessionId);

        if (($detail['ok']) !== true) {
            return ['ok' => false, 'message' => "No OpenCode session found for '{$sessionId}': " . (string)($detail['message'] ?? 'unknown error')];
        }

        $session = is_array($detail['session'] ?? null) ? $detail['session'] : [];
        $location = is_array($session['location'] ?? null) ? $session['location'] : [];
        $directory = $session['directory'] ?? $location['directory'] ?? $session['cwd'] ?? null;

        if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
            return ['ok' => false, 'message' => "Session '{$sessionId}' has no usable working directory; pass --workdir"];
        }

        return $directory;
    }

    private function client(): OpenCodeServeClient
    {
        return $this->client ??= new OpenCodeServeClient();
    }

    /** @param array<string, mixed> $session */
    private function track(string $id, string $workdir, array $session): void
    {
        $title = is_string($session['title'] ?? null) && $session['title'] !== '' ? $session['title'] : null;

        SidecarStore::write_sidecar($id, [
            'workdir' => $workdir,
            'spawned_at' => time(),
            'agent_session_id' => $id,
            'spawned_by_app' => true,
            'agent' => self::AGENT_ID,
            'runtime' => RuntimeType::HEADLESS,
            'title' => $title,
        ]);
    }

    /** The session name of a tracked OpenCode session, from a name or a conversation id. */
    private function tracked(string $target): ?string
    {
        $name = SidecarStore::read_sidecar($target) !== null ? $target : SidecarStore::find_by_agent_session_id($target);
        $sidecar = $name !== null ? SidecarStore::read_sidecar($name) : null;

        return $name !== null && ($sidecar['agent'] ?? null) === self::AGENT_ID ? $name : null;
    }

    /**
     * A session is blocked when a question/permission is pending, working when
     * the serve's active map lists it, idle otherwise. The blocked check comes
     * from get_status(), which is also the live question fallback.
     */
    private function status_of(string $id): ?string
    {
        $state = $this->client()->get_status($id);

        if (($state['ok']) !== true) {
            return null;
        }

        if (($state['status'] ?? null) === 'blocked') {
            return 'blocked';
        }

        $live = $this->client()->status_map();

        if (($live['ok']) === true) {
            return (string)($live['statuses'][$id] ?? 'idle');
        }

        return (string)($state['status'] ?? 'idle');
    }

    /** @return array<string, mixed> */
    private function deliver(string $name, string $action, string $prompt): array
    {
        $sent = $this->client()->send_message($name, $prompt);

        if (($sent['ok']) !== true) {
            return ['ok' => false, 'name' => $name, 'action' => $action, 'prompt_sent' => false, 'message' => "Session {$name} {$action}, but the prompt was not delivered: " . (string)($sent['message'] ?? 'unknown error')];
        }

        return ['ok' => true, 'name' => $name, 'action' => $action, 'prompt_sent' => true, 'message' => "Session {$name} {$action}; prompt delivered"];
    }
}
