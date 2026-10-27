<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/cdp.php';
require __DIR__ . '/lib/free_port.php';

function effort_wait(callable $check): bool
{
    $deadline = microtime(true) + 8;
    do {
        if ($check()) return true;
        usleep(100000);
    } while (microtime(true) < $deadline);
    return false;
}
$root = sys_get_temp_dir() . '/sessioneer-test-effort-browser-' . getmypid();
mkdir($root, 0700, true);
$state = $root . '/state.json';
file_put_contents($state, json_encode(['effort' => 'medium']));
putenv('CANNED_EFFORT_STATE=' . $state);
$socket = $root . '/agent.sock';
$harness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $socket);
$port = test_free_port();
$base = 'http://127.0.0.1:' . $port;
$url = $base . '/session.php?session=cc-20260101-1200';
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
    $pipes, null, array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $socket]));
$browser = $page = null;
try {
    assert_true(effort_wait(static fn (): bool => curl_request('GET', $url)['status'] === 200), 'effort fixture starts');
    $response = curl_request('GET', $url, [], $root . '/cookies');
    assert_true(str_contains($response['body'], 'id="effort-select"'), 'server renders the effort selector');
    assert_true(preg_match('/value="medium" selected/', $response['body']) === 1, 'SSR reflects applied effort');
    preg_match('/window\.SESSIONEER_BOOTSTRAP = (\{[^\n]+\});/', $response['body'], $match);
    $csrf = json_decode($match[1] ?? '{}', true)['csrfToken'] ?? '';
    assert_true(is_string($csrf) && $csrf !== '', 'fixture exposes its real CSRF token');
    assert_equal(405, curl_request('GET', $base . '/session_effort.php')['status'], 'effort endpoint refuses GET');
    assert_equal(403, curl_request('POST', $base . '/session_effort.php', ['-d', 'session=cc-20260101-1200&effort=high'])['status'], 'effort endpoint requires CSRF');
    $missing = curl_request('POST', $base . '/session_effort.php', ['-d', http_build_query(['session' => 'cc-20260101-1200', 'csrf_token' => $csrf])], $root . '/cookies');
    assert_equal(false, json_decode($missing['body'], true)['ok'] ?? null, 'missing effort cannot accidentally clear the override');
    $chrome = cdp_find_chrome();
    $browser = $chrome === null ? null : cdp_launch($chrome);
    $page = $browser === null ? null : cdp_open_page($browser);
    if ($page === null) {
        echo "  SKIP: Chrome unavailable for effort control\n";
    } else {
        cdp_set_viewport($page, 390, 844, 1, true);
        cdp_navigate($page, $url);
        assert_equal('medium', cdp_evaluate($page, 'document.getElementById("effort-select").value'), 'browser starts at the applied level');
        assert_equal(true, cdp_evaluate($page, '(function(){var s=document.querySelectorAll("#claude-session-controls select");return s.length===3 && Math.abs(s[0].getBoundingClientRect().top-s[1].getBoundingClientRect().top)<1 && Math.abs(s[1].getBoundingClientRect().top-s[2].getBoundingClientRect().top)<1;})()'), 'model, permission mode and effort share a phone row');
        cdp_evaluate($page, 'document.getElementById("effort-select").value="high"; document.getElementById("effort-select").dispatchEvent(new Event("change"));');
        assert_true(effort_wait(function () use (&$page, $state): bool {
            return (json_decode((string) file_get_contents($state), true)['requested'] ?? null) === 'high'
                && cdp_evaluate($page, 'document.getElementById("effort-select").value==="high" && !document.getElementById("effort-select").disabled') === true;
        }), 'changing the select reaches the guarded backend and refreshes the applied value');
        file_put_contents($state, json_encode(['effort' => 'low']));
        assert_true(effort_wait(function () use (&$page): bool {
            return cdp_evaluate($page, 'document.getElementById("effort-select").value==="low"') === true;
        }), 'normal detail polling reflects external effort changes');
        cdp_evaluate($page, 'document.getElementById("effort-select").value=""; document.getElementById("effort-select").dispatchEvent(new Event("change"));');
        assert_true(effort_wait(function () use (&$page, $state): bool {
            return (json_decode((string) file_get_contents($state), true)['requested'] ?? null) === ''
                && cdp_evaluate($page, 'document.getElementById("effort-select").value==="medium" && !document.getElementById("effort-select").disabled') === true;
        }), 'default reset shows the CLI-resolved level');
        cdp_evaluate($page, 'document.getElementById("effort-select").value="max"; document.getElementById("effort-select").dispatchEvent(new Event("change"));');
        assert_true(effort_wait(function () use (&$page, $state): bool {
            return (json_decode((string) file_get_contents($state), true)['requested'] ?? null) === 'max'
                && cdp_evaluate($page, 'document.getElementById("effort-select").value==="high" && !document.getElementById("effort-select").disabled') === true;
        }), 'a capped choice displays the actual applied level instead of requested max');
        file_put_contents($state, json_encode(['effort' => 'low', 'reject' => true]));
        cdp_evaluate($page, 'document.getElementById("effort-select").value="medium"; document.getElementById("effort-select").dispatchEvent(new Event("change"));');
        assert_true(effort_wait(function () use (&$page): bool {
            return cdp_evaluate($page, 'document.getElementById("effort-setting-error").textContent.indexOf("Rejected")!==-1 && document.getElementById("effort-select").value==="low" && !document.getElementById("effort-select").disabled') === true;
        }), 'CLI rejection is visible and the actual prior value is restored');
        file_put_contents($state, json_encode(['effort' => null, 'available' => false]));
        assert_true(effort_wait(function () use (&$page): bool {
            return cdp_evaluate($page, 'document.getElementById("effort-select").disabled && document.getElementById("effort-select").title.indexOf("unavailable")!==-1') === true;
        }), 'unsupported runtime/model disables the control with an explanation');
        file_put_contents($state, json_encode(['effort' => 'high']));
        assert_true(effort_wait(function () use (&$page): bool {
            return cdp_evaluate($page, 'document.getElementById("effort-select").value==="high" && !document.getElementById("effort-select").disabled') === true;
        }), 'control recovers when live effort becomes available');
        foreach ([[390,844,true], [320,700,true], [1280,900,false]] as [$width,$height,$mobile]) {
            cdp_set_viewport($page,$width,$height,1,$mobile);
            cdp_call($page,'Runtime.evaluate',['expression'=>'new Promise(function(resolve){requestAnimationFrame(function(){requestAnimationFrame(resolve);});})','awaitPromise'=>true]);
            assert_equal(true, cdp_evaluate($page, '(function(){var s=document.querySelectorAll("#claude-session-controls select");return Math.abs(s[0].getBoundingClientRect().top-s[2].getBoundingClientRect().top)<1 && document.documentElement.scrollWidth<=innerWidth;})()'), 'three controls stay on one row without overflow at ' . $width);
            if (getenv('SESSIONEER_TEST_ARTIFACT_DIR')) file_put_contents(getenv('SESSIONEER_TEST_ARTIFACT_DIR').'/effort-'.$width.'.png',cdp_screenshot($page));
        }
        assert_equal([], cdp_drain_console_errors($page), 'effort changes emit no browser errors');
    }
    file_put_contents($state, json_encode(['effort' => null, 'available' => false, 'runtime' => 'tmux']));
    $unsupported = curl_request('GET', $url . '&tmux=1');
    assert_true(preg_match('/<select id="effort-select"[^>]* disabled/', $unsupported['body']) === 1, 'unsupported tmux control is disabled in SSR');
} finally {
    if ($page !== null) cdp_close_page($page);
    if ($browser !== null) cdp_shutdown($browser);
    proc_terminate($server);proc_close($server);stop_harness($harness,$socket);
    exec('rm -rf '.escapeshellarg($root));
}
test_exit();
