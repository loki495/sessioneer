<?php
declare(strict_types=1);

/**
 * Exercises the Phase 1 runtime contract: RuntimeRegistry resolving an
 * agent's supported runtimes into concrete RuntimeProvider instances, and
 * the agent→runtime mapping the adapters declare. Purely in-memory - no
 * `opencode serve` process, no real tmux server, no Claude/opencode spawn.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Runtimes\HeadlessRuntime;
use HostAgent\Runtimes\TmuxRuntime;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\ClaudeHeadlessRuntime;

// --- supported runtimes per agent ---
$cases = [
    'claude' => [RuntimeType::HEADLESS, RuntimeType::TMUX],
    'antigravity' => [RuntimeType::TMUX],
    'opencode' => [RuntimeType::HEADLESS, RuntimeType::TMUX],
    'codex' => [RuntimeType::HEADLESS],
];

foreach ($cases as $agent => $expected) {
    assert_equal(
        $expected,
        RuntimeRegistry::supported($agent),
        "{$agent}: supported_runtimes() returns the certified set"
    );
}

// --- defaults ---
assert_true(
    RuntimeRegistry::default_for('claude') instanceof ClaudeHeadlessRuntime,
    'claude: default runtime is ClaudeHeadlessRuntime (headless preferred over tmux)'
);
assert_true(
    RuntimeRegistry::default_for('codex') instanceof CodexHeadlessRuntime,
    'codex: default and only runtime is CodexHeadlessRuntime'
);
assert_true(
    RuntimeRegistry::default_for('opencode') instanceof HeadlessRuntime,
    'opencode: default runtime is HeadlessRuntime (headless preferred over tmux)'
);
assert_true(
    RuntimeRegistry::runtime_for('codex', RuntimeType::HEADLESS) instanceof CodexHeadlessRuntime,
    'codex+headless resolves to CodexHeadlessRuntime'
);
assert_equal(null, RuntimeRegistry::runtime_for('codex', RuntimeType::TMUX), 'codex+tmux resolves to null');

// --- runtime_for returns the right provider for a supported runtime ---
assert_true(
    RuntimeRegistry::runtime_for('claude', RuntimeType::TMUX) instanceof TmuxRuntime,
    'claude+tmux resolves to TmuxRuntime'
);
assert_true(
    RuntimeRegistry::runtime_for('opencode', RuntimeType::HEADLESS) instanceof HeadlessRuntime,
    'opencode+headless resolves to HeadlessRuntime'
);
assert_true(
    RuntimeRegistry::runtime_for('opencode', RuntimeType::TMUX) instanceof TmuxRuntime,
    'opencode+tmux resolves to TmuxRuntime'
);

// --- runtime_for returns null for an agent+runtime it doesn't support ---
assert_true(
    RuntimeRegistry::runtime_for('claude', RuntimeType::HEADLESS) instanceof \HostAgent\Runtimes\ClaudeHeadlessRuntime,
    'claude+headless resolves to ClaudeHeadlessRuntime'
);
assert_equal(
    null,
    RuntimeRegistry::runtime_for('antigravity', RuntimeType::HEADLESS),
    'antigravity+headless resolves to null (agy has no headless session)'
);

// --- runtime ids round-trip ---
assert_equal(RuntimeType::TMUX, RuntimeRegistry::runtime_for('claude', RuntimeType::TMUX)?->id(), 'TmuxRuntime::id() is "tmux"');
assert_equal(RuntimeType::HEADLESS, RuntimeRegistry::runtime_for('opencode', RuntimeType::HEADLESS)?->id(), 'HeadlessRuntime::id() is "headless"');

// --- unknown agent still throws (consistent with AgentRegistry) ---
$threw = false;
try {
    AgentRegistry::get('nonexistent-agent');
} catch (\InvalidArgumentException) {
    $threw = true;
}
assert_true($threw, 'unknown agent id is rejected');

test_exit();
