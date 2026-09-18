<?php

declare(strict_types=1);

namespace HostAgent\Agents;

/**
 * One implementation per supported coding CLI agent (Claude Code first,
 * see ClaudeCodeAdapter; Antigravity next, see
 * docs/antigravity-adapter-plan.md for the research this interface is
 * shaped around). Deliberately narrow - covers only what genuinely differs
 * per agent (spawn argv, hook registration, permission-mode vocabulary),
 * not things like tmux plumbing or SQLite storage that are already
 * agent-agnostic (see SidecarStore/SessionStatusStore/PendingToolStore).
 *
 * Hook PAYLOAD parsing (the actual host-agent/hooks/*.php scripts) is
 * deliberately NOT part of this interface - each agent's hook JSON shape
 * differs enough (Claude Code: flat tool_name/tool_input; Antigravity:
 * nested toolCall.name/args, decision-gated PreToolUse, no SessionStart
 * equivalent) that forcing them through one shared dispatch method would
 * be a leaky abstraction. Each agent gets its own small, explicit hook
 * scripts instead, matching this codebase's existing preference for
 * several small single-purpose files over a shared indirection layer.
 */
interface AgentAdapter
{
    /**
     * Stable machine identifier - 'claude', 'antigravity', etc. Stored in
     * the sidecars table's `agent` column (see docs/antigravity-adapter-plan.md
     * Phase 0) so a session's own row says which adapter governs it.
     */
    public function id(): string;

    /**
     * Human-readable name for the New Session UI's agent picker and
     * anywhere else this needs to be shown to Andres.
     */
    public function label(): string;

    /**
     * The tmux session-name prefix this agent's own spawned sessions get
     * (e.g. 'cc' -> cc-20260824-131822). Sessioneer's own naming convention, not
     * the agent's - session TRACKING is sidecar-existence-based, not a
     * prefix glob (see SessionService::list_all_sessions()'s own comment),
     * so this only affects the generated name, never what gets listed.
     */
    public function session_name_prefix(): string;

    /**
     * Builds this agent's own binary + CLI flags for a brand-new
     * interactive session (the tmux `new-session` wrapper itself - pane
     * size, cwd, SESSIONEER_SESSION_NAME - stays in SessionLifecycleService,
     * agent-agnostic). `assigned_id` is this agent's own conversation/
     * session identifier if it can be chosen up front (Claude Code's
     * --session-id), or null if the agent has no such mechanism and the
     * real id can only be learned reactively, off whatever hook fires
     * first after spawn (Antigravity - see docs/antigravity-adapter-plan.md's
     * "CLI flags" section for why).
     *
     * @param array<string, mixed> $options adapter-specific spawn options
     *   (e.g. Claude Code's enable_task_tools/starting_mode/profile) - each
     *   adapter documents and reads only the keys it understands, ignoring
     *   the rest, rather than a rigid shared shape every agent must fit.
     * @return array{argv: string[], assigned_id: ?string, env?: array<string, string>}
     *   env: extra env vars SessionLifecycleService should set on the
     *   spawned tmux pane (via `-e`, same mechanism as its own
     *   SESSIONEER_SESSION_NAME) - e.g. ClaudeCodeAdapter's CLAUDE_CONFIG_DIR
     *   when $options['profile'] names a non-default account (see Dibs
     *   plan #230). Omitted entirely by an adapter with nothing to add.
     */
    public function build_spawn_argv(array $options): array;

    /**
     * Whether this agent's hooks are already registered wherever this
     * agent looks for them (Claude Code: ~/.claude/settings.json;
     * Antigravity: ~/.gemini/config/hooks.json). Same {ok, installed,
     * message?} shape HookService::check_session_hook() already returns,
     * so callers (the dashboard's health box, agent.php's dispatch_action())
     * don't need to know which agent they're asking about.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function check_hooks(): array;

    /**
     * Registers every missing hook this agent needs, idempotently. Same
     * shape/semantics as check_hooks() above.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function install_hooks(): array;

    /**
     * This agent's own raw permission-mode enum values (as seen in its
     * hook payloads) mapped to Sessioneer's own manual/accept edits/plan/auto
     * vocabulary (TranscriptView::MODE_OPTIONS) - same shape as
     * PermissionMode::HOOK_PERMISSION_MODE_MAP, just per-agent. A raw
     * value with no entry here means "unrecognized", same as
     * PermissionMode::normalize_hook_permission_mode()'s existing null-if-
     * unknown behavior.
     *
     * @return array<string, string>
     */
    public function permission_mode_map(): array;

    /**
     * Which hostings this agent's sessions can live under (see RuntimeType).
     * The ordering is a preference - the first entry is the default when
     * nothing else says otherwise. An agent that has no headless session
     * mode at all just returns [RuntimeType::TMUX], and Sessioneer should not
     * pretend it can spawn it headless.
     *
     * Deliberately lives on the adapter (not the runtime) because whether a
     * headless mode is usable is a property of the CLI itself (opencode has
     * `opencode serve`; claude's Agent SDK is pay-per-API; antigravity has
     * only one-shot `agy -p`), i.e. exactly the per-agent knowledge
     * AgentAdapter already owns.
     *
     * @return array<int, string> a subset of RuntimeType::all()
     */
    public function supported_runtimes(): array;
}
