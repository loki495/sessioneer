<?php

declare(strict_types=1);

/**
 * One contract, every registered agent: the same assertions run against each
 * AgentAdapter, so a new agent (or a change to an existing one) that breaks the
 * shared shape fails here rather than in a dashboard. Covers identity, runtimes,
 * spawn argv, permission modes and the model catalog, including the sad paths
 * (an unreachable Codex bridge / OpenCode serve is a handled failure, an unknown
 * agent id is a handled failure, no adapter throws).
 *
 * Run it through tests/run.sh: the Codex/OpenCode fixtures come from
 * tests/.env.testing, and this refuses to run against the real host's servers.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Runtimes\RuntimeType;

if (!str_contains((string)getenv('CODEX_BRIDGE_SOCKET'), 'does-not-exist') || !str_contains((string)getenv('OPENCODE_SERVE_URL'), '127.0.0.1:1')) {
    fwrite(STDERR, "REFUSING TO RUN: the Codex bridge / OpenCode serve are not pinned to fixtures (run via tests/run.sh).\n");
    exit(1);
}

$ids = AgentRegistry::known_agent_ids();
assert_true(count($ids) >= 4, 'the registry lists every agent');

foreach ($ids as $id) {
    echo "{$id}\n";
    $adapter = AgentRegistry::get($id);

    assert_equal($id, $adapter->id(), "{$id}: id() matches its registry key");
    assert_true($adapter->label() !== '', "{$id}: has a label");
    assert_true(preg_match('/^[a-z]{2,3}$/', $adapter->session_name_prefix()) === 1, "{$id}: has a short lowercase session-name prefix");

    $runtimes = $adapter->supported_runtimes();
    assert_true($runtimes !== [] && array_diff($runtimes, RuntimeType::all()) === [], "{$id}: supports at least one known runtime, and only known ones");

    foreach ($adapter->permission_mode_map() as $raw => $mode) {
        assert_true(is_string($mode), "{$id}: permission_mode_map values are strings ({$raw})");
    }

    $spawn = $adapter->build_spawn_argv([]);
    assert_true(is_array($spawn['argv'] ?? null) && array_key_exists('assigned_id', $spawn), "{$id}: build_spawn_argv() returns argv and assigned_id with no options");
    $junk = $adapter->build_spawn_argv(['starting_mode' => 'nonsense']);
    assert_true(!in_array('nonsense', $junk['argv'], true), "{$id}: an unknown starting mode is dropped, not passed to the CLI");

    $catalog = $adapter->model_catalog();
    assert_true(is_bool($catalog['ok'] ?? null), "{$id}: model_catalog() answers ok true/false");

    if ($catalog['ok'] === false) {
        assert_true(is_string($catalog['message'] ?? null) && $catalog['message'] !== '', "{$id}: an unavailable catalog says why, as a handled failure");
        continue;
    }

    $seen = [];

    foreach ($catalog['models'] ?? [] as $row) {
        assert_true(is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '' && is_string($row['name'] ?? null) && $row['name'] !== '', "{$id}: every catalog row has a string id and name");
        assert_true($row['id'] !== 'default', "{$id}: the catalog never carries a default row");
        assert_true(!isset($seen[$row['id']]), "{$id}: catalog ids are unique ({$row['id']})");
        $seen[$row['id']] = true;
    }
}

echo "list_models\n";
foreach (['claude', 'antigravity'] as $id) {
    $viaAgent = sessioneer_list_models($id);
    assert_equal(AgentRegistry::get($id)->model_catalog(), $viaAgent, "list_models({$id}) is the adapter's own catalog");
    assert_true($viaAgent['ok'] === true && ($viaAgent['models'] ?? []) !== [], "{$id}: has models to offer");
}

assert_equal(['ok' => false, 'message' => 'Unknown agent'], sessioneer_list_models('not-an-agent'), 'an unknown agent id is a handled failure');
assert_equal(['ok' => false, 'message' => 'Unknown agent'], sessioneer_list_models(''), 'an empty agent id is a handled failure');

$codex = sessioneer_list_models('codex');
assert_true($codex['ok'] === false && is_string($codex['message'] ?? null), 'an unreachable Codex bridge is a handled failure with a message');

$opencode = sessioneer_list_models('opencode');
assert_true($opencode['ok'] === true && $opencode['models'] === [], 'an unreachable OpenCode serve is an ok, empty catalog');

test_exit();
