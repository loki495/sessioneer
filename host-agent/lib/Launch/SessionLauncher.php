<?php

declare(strict_types=1);

namespace HostAgent\Launch;

/**
 * What one agent must answer to be startable from outside the web UI (the
 * `launch` host-agent action, driven by `bin/sessioneer`). LaunchService
 * picks the implementation by agent id, so a new agent is one class plus
 * one line in LaunchService::LAUNCHERS; nothing else branches per agent.
 *
 * Request keys (a launcher reads only the ones it understands):
 *   agent             string   agent id; the launcher's own agent_id()
 *   prompt            string   first/next message to deliver; optional when resuming
 *   agent_session_id  string   existing conversation to resume or reuse; absent = new session
 *   workdir           string   absolute directory; required for a new session
 *   model             string   optional
 *   effort            string   optional reasoning level for a new or resumed session; refused
 *                              when the agent/model has no such level or (Claude) the
 *                              session is already live, since only a spawn sets it
 *   starting_mode     string   optional permission mode
 *   profile           string   optional account/profile
 *   caller_config_dir string   optional; the caller's CLAUDE_CONFIG_DIR, used to
 *                              pick the profile when `profile` is absent
 *   enable_task_tools bool     optional
 *   no_tools          bool     optional; a new session gets no tools at all
 *   allowed_tools     string   optional; Claude only, new session: comma separated tool rules to
 *                              pre-approve (--allowedTools); validated by
 *                              ClaudeCodeAdapter::tool_rules_error(); not with no_tools
 *   disallowed_tools  string   optional; Claude only, new session: tool rules always denied
 *                              (--disallowedTools); same validation. starting_mode may also be
 *                              `dontAsk` for Claude (deny whatever is not pre-approved)
 *
 * Reply: ['ok' => bool, 'message' => string] plus, once a session exists,
 * 'name' (the session ref the dashboard uses), 'action' (created|resumed|
 * reused) and 'prompt_sent' (bool). A session that was started but could
 * not take its prompt is ok=false WITH 'name', so the caller can find it.
 */
interface SessionLauncher
{
    public function agent_id(): string;

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function launch(array $request): array;

    /**
     * Stops a session. Request: `target` (session name or agent conversation
     * id) and optional `force` (bool); a working or blocked session is
     * refused without it. The conversation stays resumable (archived).
     * Reply: ['ok' => bool, 'message' => string, 'name' => string once found].
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function kill(array $request): array;

    /**
     * The sessions this launcher can manage. Reply: ['ok' => true,
     * 'sessions' => list of {name, agent_session_id, status, workdir, title,
     * model, effort}].
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function list_sessions(array $request): array;

    /**
     * Delivers `message` to the session `target` (name or conversation id) on
     * behalf of the session `from` (the caller's SESSIONEER_SELF). The host
     * agent prepends the sender stamp `[sessioneer peer: <name> · <workdir> ·
     * <branch>]` from its own records of `from`, so the message body cannot
     * set it. A `from` that is not a tracked session is refused.
     * Reply: like launch(), with action 'sent'.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function send(array $request): array;

    /**
     * Live sessions in the same git repository (worktrees included) as the
     * session `from` (or the `workdir`), excluding `from`. Reply: ['ok' =>
     * true, 'self' => ?string, 'peers' => list of {name, status, workdir,
     * branch, title}].
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function peers(array $request): array;
}
