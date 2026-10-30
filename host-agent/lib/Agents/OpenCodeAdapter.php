<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Services\Config;
use HostAgent\Runtimes\OpenCodeServeClient;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\GlobalStateStore;

/**
 * Third AgentAdapter implementation, for the OpenCode TUI CLI
 * (binary `opencode`). The TUI takes the project path as its positional arg
 * (`opencode [project]`). `--session <id>` continues that session, creating it
 * when it does not exist; `--model` and `--agent` belong to `opencode run`,
 * not the TUI. Blocked prompts come from the directory-scoped serve API and
 * the global plugin's `ctx.permission.hook("evaluate")` /
 * `ctx.event.subscribe(...)` - see OpenCodeServeClient and
 * opencode-plugins/sessioneer-permissions.js.
 */
class OpenCodeAdapter implements AgentAdapter
{
    public function id(): string
    {
        return 'opencode';
    }

    public function label(): string
    {
        return 'OpenCode';
    }

    public function session_name_prefix(): string
    {
        return 'oc';
    }

    /**
     * $options['workdir'] (string, the New Session browser's chosen
     * directory) is the positional `opencode [project]` arg. $options['model']
     * and $options['agent'] are passed as `--model provider/model` and
     * `--agent <name>`; the v2 TUI rejects both (they are `opencode run`
     * flags), so a TUI spawned with either shows its usage text instead of a
     * prompt. Other keys (enable_task_tools, starting_mode, effort) are
     * silently ignored - OpenCode has no --permission-mode / --effort
     * equivalent, only --auto for auto-approve (not wired here).
     *
     * assigned_id is always null - the TUI has no flag that reports the new
     * session's id. Real identity (`ses_*` from opencode.db) is learned
     * reactively after the first prompt creates a row.
     */
    public function build_spawn_argv(array $options): array
    {
        $argv = [Config::opencode_bin()];

        $workdir = $options['workdir'] ?? $options['directory'] ?? null;

        if (is_string($workdir) && $workdir !== '') {
            $argv[] = $workdir;
        }

        $model = $options['model'] ?? null;

        if (is_string($model) && $model !== '') {
            $argv[] = '--model';
            $argv[] = $model;
        }

        $agent = $options['agent'] ?? null;

        if (is_string($agent) && $agent !== '') {
            $argv[] = '--agent';
            $argv[] = $agent;
        }

        return ['argv' => $argv, 'assigned_id' => null];
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function check_hooks(): array
    {
        return ['ok' => true, 'installed' => false, 'message' => 'OpenCode plugin not yet installed (Phase 5 will add sessioneer-status plugin)'];
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public function install_hooks(): array
    {
        return ['ok' => true, 'installed' => false, 'message' => 'OpenCode plugin not yet installed (Phase 5 will add sessioneer-status plugin)'];
    }

    /**
     * OpenCode has no --mode / --permission-mode vocabulary like Claude
     * Code's acceptEdits/plan/auto or Antigravity's accept-edits/plan.
     * Its closest equivalent is --auto (auto-approve permissions, deny is
     * the default) - a boolean, not a mode enum. No map to provide here
     * until a real mode-like field is observed in a plugin hook payload.
     *
     * @return array<string, string>
     */
    public function permission_mode_map(): array
    {
        return [];
    }

    /**
     * OpenCode is the one agent with a real headless session runtime
     * (`opencode serve`) as well as the tmux TUI, so both are offered -
     * headless first (the headless-runtime plan's intended default for the
     * reliable permission/question path), tmux as the existing fallback.
     */
    public function supported_runtimes(): array
    {
        return [RuntimeType::HEADLESS, RuntimeType::TMUX];
    }

    public function model_catalog(): array
    {
        $models = (new OpenCodeServeClient())->available_models();

        if ($models !== []) {
            GlobalStateStore::write('opencode_models', ['models' => $models, 'updated_at' => time()]);
        }

        return ['ok' => true, 'models' => array_values($models)];
    }
}
