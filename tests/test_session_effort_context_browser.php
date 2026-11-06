<?php
declare(strict_types=1);

/**
 * The session page's reasoning-effort control and context readout in a real headless Chrome against the
 * canned agent (states driven through CANNED_EFFORT_STATE / CANNED_CONTEXT_STATE): the effort endpoint's
 * guards, a change reaching the backend and the control showing what the session actually ended up with
 * (a capped "max" shows "high"), polling following an outside change, a refusal being surfaced with the
 * prior value restored, an unavailable control being disabled, and the header's "ctx N%" following the poll
 * for Claude and Codex (absent when the percentage is unknown). Skips (exit 0) when no Chrome is installed.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/cdp.php';

const SEC_SESSION = 'cc-20260101-1200';

function sec_wait(array &$page, string $expression, float $timeout = 12.0): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (cdp_evaluate($page, $expression) === true) {
            return true;
        }

        usleep(200000);
    } while (microtime(true) < $deadline);

    return cdp_evaluate($page, $expression) === true;
}

$root = sys_get_temp_dir() . '/sessioneer-test-effort-context-' . getmypid();
mkdir($root, 0700, true);
$effortState = $root . '/effort.json';
$contextState = $root . '/context.json';
file_put_contents($effortState, json_encode(['effort' => 'medium']));
file_put_contents($contextState, json_encode(['agent' => 'claude', 'pct' => 12]));
putenv('CANNED_EFFORT_STATE=' . $effortState);
putenv('CANNED_CONTEXT_STATE=' . $contextState);

$socket = $root . '/agent.sock';
$harness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $socket);
$port = test_free_port();
$base = 'http://127.0.0.1:' . $port;
$url = $base . '/session?session=' . SEC_SESSION;
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
    $pipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $socket])
);
$browser = $page = null;

try {
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline && !($c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2))) {
        usleep(50000);
    }

    $jar = $root . '/cookies';
    $response = curl_request('GET', $url, [], $jar);
    assert_equal(200, $response['status'], 'the session page loads');
    preg_match('/window\.V2S = (\{.*?\});<\/script>/s', $response['body'], $m);
    $csrf = json_decode($m[1] ?? '{}', true)['csrfToken'] ?? '';
    assert_true(is_string($csrf) && $csrf !== '', 'the page exposes its CSRF token');

    assert_equal(405, curl_request('GET', $base . '/session_effort.php')['status'], 'effort endpoint refuses GET');
    assert_equal(403, curl_request('POST', $base . '/session_effort.php', ['-d', 'session=' . SEC_SESSION . '&effort=high'])['status'], 'effort endpoint requires CSRF');
    $missing = curl_request('POST', $base . '/session_effort.php', ['-d', http_build_query(['session' => SEC_SESSION, 'csrf_token' => $csrf])], $jar);
    assert_equal(false, json_decode($missing['body'], true)['ok'] ?? null, 'a missing effort cannot clear the override by accident');

    $chrome = cdp_find_chrome();
    $browser = $chrome === null ? null : cdp_launch($chrome);
    $page = $browser === null ? null : cdp_open_page($browser);

    if ($page === null) {
        echo "  SKIP: no headless browser found - nothing else to check\n";
    } else {
        cdp_set_viewport($page, 390, 844, 1.0, true);
        cdp_navigate($page, $url);
        $select = "document.getElementById('v2s-c-effort')";
        $summary = "document.getElementById('v2s-ctrl-summary').textContent";
        // The controls sheet is built when it opens; the button's summary follows every poll.
        $reopen = "(function () { var d = document.getElementById('v2s-controls'); if (d.open) { d.close(); } document.getElementById('v2s-ctrl-open').click(); return true; }())";
        $change = static fn (string $v): string => "{$GLOBALS['select']}.value = " . json_encode($v) . "; {$GLOBALS['select']}.dispatchEvent(new Event('change', {bubbles: true}));";
        $requested = static fn (): mixed => json_decode((string)file_get_contents($GLOBALS['effortState']), true)['requested'] ?? null;

        assert_true(sec_wait($page, "{$summary}.indexOf('Medium') !== -1"), 'the control summary starts at the applied level');
        cdp_evaluate($page, $reopen);
        assert_equal('medium', cdp_evaluate($page, "{$select}.value"), 'the opened control starts at the applied level');

        cdp_evaluate($page, $change('high'));
        assert_true(sec_wait($page, "{$summary}.indexOf('High') !== -1") && $requested() === 'high', 'a change reaches the backend and the summary shows the applied value');

        file_put_contents($effortState, json_encode(['effort' => 'low']));
        assert_true(sec_wait($page, "{$summary}.indexOf('Low') !== -1"), 'polling reflects an outside change');

        cdp_evaluate($page, $change(''));
        assert_true(sec_wait($page, "{$summary}.indexOf('Medium') !== -1") && $requested() === '', 'resetting to default shows the level the CLI resolved');

        cdp_evaluate($page, $change('max'));
        assert_true(sec_wait($page, "{$summary}.indexOf('High') !== -1") && $requested() === 'max', 'a capped choice shows the level actually applied');

        file_put_contents($effortState, json_encode(['effort' => 'low', 'reject' => true]));
        assert_true(sec_wait($page, "{$summary}.indexOf('Low') !== -1"), 'the rejecting backend state is polled in');
        cdp_evaluate($page, $change('medium'));
        assert_true(sec_wait($page, "document.getElementById('v2s-ctrl-status').textContent.indexOf('Rejected') !== -1 && {$summary}.indexOf('Low') !== -1"), 'a refusal is shown and the prior level stays');

        file_put_contents($effortState, json_encode(['effort' => null, 'available' => false]));
        sleep(4);
        cdp_evaluate($page, $reopen);
        assert_true(sec_wait($page, "{$select}.disabled === true"), 'an unavailable runtime disables the control');

        file_put_contents($effortState, json_encode(['effort' => 'high']));
        sleep(4);
        cdp_evaluate($page, $reopen);
        assert_true(sec_wait($page, "{$select}.value === 'high' && !{$select}.disabled"), 'the control recovers when effort becomes available');
        cdp_evaluate($page, "document.getElementById('v2s-controls').close()");

        unlink($effortState); // its detail overlay forces agent=claude
        foreach (['claude', 'codex'] as $agent) {
            file_put_contents($contextState, json_encode(['agent' => $agent, 'pct' => 12]));
            cdp_navigate($page, $url);
            assert_true(sec_wait($page, "document.getElementById('v2s-sub').textContent.indexOf('ctx 12%') !== -1"), "{$agent}: the header shows the context share");

            foreach ([81, 4, 0] as $pct) {
                file_put_contents($contextState, json_encode(['agent' => $agent, 'pct' => $pct]));
                assert_true(sec_wait($page, "document.getElementById('v2s-sub').textContent.indexOf('ctx {$pct}%') !== -1"), "{$agent}: the poll updates the share to {$pct}%");
            }

            assert_true(sec_wait($page, "!!document.querySelector('#v2s-sub .v2-warn') === false"), "{$agent}: a low share carries no warning style");
            file_put_contents($contextState, json_encode(['agent' => $agent, 'pct' => 85]));
            assert_true(sec_wait($page, "!!document.querySelector('#v2s-sub .v2-warn')"), "{$agent}: 80% and over is flagged");
            file_put_contents($contextState, json_encode(['agent' => $agent, 'pct' => null]));
            assert_true(sec_wait($page, "document.getElementById('v2s-sub').textContent.indexOf('ctx') === -1"), "{$agent}: an unknown share shows no reading");
        }

        assert_equal([], cdp_drain_console_errors($page), 'no browser errors');
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }

    if ($browser !== null) {
        cdp_shutdown($browser);
    }

    proc_terminate($server);
    proc_close($server);
    stop_harness($harness, $socket);
    exec('rm -rf ' . escapeshellarg($root));
}

test_exit();
