<?php
declare(strict_types=1);

/**
 * Phase 2.5 headless-runtime routing test - the pieces of new logic in
 * host-agent/lib/Sessions.php:
 *   1. sessioneer_is_headless_session(): reads the sidecar's `runtime` column, not a
 *      session-id shape heuristic. A ses_* id (or any ref) with a headless
 *      sidecar IS headless; a tmux sidecar is not; no sidecar at all is not.
 *   2. The `list` action carries a `headless` key sourced from headless
 *      sidecars (not a live serve call), and prunes/fails-soft correctly.
 *   3. Headless sidecar writes set runtime=headless; tmux rows keep null/tmux.
 *
 * serve URL pinned to a dead port so the real `opencode serve` at :4096 is
 * never contacted; the sync bails on its (failing) list call, leaving
 * whatever sidecars the test wrote in place.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Stores\SidecarStore;

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';
$realSidecarDir = '/run/user/' . getmyuid() . '/sessioneer-sessions';

if (Config::tmux_socket() === $realTmuxSocket) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir. Check tests/.env.testing.\n");
    exit(1);
}

putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('HEADLESS_SYNC_SECONDS=0'); // force the throttle to fire so the sync path runs
putenv('PUSH_SQLITE_FILE=' . sys_get_temp_dir() . '/sessioneer-test-headless-' . bin2hex(random_bytes(4)) . '/push.sqlite');

// --- routing rule (sidecar runtime, not shape) ---
$headlessRef = 'ses_RouteAaaa';

SidecarStore::write_sidecar($headlessRef, [
    'workdir' => '/tmp',
    'spawned_at' => time(),
    'agent_session_id' => $headlessRef,
    'spawned_by_app' => true,
    'agent' => 'opencode',
    'runtime' => RuntimeType::HEADLESS,
]);
assert_true(sessioneer_is_headless_session($headlessRef), 'a ref with a headless-runtime sidecar is headless');
assert_equal(RuntimeType::HEADLESS, SidecarStore::read_sidecar($headlessRef)['runtime'] ?? null, 'headless sidecar round-trips runtime=headless');

// a tmux opencode session: sidecar keyed by tmux name, runtime tmux/null
SidecarStore::write_sidecar('oc-tmux-route', [
    'workdir' => '/tmp',
    'spawned_at' => time(),
    'agent_session_id' => 'ses_RouteBbbb',
    'spawned_by_app' => true,
    'agent' => 'opencode',
    'runtime' => RuntimeType::TMUX,
]);
assert_true(!sessioneer_is_headless_session('oc-tmux-route'), 'a tmux-runtime sidecar is NOT headless');
assert_true(!sessioneer_is_headless_session('ses_RouteCccc'), 'a ref with no sidecar at all is NOT headless');
assert_true(!sessioneer_is_headless_session(''), 'an empty ref is not headless');

// --- list merges headless into `sessions` (one list card), fails soft ---
$list = dispatch_action(['action' => 'list']);
assert_true(array_key_exists('sessions', $list), 'list action returns `sessions` (headless merged into it)');
assert_true(!array_key_exists('headless', $list), 'no separate `headless` key - headless is merged into sessions');
assert_true(!array_key_exists('message', $list), 'list still succeeds (ok=true backbone) even when the serve sync fails');

// tmux prune on the empty test socket removes the tmux-runtime sidecar
// ('oc-tmux-route' is not a live tmux session), so the only headless row in
// `sessions` is the adopted one. The serve URL is a dead port, so the sync
// bails and never adopts anything real.
$sessionNames = array_column($list['sessions'] ?? [], 'name');
assert_true(in_array($headlessRef, $sessionNames, true), 'adopted headless sidecar is merged into sessions');
$headlessRow = null;
foreach (($list['sessions'] ?? []) as $sess) {
    $sessName = (string)($sess['name'] ?? '');
    if ($sessName === $headlessRef) {
        $headlessRow = $sess;
    }
}
assert_equal(RuntimeType::HEADLESS, $headlessRow['runtime'] ?? null, 'merged headless session carries runtime=headless');
assert_true(!in_array('ses_RouteBbbb', $sessionNames, true), 'tmux-runtime/id-alias does not appear as a session');

// cleanup so this test's sidecars/status don't leak into other test files
// that share the isolated sidecar dir
SidecarStore::delete_sidecar($headlessRef);
SidecarStore::delete_sidecar('oc-tmux-route');

test_exit();
