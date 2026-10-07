<?php
declare(strict_types=1);

/**
 * Exercises HostAgent\Agents\AgentRegistry/ClaudeCodeAdapter/
 * AntigravityAdapter - the seam introduced 2026-08-24
 * (docs/history/antigravity-adapter-plan.md Phase 1) and its second real
 * implementation (Phase 2). ClaudeCodeAdapter::build_spawn_argv() must
 * produce BYTE-IDENTICAL argv to what SessionLifecycleService::
 * create_agent_session() built inline before this extraction - this file is
 * what proves that, not a rewrite of HookService's own coverage (see
 * test_session_hook.php for that; check_hooks()/install_hooks() here only
 * confirm delegation, not every edge case HookService itself already
 * covers). AntigravityAdapter's own hooks are deliberately unimplemented
 * stubs so far (Phase 3) - covered here as "honestly reports not
 * installed", not "works".
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Agents\AntigravityAdapter;
use HostAgent\Agents\ClaudeCodeAdapter;
use HostAgent\Agents\OpenCodeAdapter;
use HostAgent\Agents\CodexAdapter;
use HostAgent\Services\AntigravityHookService;
use HostAgent\Services\Config;
use HostAgent\Services\CodexHookService;
use HostAgent\Services\HookService;
use HostAgent\Services\PushHealthService;
use HostAgent\Services\PermissionMode;

$realHomeRoot = Config::home_root();

$fixtureHome = sys_get_temp_dir() . '/sessioneer-test-agent-adapter-home-' . bin2hex(random_bytes(4));
putenv("HOME_ROOT={$fixtureHome}");

if (Config::home_root() === $realHomeRoot) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

$settingsPath = Config::claude_settings_path();

try {
    // --- AgentRegistry: identity/lookup ---

    assert_equal('claude', AgentRegistry::default_agent_id(), 'default_agent_id: claude, unchanged from every existing caller before this class existed');
    assert_equal(['claude', 'antigravity', 'opencode', 'codex'], AgentRegistry::known_agent_ids(), 'known_agent_ids: all four agents registered');

    $adapter = AgentRegistry::get('claude');
    assert_true($adapter instanceof ClaudeCodeAdapter, 'AgentRegistry::get(\'claude\'): returns a ClaudeCodeAdapter instance');
    assert_true(AgentRegistry::get('claude') === $adapter, 'AgentRegistry::get(): returns the same cached instance on a second call');

    $threw = false;
    try {
        AgentRegistry::get('not-a-real-agent');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'AgentRegistry::get(): throws InvalidArgumentException for an unknown agent id');

    // --- ClaudeCodeAdapter: static identity ---

    assert_equal('claude', $adapter->id(), 'id(): claude');
    assert_equal('Claude Code', $adapter->label(), 'label(): Claude Code');
    assert_equal('cc', $adapter->session_name_prefix(), 'session_name_prefix(): cc, matching the tmux session names this app has always generated');
    assert_equal(PermissionMode::HOOK_PERMISSION_MODE_MAP, $adapter->permission_mode_map(), 'permission_mode_map(): the exact same map PermissionMode already exposes, not a second copy of it');

    // --- build_spawn_argv(): the actual extraction from create_agent_session() ---

    $bare = $adapter->build_spawn_argv([]);
    assert_equal([Config::claude_bin(), '--session-id', $bare['assigned_id']], $bare['argv'], 'build_spawn_argv([]): bare argv is just the binary + --session-id <assigned uuid>, no extra flags');
    assert_equal(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$bare['assigned_id']), 'build_spawn_argv([]): assigned_id is a real v4 UUID');

    $second = $adapter->build_spawn_argv([]);
    assert_true($bare['assigned_id'] !== $second['assigned_id'], 'build_spawn_argv(): a fresh call gets a fresh session id, never reused');

    $withTaskTools = $adapter->build_spawn_argv(['enable_task_tools' => true]);
    assert_true(in_array('--allowedTools', $withTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools): includes --allowedTools');
    assert_true(in_array('TaskCreate,TaskGet,TaskList,TaskUpdate', $withTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools): names the exact Task* tool family');

    $withoutTaskTools = $adapter->build_spawn_argv(['enable_task_tools' => false]);
    assert_true(!in_array('--allowedTools', $withoutTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools: false): omits --allowedTools entirely');

    $withMode = $adapter->build_spawn_argv(['starting_mode' => 'accept edits']);
    $modeIdx = array_search('--permission-mode', $withMode['argv'], true);
    assert_true($modeIdx !== false, 'build_spawn_argv(starting_mode): includes --permission-mode for a recognized mode');
    assert_equal('acceptEdits', $withMode['argv'][$modeIdx + 1] ?? null, 'build_spawn_argv(starting_mode): translates this app\'s "accept edits" to Claude Code\'s real "acceptEdits" enum value via the same HOOK_PERMISSION_MODE_MAP PermissionMode already owns');

    $withUnknownMode = $adapter->build_spawn_argv(['starting_mode' => 'not-a-real-mode']);
    assert_true(!in_array('--permission-mode', $withUnknownMode['argv'], true), 'build_spawn_argv(starting_mode): an unrecognized mode is silently ignored (whitelisted, never trusted straight through) rather than passed to Claude Code raw');

    $withNullMode = $adapter->build_spawn_argv(['starting_mode' => null]);
    assert_true(!in_array('--permission-mode', $withNullMode['argv'], true), 'build_spawn_argv(starting_mode: null): omits the flag entirely, same as never passing the option at all');

    // --- build_spawn_argv(model): the bug found live 2026-08-30 (Andres:
    // "requesting a model at session launch... doesn't work for Claude
    // sessions") - Sessions.php's 'create' case extracted $modelId off the
    // request but never actually passed it to create_agent_session()/this
    // adapter, so a model chosen on the New Session form was silently
    // dropped for Claude Code (unlike Antigravity/OpenCode above, which
    // both already wire 'model' through their own adapters - this was the
    // one adapter that never implemented the option at all). Confirmed
    // against https://code.claude.com/docs/en/cli-reference that --model
    // accepts these exact bare aliases (sonnet/fable/opus/haiku), same
    // vocabulary SelectableModel::PICKER_OPTIONS/set_model() already use
    // for the session-page picker. ---
    $withModel = $adapter->build_spawn_argv(['model' => 'sonnet']);
    $modelFlagIdx = array_search('--model', $withModel['argv'], true);
    assert_true($modelFlagIdx !== false, 'build_spawn_argv(model): includes --model for a recognized SelectableModel key');
    assert_equal('sonnet', $withModel['argv'][$modelFlagIdx + 1] ?? null, 'build_spawn_argv(model): passes the bare alias straight through - the real CLI accepts it directly, no resolution to a full model id needed');

    $withDefaultModel = $adapter->build_spawn_argv(['model' => 'default']);
    assert_true(!in_array('--model', $withDefaultModel['argv'], true), 'build_spawn_argv(model: "default"): omits --model entirely - "default" has no real model id of its own, same as PICKER_OPTIONS documents');

    $withEmptyModel = $adapter->build_spawn_argv(['model' => '']);
    assert_true(!in_array('--model', $withEmptyModel['argv'], true), 'build_spawn_argv(model: empty string): omits --model entirely');

    $withNullModel = $adapter->build_spawn_argv(['model' => null]);
    assert_true(!in_array('--model', $withNullModel['argv'], true), 'build_spawn_argv(model: null): omits --model entirely, same as never passing the option at all');

    $withUnknownModel = $adapter->build_spawn_argv(['model' => 'not-a-real-model']);
    assert_true(!in_array('--model', $withUnknownModel['argv'], true), 'build_spawn_argv(model: unrecognized): whitelisted against SelectableModel::PICKER_OPTIONS, same discipline as starting_mode above - never passed to the real CLI raw');

    // --- check_hooks()/install_hooks(): pure delegation to HookService ---

    assert_equal(HookService::check_session_hook(), $adapter->check_hooks(), 'check_hooks(): identical result to calling HookService::check_session_hook() directly');

    $adapterInstall = $adapter->install_hooks();
    assert_equal(true, $adapterInstall['ok'], 'install_hooks(): succeeds against a fresh fixture settings.json');
    assert_true(is_file($settingsPath), 'install_hooks(): actually wrote ~/.claude/settings.json, proving this reached the real HookService::install_session_hook(), not a stub');
    assert_equal(HookService::check_session_hook(), $adapter->check_hooks(), 'check_hooks(): still identical to HookService::check_session_hook() after installing');

    // --- AntigravityAdapter (docs/history/antigravity-adapter-plan.md Phase 2) ---

    $antigravity = AgentRegistry::get('antigravity');
    assert_true($antigravity instanceof AntigravityAdapter, "AgentRegistry::get('antigravity'): returns an AntigravityAdapter instance");
    assert_equal('antigravity', $antigravity->id(), 'AntigravityAdapter::id(): antigravity');
    assert_equal('Antigravity', $antigravity->label(), 'AntigravityAdapter::label(): Antigravity');
    assert_equal('ag', $antigravity->session_name_prefix(), 'AntigravityAdapter::session_name_prefix(): ag, distinct from Claude Code\'s cc');

    $agBare = $antigravity->build_spawn_argv([]);
    assert_equal([Config::antigravity_bin()], $agBare['argv'], 'AntigravityAdapter::build_spawn_argv([]): bare argv is just the binary, no flags');
    assert_equal(null, $agBare['assigned_id'], 'AntigravityAdapter::build_spawn_argv(): assigned_id is always null - no --session-id/--conversation-id equivalent exists for a fresh interactive session (confirmed live against `agy --help`)');

    $agWithModel = $antigravity->build_spawn_argv(['model' => 'gemini-3.7-flash-high']);
    $modelIdx = array_search('--model', $agWithModel['argv'], true);
    assert_true($modelIdx !== false, 'build_spawn_argv(model): includes --model when given');
    assert_equal('gemini-3.7-flash-high', $agWithModel['argv'][$modelIdx + 1] ?? null, 'build_spawn_argv(model): passes the model name straight through');

    $agWithBadEffort = $antigravity->build_spawn_argv(['effort' => 'ludicrous']);
    assert_true(!in_array('--effort', $agWithBadEffort['argv'], true), 'build_spawn_argv(effort): an unrecognized effort value is ignored, not passed through raw');

    $agWithEffort = $antigravity->build_spawn_argv(['effort' => 'high']);
    assert_true(in_array('--effort', $agWithEffort['argv'], true), 'build_spawn_argv(effort): a recognized low/medium/high value is included');

    $agWithMode = $antigravity->build_spawn_argv(['starting_mode' => 'accept edits']);
    $agModeIdx = array_search('--mode', $agWithMode['argv'], true);
    assert_true($agModeIdx !== false, 'build_spawn_argv(starting_mode): includes --mode for a recognized mode');
    assert_equal('accept-edits', $agWithMode['argv'][$agModeIdx + 1] ?? null, 'build_spawn_argv(starting_mode): translates this app\'s "accept edits" to Antigravity\'s real "accept-edits" CLI flag value');

    $agWithManual = $antigravity->build_spawn_argv(['starting_mode' => 'manual']);
    assert_true(!in_array('--mode', $agWithManual['argv'], true), 'build_spawn_argv(starting_mode: manual): omits --mode entirely - manual/default has no explicit flag, same as a plain `agy` with neither flag');

    $agWithAutoMode = $antigravity->build_spawn_argv(['starting_mode' => 'auto']);
    assert_true(!in_array('--mode', $agWithAutoMode['argv'], true), 'build_spawn_argv(starting_mode: auto): Claude Code\'s "auto" has no Antigravity equivalent, silently ignored rather than passed through raw');

    $agWithTaskTools = $antigravity->build_spawn_argv(['enable_task_tools' => true]);
    assert_true(!in_array('--allowedTools', $agWithTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools): a Claude-Code-only option is silently ignored, per the AgentAdapter interface\'s own "read only what you understand" contract');

    // Phase 3: check_hooks()/install_hooks() delegate to
    // AntigravityHookService, same "identical to calling the real service
    // directly" proof as ClaudeCodeAdapter's own tests above.
    assert_equal(AntigravityHookService::check_session_hook(), $antigravity->check_hooks(), 'AntigravityAdapter::check_hooks(): identical result to calling AntigravityHookService::check_session_hook() directly');

    // --- OpenCodeAdapter (Phase 1.1) ---

    $opencode = AgentRegistry::get('opencode');
    assert_true($opencode instanceof OpenCodeAdapter, "AgentRegistry::get('opencode'): returns an OpenCodeAdapter instance");
    assert_equal('opencode', $opencode->id(), 'OpenCodeAdapter::id(): opencode');
    assert_equal('OpenCode', $opencode->label(), 'OpenCodeAdapter::label(): OpenCode');
    assert_equal('oc', $opencode->session_name_prefix(), 'OpenCodeAdapter::session_name_prefix(): oc, distinct from cc/ag');

    $ocBare = $opencode->build_spawn_argv([]);
    assert_equal([Config::opencode_bin()], $ocBare['argv'], 'OpenCodeAdapter::build_spawn_argv([]): bare argv is just the binary, no positional workdir or flags');
    assert_equal(null, $ocBare['assigned_id'], 'OpenCodeAdapter::build_spawn_argv(): assigned_id is always null - no --session-id equivalent exists for a fresh TUI session (verified live: opencode --session <nonexistent> is resume-only)');

    $ocWithWorkdir = $opencode->build_spawn_argv(['workdir' => '/tmp/test-project']);
    assert_true(in_array('/tmp/test-project', $ocWithWorkdir['argv'], true), 'build_spawn_argv(workdir): includes positional workdir');
    assert_equal(Config::opencode_bin(), $ocWithWorkdir['argv'][0], 'build_spawn_argv(workdir): workdir follows the binary as the positional project arg');

    $ocWithModel = $opencode->build_spawn_argv(['model' => 'opencode/muse-spark-1.2-contributor-free']);
    $ocModelIdx = array_search('--model', $ocWithModel['argv'], true);
    assert_true($ocModelIdx !== false, 'build_spawn_argv(model): includes --model when given');
    assert_equal('opencode/muse-spark-1.2-contributor-free', $ocWithModel['argv'][$ocModelIdx + 1] ?? null, 'build_spawn_argv(model): passes the model name straight through');

    $ocWithEmptyModel = $opencode->build_spawn_argv(['model' => '']);
    assert_true(!in_array('--model', $ocWithEmptyModel['argv'], true), 'build_spawn_argv(model: empty string): omits --model entirely');

    $ocWithAgent = $opencode->build_spawn_argv(['agent' => 'build']);
    $ocAgentIdx = array_search('--agent', $ocWithAgent['argv'], true);
    assert_true($ocAgentIdx !== false, 'build_spawn_argv(agent): includes --agent when given');
    assert_equal('build', $ocWithAgent['argv'][$ocAgentIdx + 1] ?? null, 'build_spawn_argv(agent): passes the agent name straight through');

    $ocWithTaskTools = $opencode->build_spawn_argv(['enable_task_tools' => true]);
    assert_true(!in_array('--allowedTools', $ocWithTaskTools['argv'], true), 'build_spawn_argv(enable_task_tools): Claude-Code-only option is silently ignored, per "read only what you understand" contract');

    $ocWithStartingMode = $opencode->build_spawn_argv(['starting_mode' => 'accept edits']);
    assert_true(!in_array('--permission-mode', $ocWithStartingMode['argv'], true), 'build_spawn_argv(starting_mode): no --permission-mode for OpenCode - silently ignored, no mode vocabulary');
    assert_true(!in_array('--mode', $ocWithStartingMode['argv'], true), 'build_spawn_argv(starting_mode): no --mode either - not Antigravity');

    assert_equal([], $opencode->permission_mode_map(), 'permission_mode_map(): empty - OpenCode has no mode vocabulary (only --auto boolean)');

    $ocHooks = $opencode->check_hooks();
    assert_equal(false, $ocHooks['installed'], 'check_hooks(): honestly reports not installed (plugin not yet added, Phase 5)');
    assert_equal(true, $ocHooks['ok'], 'check_hooks(): ok=true even when not installed (honest stub, not a failure)');

    $ocInstall = $opencode->install_hooks();
    assert_equal(false, $ocInstall['installed'], 'install_hooks(): honestly reports not installed (stub, Phase 5 will implement)');

    $codex = AgentRegistry::get('codex');
    assert_true($codex instanceof CodexAdapter, "AgentRegistry::get('codex'): returns a CodexAdapter instance");
    assert_equal('Codex', $codex->label(), 'CodexAdapter::label(): Codex');
    assert_equal([], $codex->build_spawn_argv([])['argv'], 'CodexAdapter never builds a tmux/TUI spawn argv');
    $codexBridgeHealth = PushHealthService::codex_bridge_reachable();
    $codexHooks = $codex->check_hooks();
    assert_equal(false, $codexHooks['installed'], 'CodexAdapter honestly reports missing status hooks on a fresh fixture home');
    assert_equal($codexBridgeHealth['ok'] ?? null, $codexHooks['ok'] ?? null, 'CodexAdapter::check_hooks(): ok now reflects the real Codex bridge reachability check');
    $codexInstall = $codex->install_hooks();
    assert_equal(true, $codexInstall['installed'] ?? false, 'CodexAdapter::install_hooks() installs the status hooks');
    assert_equal(true, CodexHookService::check_session_hook()['installed'] ?? false, 'CodexAdapter::install_hooks() delegates to the real CodexHookService');
    assert_equal(true, $codex->check_hooks()['installed'] ?? false, 'CodexAdapter reports its status hooks installed afterward');

    $agInstall = $antigravity->install_hooks();
    assert_equal(true, $agInstall['ok'], 'AntigravityAdapter::install_hooks(): succeeds against a fresh fixture hooks.json');
    assert_true(is_file(Config::antigravity_hooks_path()), 'AntigravityAdapter::install_hooks(): actually wrote ~/.gemini/config/hooks.json, proving this reached the real AntigravityHookService, not a stub');
    assert_equal(AntigravityHookService::check_session_hook(), $antigravity->check_hooks(), 'AntigravityAdapter::check_hooks(): still identical to AntigravityHookService::check_session_hook() after installing');
} finally {
    @unlink($settingsPath);
    @rmdir(dirname($settingsPath));
    @unlink(Config::antigravity_hooks_path());
    @rmdir(dirname(Config::antigravity_hooks_path()));
    @rmdir(dirname(dirname(Config::antigravity_hooks_path())));
    @unlink(Config::codex_hooks_path());
    @rmdir(dirname(Config::codex_hooks_path()));
    @rmdir($fixtureHome);
}

test_exit();
