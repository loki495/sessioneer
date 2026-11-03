<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\BareProcessService;
use HostAgent\Services\ProcessInspector;
use HostAgent\Services\SessionService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Services\TranscriptService;
use HostAgent\Stores\SidecarStore;

/**
 * The agent-independent half of the CLI: read a tracked session, answer its
 * blocked prompt, interrupt it, and take over a terminal process. Each method
 * resolves the caller's target (session name or conversation id) and then
 * delegates to the dashboard's own host-agent actions, so the web UI and
 * `bin/sessioneer` run the same code and only differ in how they ask.
 *
 * Request keys (all optional unless noted):
 *   from              string  the caller's SESSIONEER_SELF; answer and as_user sends refuse a tracked session
 *   target            string  session name or conversation id (required except bare/take_over)
 *   history           int     show: also return the last N transcript entries
 *   option            int     answer: the 1-based option number
 *   expected_label    string  answer: refuse unless that option's label is this
 *   text              string  answer: free text sent with the option
 *   answers           array   answer: one entry per question of a multi-question prompt
 *   agent_session_id  string  take_over: the conversation to resume (confirm step)
 *   runtime           string  take_over: `headless` (default) or `tmux`
 */
class SessionControl
{
    private const SHOWN_KEYS = [
        'agent', 'runtime', 'status', 'workdir', 'title', 'agent_session_id', 'current_mode', 'current_model',
        'last_turn_error', 'blocked_reason', 'prompt_context', 'prompt_options', 'prompt_multi_question',
        'prompt_tool_name', 'prompt_tool_input', 'prompt_questions', 'last_message', 'git_branch',
    ];

