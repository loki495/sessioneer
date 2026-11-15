<?php
declare(strict_types=1);

/**
 * The wake-up rows driven by a real headless Chrome (tests/lib/cdp.php) against the fake host agent
 * tests/fixtures/fake_wake_ui_agent.php (never a real `at`): the dashboard fold filling in after load,
 * "Show full prompt" (textContent only, fetched once, toggled), the confirm text of Cancel exactly, a
 * refused confirm sending nothing, an accepted confirm posting, toasting and fetching the list again, a
 * failed cancel toasting as an error, and no cancel button on a running row. Skips (exit 0) when no Chrome
 * is installed, like the other *_browser.php files.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

function uiw_wait(array &$page, string $expression, float $timeout = 5.0): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (cdp_evaluate($page, $expression) === true) {
            return true;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    return false;
}

/** Waits until the fake agent has logged more than $above requests of $action. */
function uiw_wait_more(string $log, string $action, int $above, float $timeout = 5.0): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (uiw_count($log, $action) > $above) {
            return true;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    return false;
}

/** @return list<array<string, mixed>> */
function uiw_requests(string $log): array
{
    return array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string)@file_get_contents($log)))));
}

function uiw_count(string $log, string $action, ?int $job = null): int
{
    return count(array_filter(uiw_requests($log), static fn (array $r): bool => ($r['action'] ?? '') === $action && ($job === null || ($r['job'] ?? null) === $job)));
}

$scratch = sys_get_temp_dir() . '/sessioneer-test-wakes-browser-' . getmypid();
mkdir($scratch, 0700);
$agentSocket = $scratch . '/agent.sock';
$log = $scratch . '/agent.log';
putenv('FAKE_WAKE_UI_LOG=' . $log);
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/fake_wake_ui_agent.php'], $agentSocket);
$port = test_free_port();
$server = proc_open(
    ['php', '-d', 'session.save_path=' . $scratch, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $scratch . '/server.log', 'w']],
    $pipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket])
);
$base = "http://127.0.0.1:{$port}";
$browser = null;
$page = null;
$hostilePrompt = "<script>alert(1)</script>\n\" onmouseover=\"alert(2)\" 'x' & \$HOME `date` \\ end\n--workdir";

