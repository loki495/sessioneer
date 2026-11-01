<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Agents\ClaudeCodeAdapter;
use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\BareProcessService;
use HostAgent\Services\Config;
use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Services\TranscriptService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Claude Code launches always land in the headless runtime: a new
 * conversation, a resumed one, or - when Sessioneer already tracks a session
 * on that conversation id (headless, dormant or tmux) - that same session,
 * which just receives the prompt.
 */
class ClaudeSessionLauncher implements SessionLauncher
{
    use PeerLookup;

    private const AGENT_ID = 'claude';

    public function agent_id(): string
    {
        return self::AGENT_ID;
    }

    public function launch(array $request): array
    {
        $prompt = is_string($request['prompt'] ?? null) ? trim($request['prompt']) : '';
        $conversationId = is_string($request['agent_session_id'] ?? null) ? trim($request['agent_session_id']) : '';
        $profile = is_string($request['profile'] ?? null) && $request['profile'] !== '' ? $request['profile'] : null;

        if ($profile === null) {
            $resolved = $this->profile_for_caller($request);

            if (is_array($resolved)) {
                return $resolved;
            }

            $profile = $resolved;
        }

        $effort = is_string($request['effort'] ?? null) ? trim($request['effort']) : '';

        if ($effort !== '' && !in_array($effort, ClaudeCodeAdapter::EFFORT_LEVELS, true)) {
            return ['ok' => false, 'message' => "--effort '{$effort}' is not a Claude level (" . implode(', ', ClaudeCodeAdapter::EFFORT_LEVELS) . ')'];
        }

        foreach (['allowed_tools' => '--allowed-tools', 'disallowed_tools' => '--disallowed-tools'] as $key => $flag) {
            if (!isset($request[$key])) {
                continue;
            }

            $rules = $request[$key];
            $error = is_string($rules) ? ClaudeCodeAdapter::tool_rules_error($rules) : 'the tool list must be a string';

            if ($error === null && $conversationId !== '') {
                $error = 'it only applies to starting a new session';
            }

            if ($error === null && !empty($request['no_tools'])) {
                $error = 'it cannot be combined with --no-tools';
            }

            if ($error !== null) {
                return ['ok' => false, 'message' => "{$flag} refused: {$error}"];
            }
        }

        $runtime = RuntimeRegistry::runtime_for(self::AGENT_ID, RuntimeType::HEADLESS);

        if (!$runtime instanceof ClaudeHeadlessRuntime) {
            return ['ok' => false, 'message' => 'Headless runtime unavailable for claude'];
        }

        if ($conversationId === '') {
            if ($prompt === '') {
                return ['ok' => false, 'message' => 'A new session needs a prompt'];
            }

            $created = $runtime->create([
                'workdir' => $request['workdir'] ?? null,
                'model' => $request['model'] ?? null,
                'starting_mode' => $request['starting_mode'] ?? null,
                'enable_task_tools' => !empty($request['enable_task_tools']),
                'no_tools' => !empty($request['no_tools']),
                'allowed_tools' => $request['allowed_tools'] ?? null,
                'disallowed_tools' => $request['disallowed_tools'] ?? null,
                'profile' => $profile,
                'effort' => $effort,
            ]);

            return $created['ok'] === true ? $this->deliver((string)$created['name'], 'created', $prompt, $runtime) : $created;
        }

        $existing = $this->tracked_session($conversationId);

        if ($existing !== null) {
            if ($effort !== '') {
                return ['ok' => false, 'name' => $existing['name'], 'message' => "Session {$existing['name']} already exists; Claude's effort is fixed when its process starts, so --effort cannot change it"];
            }

            return $prompt === ''
                ? ['ok' => true, 'name' => $existing['name'], 'action' => 'reused', 'prompt_sent' => false, 'message' => "Session {$existing['name']} already tracks that conversation"]
                : $this->deliver($existing['name'], 'reused', $prompt, $runtime, $existing['runtime']);
        }

        if (in_array($conversationId, BareProcessService::live_bare_agent_session_ids(), true)) {
            return ['ok' => false, 'message' => 'That conversation is running in a terminal Sessioneer did not start; use Take over in the dashboard'];
        }

        $workdir = is_string($request['workdir'] ?? null) && $request['workdir'] !== '' ? $request['workdir'] : null;

        if ($workdir === null) {
            $transcript = TranscriptRouter::find_transcript_path($conversationId, $profile);
            $workdir = $transcript !== null ? TranscriptService::find_first_cwd($transcript) : null;
        }

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'No transcript found for that conversation; pass a workdir if it lives under another account'];
        }

        $resumed = $runtime->resume($workdir, $conversationId, $profile, $effort !== '' ? $effort : null);

        if ($resumed['ok'] !== true) {
            return $resumed;
        }

        return $prompt === ''
            ? ['ok' => true, 'name' => $resumed['name'], 'action' => 'resumed', 'prompt_sent' => false, 'message' => (string)($resumed['message'] ?? 'Resumed')]
            : $this->deliver((string)$resumed['name'], 'resumed', $prompt, $runtime);
    }

    public function kill(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';

        if ($target === '') {
            return ['ok' => false, 'message' => 'Kill needs a session name or conversation id'];
        }

        $runtime = RuntimeRegistry::runtime_for(self::AGENT_ID, RuntimeType::HEADLESS);

        if (!$runtime instanceof ClaudeHeadlessRuntime) {
            return ['ok' => false, 'message' => 'Headless runtime unavailable for claude'];
        }

        $tracked = $runtime->session_entry($target) !== null ? $target : SidecarStore::find_by_agent_session_id($target);
        $entry = $tracked !== null ? $runtime->session_entry($tracked) : null;

        if ($tracked === null || $entry === null) {
            return ['ok' => false, 'message' => "No headless Claude session found for '{$target}' (terminal sessions are stopped from the dashboard)"];
        }

        $status = (string)$entry['status'];

        if ($status !== 'idle' && empty($request['force'])) {
            return ['ok' => false, 'name' => $tracked, 'message' => "Session {$tracked} is {$status}; stopping it would drop that work (use --force)"];
        }

        $killed = $runtime->kill($tracked);

        if ($killed['ok'] === true) {
            // Same as the `kill` action: the session leaves the list at once.
            SidecarStore::delete_sidecar($tracked);
            SessionStatusStore::delete_status($tracked);
        }

        return $killed['ok'] === true
            ? ['ok' => true, 'name' => $tracked, 'message' => "Session {$tracked} stopped; its conversation can be resumed"]
            : ['ok' => false, 'name' => $tracked, 'message' => (string)($killed['message'] ?? 'Could not stop the session')];
    }

    public function list_sessions(array $request): array
    {
        $runtime = RuntimeRegistry::runtime_for(self::AGENT_ID, RuntimeType::HEADLESS);

        if (!$runtime instanceof ClaudeHeadlessRuntime) {
            return ['ok' => false, 'message' => 'Headless runtime unavailable for claude'];
        }

        $sessions = [];

        foreach ($runtime->list()['sessions'] ?? [] as $entry) {
            $sessions[] = [
                'name' => $entry['name'],
                'agent_session_id' => $entry['agent_session_id'],
                'status' => $entry['status'],
                'workdir' => $entry['workdir'],
                'title' => $entry['title'],
                'model' => $entry['current_model'],
                'effort' => SidecarStore::read_sidecar($entry['name'])['effort'] ?? null,
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

        $name = SidecarStore::read_sidecar($target) !== null ? $target : SidecarStore::find_by_agent_session_id($target);
        $receiver = $name !== null ? SidecarStore::read_sidecar($name) : null;

        if ($name === null || $receiver === null || ($receiver['agent'] ?? self::AGENT_ID) !== self::AGENT_ID) {
            return ['ok' => false, 'message' => "No tracked Claude session found for '{$target}'"];
        }

        if ($name === $from) {
            return ['ok' => false, 'message' => 'A session cannot send a message to itself'];
        }

        $runtime = RuntimeRegistry::runtime_for(self::AGENT_ID, RuntimeType::HEADLESS);

        if (!$runtime instanceof ClaudeHeadlessRuntime) {
            return ['ok' => false, 'message' => 'Headless runtime unavailable for claude'];
        }

        $stamp = $this->peer_stamp($from, $sender);

        return $this->deliver($name, 'sent', "{$stamp} {$message}", $runtime, (string)($receiver['runtime'] ?? RuntimeType::TMUX));
    }

    /**
     * With no explicit profile, a launch runs under the caller's own account
     * (its CLAUDE_CONFIG_DIR), so a session started from a work-account session
     * doesn't silently land on the default account. A dir that matches no
     * configured profile is refused rather than guessed.
     *
     * @param array<string, mixed> $request
     * @return string|null|array{ok: false, message: string} the profile, or the handled failure to return
     */
    private function profile_for_caller(array $request): string|null|array
    {
        $callerDir = is_string($request['caller_config_dir'] ?? null) ? rtrim(trim($request['caller_config_dir']), '/') : '';

        if ($callerDir === '') {
            return null;
        }

        $profile = Config::claude_profile_for_config_dir($callerDir);

        if ($profile === null && $callerDir !== Config::claude_config_dir(null)) {
            return ['ok' => false, 'message' => "The caller runs under CLAUDE_CONFIG_DIR {$callerDir}, which matches no profile in host-agent/config/agents.php; pass --profile"];
        }

        return $profile;
    }

    /** @return array{name:string, runtime:string}|null */
    private function tracked_session(string $conversationId): ?array
    {
        $name = SidecarStore::find_by_agent_session_id($conversationId);
        $sidecar = $name !== null ? SidecarStore::read_sidecar($name) : null;

        if ($name === null || ($sidecar['agent'] ?? self::AGENT_ID) !== self::AGENT_ID) {
            return null;
        }

        return ['name' => $name, 'runtime' => (string)($sidecar['runtime'] ?? RuntimeType::TMUX)];
    }

    /** @return array<string, mixed> */
    private function deliver(string $name, string $action, string $prompt, ClaudeHeadlessRuntime $runtime, string $runtimeType = RuntimeType::HEADLESS): array
    {
        $sent = $runtimeType === RuntimeType::HEADLESS
            ? $runtime->send_message($name, $prompt)
            : PromptInteractionService::send_message($name, $prompt, []);

        if ($sent['ok'] !== true) {
            return ['ok' => false, 'name' => $name, 'action' => $action, 'prompt_sent' => false, 'message' => "Session {$name} {$action}, but the prompt was not delivered: " . (string)($sent['message'] ?? 'unknown error')];
        }

        return ['ok' => true, 'name' => $name, 'action' => $action, 'prompt_sent' => true, 'message' => "Session {$name} {$action}; prompt delivered"];
    }
}
