<?php

declare(strict_types=1);

// Real SSR + JS polling against the canned agent. Temporary HTTP/UNIX sockets,
// no tmux/agent calls. Chrome optional, as in the existing browser tier.
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/cdp.php';
require __DIR__ . '/lib/free_port.php';

function context_browser_wait(callable $check): bool
{
    $deadline = microtime(true) + 8;
    do {
        if ($check()) return true;
        usleep(150000);
    } while (microtime(true) < $deadline);
    return false;
}

$root = sys_get_temp_dir() . '/sessioneer-test-context-browser-' . getmypid();
mkdir($root, 0700, true);
$statePath = $root . '/state.json';
file_put_contents($statePath, json_encode(['agent' => 'claude', 'pct' => 12]));
putenv('CANNED_CONTEXT_STATE=' . $statePath);
$socket = $root . '/agent.sock';
$harness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $socket);
$port = test_free_port();
$url = 'http://127.0.0.1:' . $port . '/session.php?session=cc-20260101-1200';
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
    $pipes, null, array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $socket]));
$browser = null;
$page = null;
try {
    assert_true(context_browser_wait(static fn (): bool => curl_request('GET', $url)['status'] === 200), 'context fixture HTTP server starts');
    foreach (['claude', 'codex'] as $agent) {
        foreach ([12, null] as $pct) {
            file_put_contents($statePath, json_encode(['agent' => $agent, 'pct' => $pct]));
            $response = curl_request('GET', $url);
            assert_equal(200, $response['status'], $agent . ' context initial page loads');
            assert_true(preg_match('/id="header-context"[^>]*>\s*' . ($pct === null ? 'ctx unavailable' : 'ctx 12%') . '\s*</', $response['body']) === 1, $agent . ' initial render distinguishes measured and unknown context');
        }
    }
    $chrome = cdp_find_chrome();
    $browser = $chrome === null ? null : cdp_launch($chrome);
    $page = $browser === null ? null : cdp_open_page($browser);
    if ($page === null) {
        echo "  SKIP: Chrome unavailable for context polling\n";
    } else {
        foreach (['claude', 'codex'] as $agent) {
            cdp_set_viewport($page, 390, 844, 1, true);
            file_put_contents($statePath, json_encode(['agent' => $agent, 'pct' => 12]));
            cdp_navigate($page, $url . '&context_agent=' . $agent);
            assert_equal($agent, cdp_evaluate($page, 'window.SESSIONEER_BOOTSTRAP.agent'), 'browser loads the requested agent fixture: ' . $agent);
            cdp_evaluate($page, 'if(document.getElementById("quota-info").classList.contains("hidden")){document.getElementById("quota-toggle-btn").click();}');
            assert_true(context_browser_wait(function () use (&$page): bool {
                return cdp_evaluate($page, 'document.getElementById("quota-info").textContent.indexOf("This session: ctx 12%") !== -1') === true;
            }), $agent . ' context renders in footer without account quota');
            foreach ([81, 4, 0, null] as $pct) {
                file_put_contents($statePath, json_encode(['agent' => $agent, 'pct' => $pct]));
                $expected = $pct === null ? 'ctx unavailable' : 'ctx ' . $pct . '%';
                assert_true(context_browser_wait(function () use (&$page, $expected): bool {
                    return cdp_evaluate($page, 'document.getElementById("header-context").textContent.trim() === ' . json_encode($expected)) === true;
                }), $agent . ' poll updates current context: ' . $expected);
                assert_true(cdp_evaluate($page, 'document.getElementById("quota-info").textContent.indexOf(' . json_encode('This session: ' . $expected) . ') !== -1') === true, $agent . ' footer stays in sync with session polling: ' . $expected);
            }
            assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= window.innerWidth'), $agent . ' context header fits a phone viewport');
            assert_equal([], cdp_drain_console_errors($page), $agent . ' context updates emit no browser errors');
            file_put_contents($root . '/' . $agent . '-mobile.png', cdp_screenshot($page));
            cdp_set_viewport($page, 1280, 900, 1, false);
            file_put_contents($root . '/' . $agent . '-desktop.png', cdp_screenshot($page));
        }
    }
} finally {
    if ($page !== null) cdp_close_page($page);
    if ($browser !== null) cdp_shutdown($browser);
    proc_terminate($server);
    proc_close($server);
    stop_harness($harness, $socket);
    exec('rm -rf ' . escapeshellarg($root));
}

test_exit();