try {
    assert_true(is_resource($server), 'wakes browser: isolated PHP server starts');
    fclose($pipes[0]);
    $deadline = microtime(true) + 3.0;

    do {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if (is_resource($connection)) {
            fclose($connection);
            break;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    $chromeBin = cdp_find_chrome();

    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
        assert_true($page !== null, 'wakes browser: Chrome opens a test page');
    }

    if ($page !== null) {
        assert_true(cdp_set_viewport($page, 1000, 900, 1.0, false), 'viewport set');
        assert_true(cdp_navigate($page, $base . '/'), 'the dashboard loads');

        echo "The fold fills in after load\n";
        assert_true(uiw_wait($page, "!document.getElementById('ui-wakes').hidden"), 'the fold is shown once the list has rows');
        assert_equal('3', cdp_evaluate($page, "document.getElementById('ui-wakes-n').textContent"), 'its heading counts the wake-ups');
        assert_equal(3, cdp_evaluate($page, "document.querySelectorAll('#ui-wakes-body .ui-wake').length"), 'and the body holds the rows');
        assert_equal(false, cdp_evaluate($page, "document.getElementById('ui-wakes').open"), 'the script never opens the fold itself');
        cdp_evaluate($page, "document.getElementById('ui-wakes').open = true");
        assert_equal('2026-11-15 10:30 EST', cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] time[data-at]').getAttribute('title')"), 'a row\'s time keeps the host agent\'s local time as its title');
        assert_true((string)cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] time[data-at]').textContent") !== '', 'and shows a time');

        echo "No cancel button on a running row\n";
        assert_equal(true, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"12\"]') !== null && document.querySelector('[data-wake-job=\"12\"] [data-wake-cancel]') === null"), 'the running row is listed without a cancel button');
        assert_equal(true, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] [data-wake-cancel]') !== null"), 'a scheduled row has one');

        echo "Show full prompt\n";
        $showsBefore = uiw_count($log, 'launch_wake_show', 11);
        assert_true(cdp_click($page, '[data-wake-job="11"] [data-wake-show]'), 'the user clicks Show full prompt');
        assert_true(uiw_wait($page, "!document.querySelector('[data-wake-job=\"11\"] pre.ui-wake-full').hidden"), 'the prompt is shown');
        assert_equal($hostilePrompt, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] pre.ui-wake-full').textContent"), 'as text, byte-exact');
        assert_equal(0, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] pre.ui-wake-full').children.length + document.querySelectorAll('#ui-wakes-body script, #ui-wakes-body b').length"), 'no element was created from the prompt');
        assert_equal('Hide full prompt', cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] [data-wake-show]').textContent"), 'the button now says Hide full prompt');
        assert_true(cdp_click($page, '[data-wake-job="11"] [data-wake-show]'), 'a second click');
        assert_equal(true, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] pre.ui-wake-full').hidden"), 'hides it again');
        assert_equal('Show full prompt', cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] [data-wake-show]').textContent"), 'and the label returns');
        assert_true(cdp_click($page, '[data-wake-job="11"] [data-wake-show]'), 'a third click');
        assert_equal(false, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] pre.ui-wake-full').hidden"), 'shows it again');
        assert_equal($showsBefore + 1, uiw_count($log, 'launch_wake_show', 11), 'the prompt was fetched once, not on every toggle');

        echo "Cancel: the confirm text, and refusing it\n";
        cdp_evaluate($page, <<<'JS'
        window.__confirms = [];
        window.__confirmAnswer = false;
        window.confirm = function (text) { window.__confirms.push(text); return window.__confirmAnswer; };
        JS);
        $cancelsBefore = uiw_count($log, 'launch_wake_cancel');
        $listsBefore = uiw_count($log, 'launch_wake_list');
        assert_true(cdp_click($page, '[data-wake-job="11"] [data-wake-cancel]'), 'the user clicks Cancel');
        usleep(300000);
        $time = (string)cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] time[data-at]').textContent");
        assert_equal(['Cancel the wake-up at ' . $time . ' that sends "scheduled later"?'], cdp_evaluate($page, 'window.__confirms'), 'the confirm text names the row\'s time and the first 80 characters of its preview');
        assert_equal($cancelsBefore, uiw_count($log, 'launch_wake_cancel'), 'a refused confirm sends no cancel request');
        assert_equal($listsBefore, uiw_count($log, 'launch_wake_list'), 'and does not refetch the list');
        assert_equal(false, cdp_evaluate($page, "document.querySelector('[data-wake-job=\"11\"] [data-wake-cancel]').disabled"), 'the button stays usable');

        echo "Cancel: accepting the confirm\n";
        cdp_evaluate($page, 'window.__confirmAnswer = true');
        assert_true(cdp_click($page, '[data-wake-job="11"] [data-wake-cancel]'), 'the user clicks Cancel again and confirms');
        assert_true(uiw_wait($page, "!document.getElementById('ui-toast').hidden && document.getElementById('ui-toast').textContent === 'Cancelled wake-up 11 (2026-11-15 10:30 EST)'"), 'the toast shows the host agent\'s message');
        assert_equal(false, cdp_evaluate($page, "document.getElementById('ui-toast').classList.contains('is-bad')"), 'as a good toast');
        assert_equal($cancelsBefore + 1, uiw_count($log, 'launch_wake_cancel', 11), 'exactly one cancel request, for job 11');
        assert_true(uiw_wait_more($log, 'launch_wake_list', $listsBefore), 'the list is fetched again');
        $posts = array_values(array_filter(uiw_requests($log), static fn (array $r): bool => ($r['action'] ?? '') === 'launch_wake_cancel'));
        assert_true(!array_key_exists('from', $posts[count($posts) - 1] ?? []), 'the dashboard sends no from');

        echo "Cancel: a refusal toasts as an error and still refetches\n";
        $listsBefore = uiw_count($log, 'launch_wake_list');
        assert_true(cdp_click($page, '[data-wake-job="15"] [data-wake-cancel]'), 'the user cancels the overdue row, whose job is gone');
        assert_true(uiw_wait($page, "document.getElementById('ui-toast').textContent.indexOf('no longer exists') !== -1"), 'the toast shows the reason');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('ui-toast').classList.contains('is-bad')"), 'as an error toast');
        assert_true(uiw_wait_more($log, 'launch_wake_list', $listsBefore), 'and the list is fetched again');
        assert_equal([], cdp_drain_console_errors($page), 'no console errors');
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }

    if ($browser !== null) {
        cdp_shutdown($browser);
    }

    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    stop_harness($agentHarness, $agentSocket);

    foreach (glob($scratch . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($scratch);
}

test_exit();