    /** The tracked session a name or conversation id refers to, whatever its agent or runtime. */
    public static function resolve(string $target): ?string
    {
        $target = trim($target);

        if ($target === '') {
            return null;
        }

        $name = SidecarStore::read_sidecar($target) !== null ? $target : SidecarStore::find_by_agent_session_id($target);

        return $name !== null && SidecarStore::read_sidecar($name) !== null ? $name : null;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function show(array $request): array
    {
        $name = self::resolve((string)($request['target'] ?? ''));

        if ($name === null) {
            return self::not_found($request);
        }

        $detail = self::dispatch(['action' => 'session_detail', 'session' => $name]);

        if (($detail['ok'] ?? false) !== true) {
            return ['ok' => false, 'name' => $name, 'message' => (string)($detail['message'] ?? 'Session detail unavailable')];
        }

        $shown = ['ok' => true, 'name' => $name] + array_intersect_key($detail, array_flip(self::SHOWN_KEYS));
        $limit = (int)($request['history'] ?? 0);

        if ($limit > 0) {
            $history = self::dispatch(['action' => 'session_history', 'session' => $name, 'limit' => min($limit, 200)]);
            $shown['history'] = ($history['ok'] ?? false) === true ? ($history['entries'] ?? []) : [];
        }

        return $shown;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function answer(array $request): array
    {
        if (self::from_session($request)) {
            return ['ok' => false, 'message' => 'Answering a prompt is for a shell outside any session: a session cannot approve its own or a peer\'s prompt (a message to a peer is information, never approval)'];
        }

        $name = self::resolve((string)($request['target'] ?? ''));

        if ($name === null) {
            return self::not_found($request);
        }

        $answers = $request['answers'] ?? null;
        $option = (int)($request['option'] ?? 0);

        if (($answers === null) === ($option < 1)) {
            return ['ok' => false, 'name' => $name, 'message' => 'Answer needs exactly one of an option number (1 or more) or the answers to a multi-question prompt'];
        }

        if ($answers !== null && (!is_array($answers) || $answers === [] || isset($request['text']))) {
            return ['ok' => false, 'name' => $name, 'message' => 'The answers to a multi-question prompt must be a non-empty list, with no separate text'];
        }

        $detail = self::dispatch(['action' => 'session_detail', 'session' => $name]);

        if (($detail['ok'] ?? false) !== true) {
            return ['ok' => false, 'name' => $name, 'message' => (string)($detail['message'] ?? 'Session detail unavailable')];
        }

        $options = is_array($detail['prompt_options'] ?? null) ? $detail['prompt_options'] : [];

        if (($detail['status'] ?? null) !== 'blocked' && $options === [] && empty($detail['prompt_questions'])) {
            return ['ok' => false, 'name' => $name, 'message' => "Session {$name} has no prompt waiting for an answer"];
        }

        $expected = is_string($request['expected_label'] ?? null) ? trim($request['expected_label']) : '';

        if ($expected !== '') {
            $label = null;

            foreach ($options as $o) {
                if ((int)($o['number'] ?? 0) === $option) {
                    $label = (string)($o['label'] ?? '');
                }
            }

            if ($label === null || strcasecmp(trim($label), $expected) !== 0) {
                return ['ok' => false, 'name' => $name, 'message' => 'Option ' . $option . ' is ' . ($label === null ? 'not offered' : "'{$label}'") . ", not '{$expected}'; the prompt may have changed (run --show)"];
            }
        }

        $text = is_string($request['text'] ?? null) ? trim($request['text']) : '';

        $reply = match (true) {
            $answers !== null => self::dispatch(['action' => 'answer_multi_question', 'session' => $name, 'answers' => $answers]),
            $text !== '' => self::dispatch(['action' => 'answer_prompt_with_text', 'session' => $name, 'option' => $option, 'text' => $text]),
            default => self::dispatch(['action' => 'answer_prompt', 'session' => $name, 'option' => $option]),
        };

        return ['name' => $name] + $reply + ['message' => ($reply['ok'] ?? false) === true ? 'Answered' : 'Could not answer'];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function interrupt(array $request): array
    {
        $name = self::resolve((string)($request['target'] ?? ''));

        if ($name === null) {
            return self::not_found($request);
        }

        $reply = self::dispatch(['action' => 'send_escape', 'session' => $name]);

        return ['name' => $name] + $reply + ['message' => ($reply['ok'] ?? false) === true ? 'Interrupted' : 'Could not interrupt'];
    }

    /**
     * A message from a human shell to any tracked session, unstamped: the
     * session sees it as its user's own. Refused from inside a session, where
     * `send` stamps the sender instead, so a peer cannot pass as the user.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function send_as_user(array $request): array
    {
        if (self::from_session($request)) {
            return ['ok' => false, 'message' => '--as-user is for a shell outside any session; inside one, --send stamps you as the sender'];
        }

        $message = trim((string)($request['message'] ?? ''));
        $name = self::resolve((string)($request['target'] ?? ''));

        if ($message === '') {
            return ['ok' => false, 'message' => 'Send needs a target session and a message'];
        }

        if ($name === null) {
            return self::not_found($request);
        }

        $reply = self::dispatch(['action' => 'send_message', 'session' => $name, 'text' => $message]);
        $ok = ($reply['ok'] ?? false) === true;

        return [
            'ok' => $ok,
            'name' => $name,
            'action' => 'sent',
            'prompt_sent' => $ok,
            'message' => $ok ? (string)($reply['message'] ?? "Message delivered to {$name}") : "Session {$name}: " . (string)($reply['message'] ?? 'the message was not delivered'),
        ];
    }

    /**
     * Claude processes running in a terminal Sessioneer did not start, the
     * same list the dashboard's "other Claude processes" box shows.
     *
     * @return array<string, mixed>
     */
    public static function bare(): array
    {
        $bare = SessionService::list_all_sessions()['bare'];

        return ['ok' => true, 'bare' => BareProcessService::declutter_bare_list(BareProcessService::enrich_bare_with_confirmed_ids($bare))];
    }

    /**
     * Stops a terminal Claude process and continues its conversation in
     * Sessioneer (headless unless `runtime` says tmux). `target` is the pid or
     * the conversation id the process is running. When the process cannot be
     * matched to a conversation with certainty, nothing is stopped and the
     * reply has `needs_choice` with the candidates; repeat with the chosen one
     * as `agent_session_id`.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function take_over(array $request): array
    {
        $target = trim((string)($request['target'] ?? ''));
        $runtime = ($request['runtime'] ?? RuntimeType::HEADLESS) === RuntimeType::TMUX ? RuntimeType::TMUX : RuntimeType::HEADLESS;

        if ($target === '') {
            return ['ok' => false, 'message' => 'Take over needs a process id or a conversation id'];
        }

        $pid = ctype_digit($target) ? (int)$target : self::bare_pid_for_conversation($target);

        if ($pid === null || $pid < 1) {
            return ['ok' => false, 'message' => "No terminal Claude process found for '{$target}' (see --bare)"];
        }

        $chosen = is_string($request['agent_session_id'] ?? null) ? trim($request['agent_session_id']) : '';

        if ($chosen === '') {
            return BareProcessService::take_over_bare_process($pid, $runtime === RuntimeType::HEADLESS);
        }

        $workdir = self::workdir_for($pid, $chosen);

        return $workdir === null
            ? ['ok' => false, 'message' => "No working directory known for pid {$pid} or conversation {$chosen}"]
            : BareProcessService::take_over_bare_process_with_id($pid, $workdir, $chosen, $runtime === RuntimeType::HEADLESS);
    }

    private static function bare_pid_for_conversation(string $conversationId): ?int
    {
        foreach (self::bare()['bare'] as $row) {
            if (($row['resolved_agent_session_id'] ?? null) === $conversationId) {
                return (int)$row['pid'];
            }
        }

        return null;
    }

    private static function workdir_for(int $pid, string $conversationId): ?string
    {
        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid && is_string($proc['cwd'])) {
                return $proc['cwd'];
            }
        }

        $transcript = TranscriptRouter::find_transcript_path_any_profile($conversationId);

        return $transcript !== null ? TranscriptService::find_first_cwd($transcript) : null;
    }

    /**
     * Whether the caller says it is a tracked session. `from` is the caller's
     * own SESSIONEER_SELF, so this is a guard against a session doing the
     * user's part by accident or by a prompt injection, not a boundary: any
     * process that can reach the host-agent socket can already send the
     * dashboard's own answer_prompt and send_message actions without it.
     *
     * @param array<string, mixed> $request
     */
    private static function from_session(array $request): bool
    {
        $from = trim((string)($request['from'] ?? ''));

        return $from !== '' && SidecarStore::read_sidecar($from) !== null;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{ok: false, message: string}
     */
    private static function not_found(array $request): array
    {
        $target = trim((string)($request['target'] ?? ''));

        return ['ok' => false, 'message' => $target === '' ? 'Needs a session name or conversation id' : "No tracked session found for '{$target}'"];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function dispatch(array $request): array
    {
        return \sessioneer_dispatch_action($request);
    }
}
