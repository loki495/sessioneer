<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\AntigravityHookService;
use HostAgent\Services\AntigravitySelectableModel;
use HostAgent\Services\Config;

/**
 * Second AgentAdapter implementation, for Google's Antigravity CLI
 * (binary `agy`, as of `agy 1.1.19`). Phase 2: spawn argv + identity. Phase 3: hook registration -
 * ships working/idle tracking; "blocked" detection is a later phase (a hook's own
 * decision doesn't suppress Antigravity's real approval UI).
 */
class AntigravityAdapter implements AgentAdapter
{
    /**
     * This app's own manual/accept edits/plan vocabulary -> the real
     * `--mode` value `agy` accepts for a NEW interactive session
     * (confirmed via `agy --help`: only accept-edits/plan are real flag
     * values - there is no explicit flag for the interactive default,
     * that's just what a plain `agy` with neither flag drops into, and no
     * 4th "bypass/auto" mode was observed to exist at all). 'manual' is
     * intentionally absent from this map - omitting the --mode flag
     * entirely IS Antigravity's manual/default mode, same as Claude Code's
     * own null-starting-mode behavior.
     */
    private const STARTING_MODE_FLAGS = [
        'accept edits' => 'accept-edits',
        'plan' => 'plan',
    ];

    public function id(): string
    {
        return 'antigravity';
    }

    public function label(): string
    {
        return 'Antigravity';
    }

    public function session_name_prefix(): string
    {
        return 'ag';
    }

    /**
     * $options['starting_mode'] mirrors ClaudeCodeAdapter's own option of
     * the same name (this app's manual/accept edits/plan vocabulary) -
     * unrecognized/unsupported values (e.g. 'auto', which this agent has
     * no equivalent for) are silently ignored, same whitelisting
     * discipline as the Claude adapter. $options['model']/$options['effort']
     * pass straight through to `--model`/`--effort` when given (mirroring the
     * model-select dropdown, mirrored per-agent once the New Session
     * UI grows an agent picker).
     * $options['enable_task_tools'] (a Claude-Code-specific
     * concept - Antigravity has no equivalent) is silently ignored if
     * present, per this interface's own "read only what you understand"
     * contract.
     *
     * assigned_id is always null - no
     * --session-id/--conversation-id equivalent exists for starting a
     * fresh interactive session; `--conversation <id>` only RESUMES an
     * existing one. Real identity can only be learned reactively, off the
     * conversationId in whichever hook fires first after spawn - Phase 3.
     */
    public function build_spawn_argv(array $options): array
    {
        $argv = [Config::antigravity_bin()];

        $model = $options['model'] ?? null;

        if (is_string($model) && $model !== '') {
            $argv[] = '--model';
            $argv[] = $model;
        }

        $effort = $options['effort'] ?? null;

        if (is_string($effort) && in_array($effort, ['low', 'medium', 'high'], true)) {
            $argv[] = '--effort';
            $argv[] = $effort;
        }

        $startingMode = $options['starting_mode'] ?? null;
        $realMode = is_string($startingMode) ? (self::STARTING_MODE_FLAGS[$startingMode] ?? null) : null;

        if ($realMode !== null) {
            $argv[] = '--mode';
            $argv[] = $realMode;
        }

        return ['argv' => $argv, 'assigned_id' => null];
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function check_hooks(): array
    {
        return AntigravityHookService::check_session_hook();
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function install_hooks(): array
    {
        return AntigravityHookService::install_session_hook();
    }

    /**
     * Not yet backed by a real observed hook field - unlike Claude Code's
     * hooks (which explicitly send permission_mode on UserPromptSubmit/
     * PermissionRequest/Stop), none of the 4 Antigravity hook payloads
     * captured live during this research (PreToolUse, PreInvocation,
     * PostInvocation, Stop) carried any mode/permission field at all. This
     * map exists so the interface contract is satisfiable, but nothing
     * calls normalize_hook_permission_mode()-style logic against it yet
     * (an open item).
     *
     * @return array<string, string>
     */
    public function permission_mode_map(): array
    {
        return array_flip(self::STARTING_MODE_FLAGS);
    }

    /**
     * Antigravity has no headless session mode - `agy -p` is one-shot
     * (usage/print), not a drivable session. Stays tmux-only.
     */
    public function supported_runtimes(): array
    {
        return [RuntimeType::TMUX];
    }

    public function model_catalog(): array
    {
        $models = [];

        foreach (AntigravitySelectableModel::PICKER_OPTIONS as $key => $label) {
            $models[] = ['id' => $key, 'name' => $label];
        }

        return ['ok' => true, 'models' => $models];
    }
}
