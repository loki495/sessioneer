<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

/**
 * One implementation per runtime (see RuntimeType). Deliberately narrow
 * and Sessioneer-shaped: it expresses "the things a session can do", WITHOUT
 * leaking the runtime's own mechanics into callers.
 *
 * This mirrors the AgentAdapter philosophy inverted - AgentAdapter holds
 * what differs per CLI; RuntimeProvider holds what differs per hosting
 * runtime:
 *
 *  - lifecycle (create/list/detail/kill) - tmux: tmux + sidecar; headless:
 *    the agent's own server API (opencode serve for opencode).
 *  - status (working/idle/blocked) - tmux: hooks + capture-pane; headless:
 *    server status + event stream.
 *  - drive (send_message, answer_prompt) - tmux: keystroke typing; headless:
 *    HTTP POST.
 *  - read (transcript/todo/plan/quota) is deliberately NOT here: it is
 *    already agent-agnostic and routed separately (TranscriptRouter sends
 *    a ses_* id to OpenCodeTranscriptService and a UUID to
 *    TranscriptService regardless of how the session was spawned), so a
 *    runtime re-exposing it would just duplicate that seam.
 *
 * A "session reference" ($sessionRef) is intentionally opaque: the tmux
 * runtime's ref is the tmux session NAME, the headless runtime's ref is the
 * ses_* id. Callers never need to know which - they take a ref from a
 * runtime's own list()/detail()/status() output and give it back, so the
 * contract never forces a tmux-name-vs-ses-id decision upstream.
 *
 * Failure contract: methods return array-shaped results with an `ok` bool
 * (["ok"=>false,"message"=>...]) for expected/handled failures - they do
 * NOT throw for a missing session, a cancelled request, or "not wired yet"
 * (compare the rest of host-agent, which uses the same handle-don't-crash
 * style).
 */
interface RuntimeProvider
{
    /**
     * The runtime slug - one of RuntimeType::all().
     */
    public function id(): string;

    /**
     * Shorthand for id() === RuntimeType::HEADLESS - the explicit
     * "is this a tmux-free headless session" classifier, so callers never
     * re-derive it from a magic session-id shape.
     */
    public function isHeadless(): bool;

    /**
     * Shorthand for id() === RuntimeType::TMUX.
     */
    public function isTmux(): bool;

    /**
     * Spawns a new session under this runtime.
     *
     * @param array<string, mixed> $options runtime-agnostic spawn options:
     *   $options['workdir'] (string) is always required; other keys are
     *   passed through to the agent (e.g. enable_task_tools, starting_mode)
     *   the same way AgentAdapter::build_spawn_argv() consumes them.
     * @return array{ok:bool, session?:array<string,mixed>, name?:string, id?:string, message?:string}
     */
    public function create(array $options): array;

    /**
     * All sessions hosted by this runtime for this agent.
     *
     * @return array{ok:bool, sessions?:array<int, array<string,mixed>>, message?:string}
     */
    public function list(): array;

    /**
     * One session's full detail (metadata + status + prompt state).
     *
     * @return array{ok:bool, session?:array<string,mixed>, message?:string}
     */
    public function detail(string $sessionRef): array;

    /**
     * Terminates a session under this runtime.
     *
     * @return array{ok:bool, message?:string}
     */
    public function kill(string $sessionRef): array;

    /**
     * Live status: the working/idle/blocked signal, plus the blocked
     * prompt when one is pending. `status` is one of 'working'|'idle'|
     * 'blocked'.
     *
     * @return array{ok:bool, status:string, blocked?:?array<string,mixed>, message?:string}
     */
    public function status(string $sessionRef): array;

    /**
     * Sends a free-text message to the session, as if typed.
     *
     * @param array<int, string> $attachmentPaths
     * @return array{ok:bool, message?:string}
     */
    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array;

    /**
     * The session's currently pending prompt, normalized to Sessioneer's OWN
     * canonical prompt shape that the dashboard renders (tool_name:
     * 'permission'|'question'|..., question, context, options:
     * [{number,label}], multi_question) - see PromptInteractionService/
     * PromptParser. null when nothing is pending (not blocked, or blocked
     * on a shape the runtime cannot yet read).
     *
     * @return array{tool_name:string, question?:string, context?:string, options:array<int, array{number:int, label:string}>, multi_question:bool, is_folder_trust:bool, request_id?:?string, tool_input?:?array<string,mixed>}|null
     */
    public function pending_prompt(string $sessionRef): ?array;

    /**
     * Answers the current pending prompt. $answers is Sessioneer-shaped, matching
     * what pending_prompt() reported and how PromptInteractionService names
     * its own already-agreed shapes:
     *  - a permission prompt: ['option' => int, 'text' => ?string]
     *  - a multi-question: ['answers' => array<int, array<int,string>|string>]
     *
     * The runtime translates that into its own mechanism (tmux keystroke
     * sequence; headless serve HTTP /permission/:requestID/reply or
     * /question/:requestID/reply). Callers should re-check pending_prompt()
     * first and match the shape against its tool_name/multi_question flag.
     *
     * @param array<string, mixed> $answers
     * @return array{ok:bool, message?:string}
     */
    public function answer_prompt(string $sessionRef, array $answers): array;

    /**
     * Stops the session's current turn (tmux: Escape; headless: the agent's
     * own interrupt). The session stays usable afterwards.
     *
     * @return array{ok:bool, message?:string}
     */
    public function interrupt(string $sessionRef): array;

    /**
     * Applies model settings to the session's NEXT turns. Every argument is
     * optional because agents differ in what they accept: $provider only
     * matters to OpenCode, $effort only to Codex; a runtime ignores what it
     * has no use for, and answers with a handled failure when nothing it
     * understands was supplied.
     *
     * @return array{ok:bool, message?:string}
     */
    public function update_settings(string $sessionRef, ?string $model = null, ?string $effort = null, ?string $provider = null): array;

    /**
     * Changes the session's permission mode, in Sessioneer's own vocabulary
     * (manual / accept edits / plan / auto). A runtime with no live mode
     * switch answers with a handled failure, never a throw.
     *
     * @return array{ok:bool, message?:string}
     */
    public function set_mode(string $sessionRef, string $mode): array;
}
