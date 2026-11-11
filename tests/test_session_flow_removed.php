<?php
declare(strict_types=1);

/**
 * Step 7 of session-flow: the advise stack is gone everywhere, not only from the CLI. Written from the
 * Contract only. Intent notes:
 *  - the advise classes, AdvisePolicy, the advice script and the five test_advise_* files no longer exist (any
 *    file with "advise" in its name under src/, host-agent/, public/, bin/ or tests/ is a leftover);
 *  - nothing under src/, host-agent/, bin/ or public/ names an Advise class or calls UiView::advise_visible
 *    (a dangling `use` or static call would fatal at the first request, long after the file was deleted);
 *  - the host dispatcher has no `advise` case (an old client gets "Unknown action", it never runs a model),
 *    and the web UI no longer routes the old `advise` and `advise_act` actions (behaviour is pinned in
 *    test_ui_dashboard.php; here the controller source is checked for the names and the view for the method);
 *  - the quota park percentage ReceiverPicker needs lives in the flow code itself (behaviour: the 89.9 / 90 / 100
 *    cases in test_session_flow_receiver.php, which never names AdvisePolicy).
 */

require __DIR__ . '/lib/assert.php';

$root = dirname(__DIR__);
$scan = static function (array $dirs) use ($root): array {
    $files = [];
    foreach ($dirs as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !str_contains($file->getPathname(), '/state/')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
};

echo "files are gone\n";
foreach ([
    'host-agent/lib/Services/AdvisePolicy.php', 'host-agent/lib/Services/AdviseRunner.php', 'host-agent/lib/Services/AdviseService.php',
    'host-agent/lib/Services/AdviseModelCall.php', 'src/lib/AdvisePolicy.php', 'public/js/ui-advise.js',
    'tests/test_advise_runner.php', 'tests/test_advise_service.php', 'tests/test_advise_model_call.php',
    'tests/test_advise_visibility.php', 'tests/test_advise_browser.php',
] as $gone) {
    assert_true(!file_exists($root . '/' . $gone), "$gone is deleted");
}
$leftovers = array_values(array_filter($scan(['src', 'host-agent', 'public', 'bin', 'tests']), static fn (string $f): bool => stripos(basename($f), 'advise') !== false));
assert_equal([], array_map(static fn (string $f): string => substr($f, strlen($root) + 1), $leftovers), 'no file named after advise is left anywhere');
$weights = (string)file_get_contents($root . '/tests/shard-weights.txt');
assert_true(stripos($weights, 'advise') === false, 'shard-weights.txt lists no advise test');
assert_true(is_file($root . '/public/js/ui-flow.js'), 'public/js/ui-flow.js takes the advice script\'s place');

echo "no reference to the Advise classes\n";
$pattern = '/use\s+[\w\\\\]*Advise(Policy|Runner|Service|ModelCall)|Advise(Policy|Runner|Service|ModelCall)::|new\s+Advise|Services\\\\Advise|advise_visible/';
$hits = [];
foreach ($scan(['src', 'host-agent', 'bin', 'public']) as $file) {
    if (preg_match($pattern, (string)file_get_contents($file), $m) === 1) {
        $hits[] = substr($file, strlen($root) + 1) . ' (' . $m[0] . ')';
    }
}
assert_equal([], $hits, 'nothing under src/, host-agent/, bin/ or public/ uses an Advise class or advise_visible');
assert_true(preg_match($pattern, 'use HostAgent\\Services\\AdviseRunner;') === 1 && preg_match($pattern, 'AdvisePolicy::QUOTA_PARK_PCT') === 1 && preg_match($pattern, 'UiView::advise_visible($s)') === 1, 'the pattern itself catches the three forms (guards this test against a typo)');

echo "no advise view state\n";
foreach ($scan(['src']) as $file) {
    $source = (string)file_get_contents($file);
    assert_true(!str_contains($source, 'adviseShown') && !str_contains($source, 'data-advise'), substr($file, strlen($root) + 1) . ': no adviseShown key, no data-advise markup');
}
require_once $root . '/vendor/autoload.php';
assert_true(!method_exists(\App\Views\UiView::class, 'advise_visible'), 'UiView has no advise_visible');
assert_true(!class_exists('App\\AdvisePolicy') && !class_exists('HostAgent\\Services\\AdviseRunner'), 'the classes do not autoload');

echo "the host dispatcher and the web router\n";
$sessions = (string)file_get_contents($root . '/host-agent/lib/Sessions.php');
assert_true(preg_match('/case\s+[\'"]advise[\'"]/', $sessions) !== 1, "host dispatcher: no case 'advise'");
foreach (['flow_next', 'flow_offer', 'flow_rotate', 'flow_rotate_request', 'flow_judge', 'flow_offer_dismiss'] as $kept) {
    assert_true(preg_match('/case\s+\'' . $kept . '\'/', $sessions) === 1, "host dispatcher: still has the $kept action");
}
$controller = (string)file_get_contents($root . '/src/lib/Controllers/UiController.php');
assert_true(preg_match('/[\'"]advise(_act)?[\'"]/', $controller) !== 1, 'UiController names neither the advise nor the advise_act action');
foreach (['flow_rotate', 'flow_rotate_request', 'flow_judge', 'flow_offer_dismiss'] as $action) {
    assert_true(preg_match('/[\'"]' . $action . '[\'"]/', $controller) === 1, "UiController routes $action");
}
foreach ($scan(['public/js']) as $file) {
    assert_true(!preg_match("/action['\"]?\s*:\s*['\"]advise/", (string)file_get_contents($file)), substr($file, strlen($root) + 1) . ' posts no advise action');
}

echo "the old host action is unknown\n";
if (preg_match('/case\s+[\'"]advise[\'"]/', $sessions) !== 1) {
    $dir = sys_get_temp_dir() . '/sessioneer-test-flow-removed-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    });
    putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
    putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
    putenv("SIDECAR_DIR={$dir}");
    require $root . '/host-agent/lib/Sessions.php';
    assert_equal(['ok' => false, 'message' => 'Unknown action'], dispatch_action(['action' => 'advise', 'target' => 'x']), 'advise: answered as an unknown action, no model run');
} else {
    assert_true(false, "advise: still dispatched (case 'advise' exists), so the unknown-action reply is not checked");
}

test_exit();
