<?php

declare(strict_types=1);

namespace HostAgent\Agents;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\ClaudeModelCatalog;
use HostAgent\Services\Config;
use HostAgent\Services\HookService;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\SelectableModel;
use HostAgent\Services\SessionLifecycleService;

/**
 * The first (and, until Antigravity ships, only) AgentAdapter
 * implementation - a thin wrapper around this app's existing
 * Claude-Code-specific code (Config::claude_bin(), HookService,
 * PermissionMode), not a rewrite of any of it. Spawn argv/hook behavior is
 * identical to what SessionLifecycleService
 * once built inline.
 */
class ClaudeCodeAdapter implements AgentAdapter
{
    /** What `claude --effort` accepts; the CLI rejects a level a model does not support at spawn. */
    public const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function id(): string
    {
        return 'claude';
    }

    public function label(): string
    {
        return 'Claude Code';
    }

    public function session_name_prefix(): string
    {
        return 'cc';
    }

    /**
     * $options['enable_task_tools'] (bool) and $options['starting_mode']
     * (?string, this app's own manual/accept edits/plan/auto vocabulary)
     * mirror create_agent_session()'s own former inline parameters exactly -
     * see that method's docblock (unchanged) for why each exists.
     * $options['model'] (?string) is one of SelectableModel::PICKER_OPTIONS'
     * keys minus 'default' (sonnet/fable/opus/haiku) - the real CLI's
     * `--model` flag accepts these bare aliases directly (confirmed against
     * https://code.claude.com/docs/en/cli-reference), so this is passed
     * straight through rather than resolved to a full model id. 'default'/
     * empty/missing means "no --model flag at all", same "omit rather than
     * pass a sentinel" shape starting_mode already uses below.
     *
     * $options['profile'] (?string) names an entry under agents.php's
     * 'claude.profiles' (see Config::claude_profile_config()) - e.g. a
     * separate work account. null/empty/unrecognized all mean "this
     * process's own default account", same fallback Config's own profile
     * helpers already use, so every caller from before profiles existed
     * keeps spawning the same sessions. When set, the CLI binary
     * comes from that profile's own 'bin' override if any (Config::
     * claude_bin($profile)), and the returned env always names CLAUDE_CONFIG_DIR
     * (account_env()) so the spawned `claude` process (and Claude Code's own
     * transcript/settings storage under it) runs under that account.
     */
    public function build_spawn_argv(array $options): array
    {
        $profile = $options['profile'] ?? null;
        $profile = is_string($profile) && $profile !== '' ? $profile : null;

        $sessionId = SessionLifecycleService::generate_uuid_v4();
        $argv = [Config::claude_bin($profile), '--session-id', $sessionId, ...$this->optional_flags($options)];

        $result = ['argv' => $argv, 'assigned_id' => $sessionId];

        $result['env'] = self::account_env($profile);

        return $result;
    }

    /**
     * The child env that selects the account. A profile with its own
     * 'config_dir' sets it explicitly; the default account (null, personal,
     * an unknown name) maps to null = REMOVE the variable, because the
     * CLI reads ~/.claude.json only when it is unset - an explicit
     * ~/.claude is a different, empty config - and the spawning process
     * (manager, tmux server) may itself carry another account's value.
     * Deliberately not Config::claude_config_dir(), which is the transcript
     * lookup path (~/.claude for the default).
     *
     * @return array<string, ?string>
     */
    public static function account_env(?string $profile): array
    {
        return ['CLAUDE_CONFIG_DIR' => Config::claude_profile_config($profile)['config_dir']];
    }

    /**
     * The headless (stream-json over stdio) counterpart of
     * build_spawn_argv(): the same profile/model/starting_mode/task-tool
     * vocabulary, but a `claude -p` process that speaks NDJSON on
     * stdin/stdout and routes permission prompts to its client
     * (--permission-prompt-tool stdio). Never --bare: bare mode ignores the
     * subscription login.
     *
     * $options['resume'] (?string) is an existing Claude session id to
     * continue (`--resume <id>`); absent/empty starts a fresh session with a
     * pre-assigned id, exactly like the tmux builder. $options['starting_mode']
     * is re-applied on every resume because a `-p` resume does NOT restore
     * the previous permission mode.
     *
     * @param array<string, mixed> $options
     * @return array{argv: string[], assigned_id: ?string, env?: array<string, ?string>}
     */
    public function build_headless_argv(array $options): array
    {
        $profile = $options['profile'] ?? null;
        $profile = is_string($profile) && $profile !== '' ? $profile : null;
        $resume = $options['resume'] ?? null;
        $resume = is_string($resume) && $resume !== '' ? $resume : null;

        $argv = [
            Config::claude_bin($profile),
            '-p',
            '--input-format', 'stream-json',
            '--output-format', 'stream-json',
            '--verbose',
            // Without it the stream carries no UserPromptSubmit hook_response, so a prompt
            // refused by a hook looks delivered (ClaudeHeadlessManager::on_hook_response()).
            '--include-hook-events',
            '--permission-prompt-tool', 'stdio',
        ];

        if ($resume !== null) {
            array_push($argv, '--resume', $resume);
            $assignedId = $resume;
        } else {
            // A caller that already knows the id (a tracked session whose
            // conversation has no transcript yet) pins it; otherwise mint one.
            $pinned = $options['session_id'] ?? null;
            $assignedId = is_string($pinned) && $pinned !== '' ? $pinned : SessionLifecycleService::generate_uuid_v4();
            array_push($argv, '--session-id', $assignedId);
        }

        array_push($argv, ...$this->optional_flags($options));

        $result = ['argv' => $argv, 'assigned_id' => $assignedId];

        $result['env'] = self::account_env($profile);

        return $result;
    }

