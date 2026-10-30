<?php

declare(strict_types=1);

// Real SSR + JS polling against the canned agent. Temporary HTTP/UNIX sockets,
// no tmux/agent calls. Chrome optional, as in the existing browser tier.
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/cdp.php';
require __DIR__ . '/lib/free_port.php';

function context_browser_wait(callable $check, float $seconds = 8): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        if ($check()) return true;
        usleep(150000);
    } while (microtime(true) < $deadline);
    return false;
}

function context_browser_summary(array &$page): mixed
{
    return cdp_evaluate($page, 'Array.prototype.map.call(document.querySelectorAll("#quota-toggle-summary > [data-quota-summary-part]"), function(part) { return part.innerText.replace(/\\s+/g, " ").trim(); }).join(" / ")');
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
            cdp_call($page, 'Emulation.setTimezoneOverride', ['timezoneId' => 'UTC']);
            cdp_call($page, 'Emulation.setLocaleOverride', ['locale' => 'en-US']);
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
            assert_equal('5h — / wk — / ctx —', context_browser_summary($page), $agent . ' mobile toggle distinguishes unavailable values');
            cdp_evaluate($page, 'document.getElementById("quota-toggle-btn").click()');
            assert_equal('false', cdp_evaluate($page, 'document.getElementById("quota-toggle-btn").getAttribute("aria-expanded")'), $agent . ' mobile summary still collapses full table');
            file_put_contents($statePath, json_encode(['agent' => $agent, 'pct' => 27]));
            assert_true(context_browser_wait(function () use (&$page): bool {
                return context_browser_summary($page) === '5h — / wk — / ctx 27%';
            }), $agent . ' collapsed summary follows context polling without account quota');
            $sessionReset = strtotime(gmdate('Y-m-d') . ' 10:30:00 UTC');
            $weeklyReset = $sessionReset + 3 * 86400 + 9 * 3600 + 45 * 60;
            $initialSummary = '5h 0% ' . gmdate('M j', $sessionReset) . ' 10:30 AM / wk 63% ' . gmdate('M j', $weeklyReset) . ' 8:15 PM / ctx 27%';
            file_put_contents($statePath, json_encode(['agent' => $agent, 'pct' => 27, 'quota' => ['session' => ['pct' => 0, 'resets_at' => $sessionReset], 'week_all' => ['pct' => 63, 'resets_at' => $weeklyReset]], 'agents' => ['claude' => ['quota' => ['session' => ['pct' => 99], 'week_all' => ['pct' => 98]]]]]));
            cdp_navigate($page, $url . '&context_agent=' . $agent . '&summary=1');
            assert_true(context_browser_wait(function () use (&$page, $initialSummary): bool {
                return context_browser_summary($page) === $initialSummary;
            }), $agent . ' selected quota summary shows absolute dates and times, including today, and retains zero');
            assert_equal(true, cdp_evaluate($page, 'document.getElementById("quota-info").classList.contains("hidden")'), $agent . ' collapsed preference survives summary refresh');
            assert_equal(true, cdp_evaluate($page, 'Math.abs(document.getElementById("quota-toggle-btn").getBoundingClientRect().width - document.getElementById("quota-footer").getBoundingClientRect().width) < 2'), $agent . ' summary toggle occupies a whole mobile row');
            assert_equal(true, cdp_evaluate($page, '(function(){var parts=document.querySelectorAll("[data-quota-summary-part]"); var a=parts[0].getBoundingClientRect(),b=parts[1].getBoundingClientRect(),c=parts[2].getBoundingClientRect();return Math.abs(a.width-b.width)<1 && Math.abs(b.width-c.width)<1 && Math.abs(a.top-b.top)<1 && Math.abs(b.top-c.top)<1 && getComputedStyle(parts[0]).textAlign==="center";})()'), $agent . ' three centered sections share the mobile row evenly');

            $updatedSummary = '5h 45% ' . gmdate('M j', $sessionReset + 4500) . ' 11:45 AM / wk 0% ' . gmdate('M j', $weeklyReset + 86400) . ' 8:15 PM / ctx 27%';
            file_put_contents($statePath, json_encode(['agent' => $agent, 'pct' => 27, 'quota' => ['session' => ['pct' => 45, 'resets_at' => $sessionReset + 4500], 'week_all' => ['pct' => 0, 'resets_at' => $weeklyReset + 86400]], 'agents' => ['claude' => ['quota' => ['session' => ['pct' => 99], 'week_all' => ['pct' => 98]]]]]));
            assert_true(context_browser_wait(function () use (&$page, $updatedSummary): bool {
                return context_browser_summary($page) === $updatedSummary;
            }, 75), $agent . ' normal quota poll refreshes percentages and absolute reset dates while collapsed');
            cdp_evaluate($page, 'document.getElementById("quota-toggle-btn").click()');
            assert_equal('true', cdp_evaluate($page, 'document.getElementById("quota-toggle-btn").getAttribute("aria-expanded")'), $agent . ' summary reopens full table');
            assert_equal(true, cdp_evaluate($page, '!!document.querySelector("#quota-info table") && document.getElementById("quota-info").getBoundingClientRect().height > 0'), $agent . ' summary preserves visible full quota table');
            cdp_evaluate($page, 'document.getElementById("quota-toggle-btn").click()');
            assert_equal(0, cdp_evaluate($page, 'document.getElementById("quota-info").getBoundingClientRect().height'), $agent . ' reset-date summary stays visible with full table collapsed');
            assert_equal(true, cdp_evaluate($page, 'document.documentElement.scrollWidth <= window.innerWidth'), $agent . ' mobile footer has no horizontal overflow');
            assert_equal([], cdp_drain_console_errors($page), $agent . ' context updates emit no browser errors');
            file_put_contents($root . '/' . $agent . '-mobile.png', cdp_screenshot($page));
            if (getenv('SESSIONEER_TEST_ARTIFACT_DIR')) {
                file_put_contents(getenv('SESSIONEER_TEST_ARTIFACT_DIR') . '/' . $agent . '-mobile.png', cdp_screenshot($page));
            }
            cdp_set_viewport($page, 1280, 900, 1, false);
            assert_equal('none', cdp_evaluate($page, 'getComputedStyle(document.getElementById("quota-toggle-summary")).display'), $agent . ' desktop keeps the Quota label');
            assert_equal(true, cdp_evaluate($page, 'getComputedStyle(document.getElementById("quota-toggle-label")).display !== "none"'), $agent . ' desktop Quota label stays visible');
            file_put_contents($root . '/' . $agent . '-desktop.png', cdp_screenshot($page));
            if (getenv('SESSIONEER_TEST_ARTIFACT_DIR')) {
                file_put_contents(getenv('SESSIONEER_TEST_ARTIFACT_DIR') . '/' . $agent . '-desktop.png', cdp_screenshot($page));
            }
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
