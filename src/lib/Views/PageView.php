<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The full-page templates (index.php's dashboard, session.php's live detail
 * view, archived-session.php's read-only dormant-session view) - a thin
 * wrapper so each controller stays a plain data-gathering class with zero
 * HTML of their own, same as every other App\Views\* class, rather than a
 * one-off bypass of the View::render() convention.
 */
class PageView extends View
{
    /**
     * New Session form's agent picker - deliberately a plain view-layer
     * constant, not reached in from HostAgent\Agents\AgentRegistry (this
     * class renders inside the Docker container, which never touches
     * host-agent's own env/config - see CLAUDE.md's architecture section
     * on why - so this mirrors AgentRegistry::known_agent_ids()/label()
     * rather than calling them, same pattern TranscriptView::MODE_OPTIONS
     * already uses for Claude Code's own mode vocabulary). Keep in sync
     * with HostAgent\Agents\AgentRegistry's own ADAPTERS list by hand when
     * a new adapter is added.
     *
     * Which runtime each of these sessions runs in is the adapter's own
     * answer (AgentAdapter::supported_runtimes()); Ollama supports headless
     * only, so index.js hides the "Runs in", starting-mode and task-tool
     * controls for it rather than offering choices its adapter can't honour.
     */
    public const AGENT_OPTIONS = ['claude' => 'Claude Code', 'antigravity' => 'Antigravity', 'opencode' => 'OpenCode', 'codex' => 'Codex', 'ollama' => 'Ollama (local)'];

    /**
     * An agent's display name from AGENT_OPTIONS, for the places that only
     * have a raw agent id (session.php's label fallback when the host agent's
     * own agent_label is missing). Unknown ids keep the id itself instead of
     * borrowing another agent's name, which is what a hardcoded
     * claude/codex/antigravity chain did to every new adapter.
     */
    public static function agent_label(string $agentId): string
    {
        return self::AGENT_OPTIONS[$agentId] ?? ucfirst($agentId);
    }

    public static function render_session_page(array $data): string
    {
        return self::render('pages/session', $data);
    }

    public static function render_index_page(array $data): string
    {
        return self::render('pages/index', $data);
    }

    public static function render_archived_session_page(array $data): string
    {
        return self::render('pages/archived-session', $data);
    }
}