    public const TOOL_RULES_MAX_LENGTH = 2000;

    /**
     * Why a --allowed-tools/--disallowed-tools list is refused, or null when it is
     * safe: a conservative character set (no quotes, backslash, backtick, $, ;, |, &,
     * newline or angle brackets), no leading dash, a length cap.
     */
    public static function tool_rules_error(string $rules): ?string
    {
        if (trim($rules) === '') {
            return 'the tool list is empty';
        }

        if (strlen($rules) > self::TOOL_RULES_MAX_LENGTH) {
            return 'the tool list is longer than ' . self::TOOL_RULES_MAX_LENGTH . ' characters';
        }

        if (preg_match('/^[A-Za-z0-9 ,()*._\/:@~=+#%!?\[\]][A-Za-z0-9 ,()*._\/:@~=+#%!?\[\]-]*$/D', $rules) !== 1) {
            return 'the tool list may only contain letters, digits, spaces and , ( ) * . _ / : @ ~ = + # % ! ? [ ] -';
        }

        return null;
    }

    private static function valid_tool_rules(mixed $rules): ?string
    {
        return is_string($rules) && self::tool_rules_error($rules) === null ? $rules : null;
    }

    /**
     * Flags shared by the tmux and headless spawn shapes, in the order the
     * tmux builder has always emitted them.
     *
     * @param array<string, mixed> $options
     * @return string[]
     */
    private function optional_flags(array $options): array
    {
        $flags = [];

        // `--tools ""` drops every built-in tool; --strict-mcp-config with no --mcp-config
        // drops the MCP ones the user's settings would otherwise add back.
        if (!empty($options['no_tools'])) {
            array_push($flags, '--tools', '', '--strict-mcp-config');
        } else {
            // One argv element per flag (the CLI flag is variadic, so a single comma-joined
            // value keeps it from swallowing what follows); task tools join the same list.
            $allowed = array_filter([
                !empty($options['enable_task_tools']) ? 'TaskCreate,TaskGet,TaskList,TaskUpdate' : null,
                self::valid_tool_rules($options['allowed_tools'] ?? null),
            ]);

            if ($allowed !== []) {
                array_push($flags, '--allowedTools', implode(',', $allowed));
            }

            $disallowed = self::valid_tool_rules($options['disallowed_tools'] ?? null);

            if ($disallowed !== null) {
                array_push($flags, '--disallowedTools', $disallowed);
            }
        }

        $model = $options['model'] ?? null;

        if (is_string($model) && $model !== '' && $model !== 'default' && array_key_exists($model, SelectableModel::PICKER_OPTIONS)) {
            array_push($flags, '--model', $model);
        }

        $effort = $options['effort'] ?? null;

        if (is_string($effort) && in_array($effort, self::EFFORT_LEVELS, true)) {
            array_push($flags, '--effort', $effort);
        }

        $startingMode = $options['starting_mode'] ?? null;
        $realStartingMode = is_string($startingMode)
            ? (array_flip(PermissionMode::HOOK_PERMISSION_MODE_MAP)[$startingMode] ?? null)
            : null;

        if ($realStartingMode !== null) {
            array_push($flags, '--permission-mode', $realStartingMode);
        }

        return $flags;
    }

    public function check_hooks(): array
    {
        return HookService::check_session_hook();
    }

    public function install_hooks(): array
    {
        return HookService::install_session_hook();
    }

    public function permission_mode_map(): array
    {
        return PermissionMode::HOOK_PERMISSION_MODE_MAP;
    }

    /**
     * Claude Code runs headless (the default, listed first): the real `claude`
     * binary as a `claude -p` stream-json child of ClaudeHeadlessManager,
     * authenticated by the user's own logged-in subscription and never an API
     * key. That is a different thing from the Agent SDK library, which
     * authenticates with an API key and bills pay-as-you-go; the manager
     * enforces the difference (see ClaudeHeadlessManager's credential
     * guardrails). A tmux pane remains a supported runtime, chosen per session.
     */
    public function supported_runtimes(): array
    {
        return [RuntimeType::HEADLESS, RuntimeType::TMUX];
    }

    public function model_catalog(): array
    {
        $models = [];

        foreach (ClaudeModelCatalog::labels() as $key => $label) {
            if ($key !== 'default') {
                $models[] = ['id' => $key, 'name' => $label];
            }
        }

        return ['ok' => true, 'models' => $models];
    }
}
