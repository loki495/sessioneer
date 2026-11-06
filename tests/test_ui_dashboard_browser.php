<?php
declare(strict_types=1);

/**
 * Dashboard interactions in Chrome against canned_agent only: boot, new-session
 * folder loading and submission, handled refusal/retry, and saved settings.
 * Legacy v2-* preference keys are part of the compatibility contract.
 */
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

function uidb_wait(array &$page, string $expression, float $timeout = 5.0): bool
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

function uidb_change(array &$page, string $id, string|bool $value): void
{
    $property = is_bool($value) ? 'checked' : 'value';
    cdp_evaluate($page, '(function () { var el = document.getElementById(' . json_encode($id) . '); el.' . $property . ' = ' . json_encode($value) . '; el.dispatchEvent(new Event("change", { bubbles: true })); }())');
}

function uidb_preferences(array &$page): mixed
{
    return cdp_evaluate($page, "({ theme: document.getElementById('ui-theme').value, poll: document.getElementById('ui-poll').value, workers: document.getElementById('ui-show-workers').checked, idle: document.getElementById('ui-hide-idle').checked })");
}

$scratch = sys_get_temp_dir() . '/sessioneer-test-dashboard-' . getmypid();
mkdir($scratch, 0700);
$agentSocket = $scratch . '/agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);
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

try {
    assert_true(is_resource($server), 'dashboard: isolated PHP server starts');
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
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
        assert_true($page !== null, 'dashboard: Chrome opens a test page');
    }

    if ($page !== null) {
        assert_true(cdp_set_viewport($page, 390, 844, 1.0, true), 'dashboard: phone viewport set');
        assert_true(cdp_navigate($page, $base . '/'), 'dashboard: page loads');
        assert_equal([], cdp_drain_console_errors($page), 'dashboard: boot has no uncaught exception or console error');
        assert_true(cdp_click($page, '#ui-new'), 'new session: user clicks the New button');
        $opened = uidb_wait($page, "document.getElementById('ui-new-dialog').open");
        assert_true($opened, 'new session: click opens the creation dialog');
        assert_true(uidb_wait($page, "document.getElementById('ui-browser-path').textContent === '/home/user/www' && !document.getElementById('ui-new-submit').disabled"), 'new session: folders load and Start session here becomes usable');
        assert_equal(3, cdp_evaluate($page, "document.querySelectorAll('#ui-browser-list button').length"), 'new session: parent and both canned project folders are available');

        if ($opened) {
            // Refuse once at the HTTP boundary, then let the real fixture endpoint handle retry.
            cdp_evaluate($page, <<<'JS'
            window.__dashboardFetch = window.fetch;
            window.__dashboardPosts = [];
            window.__dashboardRefuse = true;
            window.fetch = function (url, options) {
              if (String(url) === '/action' && options && options.body) {
                window.__dashboardPosts.push(options.body);
                sessionStorage.setItem('dashboard-test-post', options.body);
                if (window.__dashboardRefuse) {
                  window.__dashboardRefuse = false;
                  return Promise.resolve(new Response(JSON.stringify({ ok: false, message: 'Fixture creation refused' }), { status: 200 }));
                }
              }
              return window.__dashboardFetch(url, options);
            };
            document.getElementById('ui-new-prompt').value = 'Fixture first message';
            JS);
            assert_true(cdp_click($page, '#ui-new-submit'), 'new session: user submits the loaded form');
            assert_true(uidb_wait($page, "!document.getElementById('ui-toast').hidden && document.getElementById('ui-toast').textContent === 'Fixture creation refused'"), 'new session: handled creation failure displays the reason');
            assert_equal(true, cdp_evaluate($page, "document.getElementById('ui-new-dialog').open && !document.getElementById('ui-new-submit').disabled"), 'new session: refusal keeps the dialog open and allows retry');
            assert_equal(1, cdp_evaluate($page, 'window.__dashboardPosts.length'), 'new session: one click sends one creation request');
            assert_equal([], cdp_drain_console_errors($page), 'new session: opening and handled refusal raise no console errors');
            assert_true(cdp_click($page, '#ui-new-submit'), 'new session: user retries');
            assert_true(uidb_wait($page, "location.pathname === '/session' && new URLSearchParams(location.search).get('session') === 'cc-fake-headless'"), 'new session: successful fixture retry follows the session redirect');
            $posted = (string)cdp_evaluate($page, "sessionStorage.getItem('dashboard-test-post')");
            parse_str($posted, $fields);
            assert_equal('new', $fields['action'] ?? null, 'new session: submits the new action');
            assert_equal('claude', $fields['agent'] ?? null, 'new session: submits the selected agent');
            assert_equal('/home/user/www', $fields['workdir'] ?? null, 'new session: submits the browsed directory');
            assert_equal('Fixture first message', $fields['prompt'] ?? null, 'new session: submits the first message');
            assert_true(is_string($fields['csrf_token'] ?? null) && $fields['csrf_token'] !== '', 'new session: includes the CSRF token');
            assert_true(cdp_navigate($page, $base . '/'), 'settings: return to dashboard');
        }

        cdp_evaluate($page, "Array.prototype.forEach.call(document.querySelectorAll('dialog[open]'), function (d) { d.close(); });");
        assert_true(cdp_click($page, '#ui-open-settings'), 'settings: user clicks Settings');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('ui-settings-dialog').open"), 'settings: click opens the settings dialog');
        uidb_change($page, 'ui-theme', 'dark');
        uidb_change($page, 'ui-poll', '10000');
        uidb_change($page, 'ui-show-workers', true);
        uidb_change($page, 'ui-hide-idle', true);
        assert_equal('dark', cdp_evaluate($page, "document.documentElement.getAttribute('data-theme')"), 'settings: theme changes immediately');
        assert_equal(['dark', '10000', '1', '1'], cdp_evaluate($page, "['v2-theme', 'v2-poll', 'v2-show-workers', 'v2-hide-idle'].map(function (k) { return localStorage.getItem(k); })"), 'settings: controls save using compatible v2 preference keys');
        assert_true(cdp_navigate($page, $base . '/'), 'settings: reload succeeds');
        assert_equal(['theme' => 'dark', 'poll' => '10000', 'workers' => true, 'idle' => true], uidb_preferences($page), 'settings: all changed preferences survive reload');

        cdp_evaluate($page, "localStorage.setItem('v2-theme', 'light'); localStorage.setItem('v2-poll', '15000'); localStorage.setItem('v2-show-workers', '0'); localStorage.setItem('v2-hide-idle', '0');");
        assert_true(cdp_navigate($page, $base . '/'), 'legacy preferences: reload succeeds');
        assert_equal(['theme' => 'light', 'poll' => '15000', 'workers' => false, 'idle' => false], uidb_preferences($page), 'legacy preferences: existing v2 keys populate every settings control');
        assert_equal('light', cdp_evaluate($page, "document.documentElement.getAttribute('data-theme')"), 'legacy preferences: saved theme is applied');
        assert_true(cdp_click($page, '#ui-new'), 'legacy preferences: New button remains clickable after reload');
        assert_equal(true, cdp_evaluate($page, "document.getElementById('ui-new-dialog').open"), 'legacy preferences: New session still opens');
        assert_equal([], cdp_drain_console_errors($page), 'settings and reloads: no uncaught exception or console error');
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
