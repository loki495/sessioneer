<?php
declare(strict_types=1);

/**
 * The /v2 session page: its server-rendered shell and bootstrap (against the canned fake agent, never
 * tmux), the /v2/history endpoint, the classic endpoints the page drives (CSRF taken from the
 * bootstrap), the sad paths (unknown session, agent down, bad CSRF, wrong Host), and that the page's
 * scripts stay plain ES5 and parse. The browser behaviour is covered by test_v2_session_browser.php.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/free_port.php';

const V2S_SESSION = 'cc-20260101-1200';
const V2S_NEW_SESSION = 'cc-20260101-1600';
const V2S_ARCHIVED = '99999999-8888-4777-a666-555555555555';

/** @return array{process: resource, port: int}|null */
function v2s_start_server(string $agentSocket): ?array
{
    $port = test_free_port();
    $process = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket])
    );

    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if ($conn !== false) {
            fclose($conn);

            return ['process' => $process, 'port' => $port];
        }

        usleep(50000);
    }

    proc_terminate($process);
    proc_close($process);

    return null;
}

/** The JSON the page hands to its script. */
function v2s_bootstrap(string $html): ?array
{
    return preg_match('/window\.V2S = (\{.*?\});<\/script>/s', $html, $m) === 1 ? json_decode($m[1], true) : null;
}

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-v2s-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);
$server = v2s_start_server($agentSocket);
$downServer = v2s_start_server(sys_get_temp_dir() . '/sessioneer-test-v2s-no-such-agent.sock');

if ($server === null || $downServer === null) {
    fwrite(STDERR, "v2 session: could not start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}

$base = "http://127.0.0.1:{$server['port']}";
$downBase = "http://127.0.0.1:{$downServer['port']}";

try {
    $jar = tempnam(sys_get_temp_dir(), 'sessioneer-v2s-cookies');

    // --- happy path: the page ---
    $page = curl_request('GET', "{$base}/session?session=" . V2S_SESSION, [], $jar);
    $body = $page['body'];
    assert_equal(200, $page['status'], 'GET /session: 200');
    assert_contains('<title>Fix the login redirect bug</title>', $body, 'page: titled with the session title');
    assert_contains('name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"', $body, 'page: mobile viewport with safe-area support, pinch zoom left to the browser');
    assert_true(preg_match('#href="/css/v2-session\.css\?v=\d+"#', $body) === 1, 'page: session stylesheet is cache-busted');
    foreach (['v2-session', 'v2-session-sheets', 'markdown'] as $script) {
        assert_true(preg_match('#src="/js/' . $script . '\.js\?v=\d+"#', $body) === 1, "page: {$script}.js is loaded cache-busted");
    }
    assert_true(!str_contains($body, 'tailwind.css'), 'page: none of the classic stylesheet');
    foreach (['v2s-list', 'v2s-prompt', 'v2s-thinking', 'v2s-send', 'v2s-stop', 'v2s-text', 'v2s-attach-btn', 'v2s-prev', 'v2s-next', 'v2s-bottom', 'v2s-load-older', 'v2s-load-until-user', 'v2s-viewer', 'v2s-fullscreen', 'v2s-search-input', 'v2s-opt-reasoning', 'v2s-opt-subagent', 'v2s-opt-confirm', 'v2s-opt-poll', 'v2s-todo-file', 'v2s-uploads', 'v2s-plan-files'] as $id) {
        assert_contains('id="' . $id . '"', $body, "page: #{$id} present");
    }
    foreach (['v2s-controls', 'v2s-search', 'v2s-info', 'v2s-sessions', 'v2s-quota'] as $sheet) {
        assert_contains('<dialog class="v2-sheet" id="' . $sheet . '">', $body, "page: {$sheet} is a native dialog sheet");
    }
    assert_contains('href="/session.php?session=' . V2S_SESSION . '" data-classic-link>Open in classic', $body, 'page: keeps a link to the classic session page');
    assert_contains('href="/" aria-label="All sessions"', $body, 'page: back to the v2 dashboard');
    assert_contains('font-size', (string)file_get_contents(dirname(__DIR__) . '/public/css/v2.css'), 'sanity: v2.css readable');

    $boot = v2s_bootstrap($body);
    assert_true(is_array($boot), 'page: bootstrap JSON parses');
    assert_equal(V2S_SESSION, $boot['session'] ?? null, 'bootstrap: session name');
    assert_true(is_string($boot['csrfToken'] ?? null) && $boot['csrfToken'] !== '', 'bootstrap: CSRF token for the page\'s own POSTs');
    assert_equal('Do you want to proceed?', $boot['detail']['blocked_reason'] ?? null, 'bootstrap: carries the live detail, including the blocked prompt');
    assert_equal(3, count($boot['detail']['prompt_options'] ?? []), 'bootstrap: carries the prompt options');
    assert_true(array_key_exists('jumpLine', $boot) && $boot['jumpLine'] === null, 'bootstrap: no jump line by default');
    $scriptTag = substr($body, (int)strpos($body, 'window.V2S = '), (int)strpos($body, '</script>', (int)strpos($body, 'window.V2S = ')) - (int)strpos($body, 'window.V2S = '));
    assert_true(!str_contains($scriptTag, '<') && !str_contains($scriptTag, '&'), 'bootstrap: < and & are hex-escaped so transcript text can never close the script tag');

    $jump = curl_request('GET', "{$base}/session?session=" . V2S_SESSION . '&jump_line=7', [], $jar);
    assert_equal(7, (v2s_bootstrap($jump['body']) ?? [])['jumpLine'] ?? null, 'page: jump_line is handed to the script');
    $badJump = curl_request('GET', "{$base}/session?session=" . V2S_SESSION . '&jump_line=-3', [], $jar);
    assert_true(($b = v2s_bootstrap($badJump['body'])) !== null && $b['jumpLine'] === null, 'page: a negative jump_line is ignored');

    $brandNew = curl_request('GET', "{$base}/session?session=" . V2S_NEW_SESSION, [], $jar);
    assert_equal(200, $brandNew['status'], 'page: a session with no transcript yet still renders');
    assert_contains('id="v2s-empty"', $brandNew['body'], 'page: and has the "nothing recorded" placeholder to show');

    // --- /v2/history ---
    $history = json_decode(curl_request('GET', "{$base}/v2/history?session=" . V2S_SESSION . '&limit=30', [], $jar)['body'], true);
    assert_true(($history['ok'] ?? false) === true && count($history['entries'] ?? []) > 5, 'GET /v2/history: entries returned');
    assert_equal(1, $history['next_before'] ?? null, 'GET /v2/history: paging cursor passed through');
    $since = json_decode(curl_request('GET', "{$base}/v2/history?session=" . V2S_SESSION . '&after=16', [], $jar)['body'], true);
    assert_equal([17, 18], array_column($since['entries'] ?? [], 'line'), 'GET /v2/history?after: only newer entries');
    $thinking = json_decode(curl_request('GET', "{$base}/v2/history?session=" . V2S_SESSION . '&include_thinking=1', [], $jar)['body'], true);
    assert_true(($thinking['ok'] ?? false) === true, 'GET /v2/history?include_thinking=1: accepted');
    $unknown = json_decode(curl_request('GET', "{$base}/v2/history?session=cc-not-real", [], $jar)['body'], true);
    assert_true(($unknown['ok'] ?? null) === false && is_string($unknown['message'] ?? null), 'GET /v2/history for an unknown session: ok=false with a reason');
    assert_contains('no-store', implode(' ', (array)($page['headers']['cache-control'] ?? '')) . curl_request('GET', "{$base}/v2/history?session=" . V2S_SESSION, [], $jar)['headers']['cache-control'], 'GET /v2/history: never cached (a stale poll looks like a stuck page on iOS)');

    // --- the classic endpoints the page drives, with the bootstrap's CSRF token ---
    $csrf = urlencode((string)$boot['csrfToken']);
    $post = static fn (string $path, string $fields): array => json_decode(curl_request('POST', "{$base}{$path}", ['-d', "csrf_token={$csrf}&{$fields}"], $jar)['body'], true) ?? [];

    assert_true(($post('/session_send.php', 'session=' . V2S_SESSION . '&message=hello')['ok'] ?? false) === true, 'footer send: ok');
    assert_true(($post('/session_send.php', 'session=' . V2S_SESSION . '&message=')['ok'] ?? null) === false, 'footer send: an empty message is refused by the agent');
    assert_true(($post('/session_mode.php', 'session=' . V2S_SESSION . '&mode=plan')['ok'] ?? false) === true, 'controls: permission mode change');
    assert_true(($post('/session_mode.php', 'session=' . V2S_SESSION . '&mode=yolo')['ok'] ?? null) === false, 'controls: an unknown mode is refused');
    assert_true(($post('/session_escape.php', 'session=' . V2S_SESSION)['ok'] ?? false) === true, 'footer stop: interrupt');
    assert_true(($post('/answer_prompt.php', 'session=' . V2S_SESSION . '&option=1&expected_label=Yes')['ok'] ?? false) === true, 'prompt: one-tap answer');
    assert_true(($post('/answer_prompt.php', 'session=' . V2S_SESSION . '&option=3&text=use+sqlite')['ok'] ?? false) === true, 'prompt: free-text reply');
    assert_true(($post('/answer_prompt.php', 'session=' . V2S_SESSION . '&option=3&text=')['ok'] ?? null) === false, 'prompt: an option that is no longer offered is refused');
    assert_equal(['ok' => true, 'matches' => [['line' => 2, 'snippet' => 'Fix the login redirect bug', 'role' => 'user', 'kind' => 'text']]], json_decode(curl_request('GET', "{$base}/session_search.php?session=" . V2S_SESSION . '&q=redirect', [], $jar)['body'], true), 'search: in-session hit has the line the page jumps to');
    $plan = json_decode(curl_request('GET', "{$base}/session_plan_files.php?session=" . V2S_SESSION, [], $jar)['body'], true);
    assert_equal(['PLAN.md', 'handoff-2026-08-08.md'], array_column($plan['files'] ?? [], 'name'), 'files: plan/handoff list');

    // --- archived-session page ---
    $arch = curl_request('GET', "{$base}/archived-session?agent_session_id=" . V2S_ARCHIVED . '&jump_line=5', [], $jar);
    assert_equal(200, $arch['status'], 'GET /archived-session: 200');
    assert_contains('<title>Refactor the old widget</title>', $arch['body'], 'archived page: titled with the conversation title');
    $archBoot = v2s_bootstrap($arch['body']);
    assert_equal(true, $archBoot['archived'] ?? null, 'archived page: bootstrap says archived');
    assert_equal(V2S_ARCHIVED, $archBoot['session'] ?? null, 'archived page: the conversation id is the session key');
    assert_equal(5, $archBoot['jumpLine'] ?? null, 'archived page: jump_line is handed to the script');
    assert_true(preg_match('#src="/js/v2-archived\.js\?v=\d+"#', $arch['body']) === 1, 'archived page: loads v2-archived.js');
    assert_true(!str_contains($arch['body'], 'v2-session-sheets.js') && !str_contains($arch['body'], 'v2s-controls'), 'archived page: none of the live-only sheets');
    assert_contains('/archived-session?agent_session_id=' . V2S_ARCHIVED, $arch['body'], 'archived page: "Back to latest" stays on the archived view');
    assert_true(!str_contains($arch['body'], 'tailwind.css') && !str_contains($arch['body'], 'data-classic-link'), 'archived page: no classic stylesheet or link');
    $archHistory = json_decode(curl_request('GET', "{$base}/v2/archived-history?agent_session_id=" . V2S_ARCHIVED . '&limit=30', [], $jar)['body'], true);
    assert_true(($archHistory['ok'] ?? false) === true && count($archHistory['entries'] ?? []) > 5, 'GET /v2/archived-history: entries returned');
    assert_equal(true, $archHistory['has_more'] ?? null, 'GET /v2/archived-history: paging flags passed through');
    assert_equal(false, json_decode(curl_request('GET', "{$base}/v2/archived-history?agent_session_id=nope", [], $jar)['body'], true)['ok'] ?? null, 'archived history for an unknown conversation: ok=false');
    $archMissing = curl_request('GET', "{$base}/archived-session?agent_session_id=nope", [], $jar);
    assert_contains('Session not found.', $archMissing['body'], 'archived page for an unknown conversation: says so');
    assert_true(!str_contains($archMissing['body'], 'window.V2S'), 'archived page for an unknown conversation: no script boots');
    $archNone = curl_request('GET', "{$base}/archived-session", [], $jar);
    assert_equal(303, $archNone['status'], 'archived page with no id: redirects');
    assert_equal('/', $archNone['headers']['location'] ?? null, 'archived page with no id: to the v2 dashboard');
    assert_contains('Session not found.', curl_request('GET', "{$downBase}/archived-session?agent_session_id=" . V2S_ARCHIVED)['body'], 'archived page with the agent down: still renders a message');
    $dashArchived = json_decode(curl_request('GET', "{$base}/v2/archived", [], $jar)['body'], true);
    assert_contains('href="/archived-session?agent_session_id=' . V2S_ARCHIVED . '"', $dashArchived['archived_html'] ?? '', 'archived list rows open the v2 archived page');
    assert_true(!str_contains($dashArchived['archived_html'] ?? '', '/archived_session.php'), 'archived list rows no longer link to the classic page');
    $v2js = (string)file_get_contents(dirname(__DIR__) . '/public/js/v2-session-sheets.js') . (string)file_get_contents(dirname(__DIR__) . '/public/js/v2.js');
    assert_true(!str_contains($v2js, '/archived_session.php'), 'search results: archived hits link to the v2 page');

    // --- sad paths ---
    $noCsrf = curl_request('POST', "{$base}/session_send.php", ['-d', 'session=' . V2S_SESSION . '&message=hi'], $jar);
    assert_equal(403, $noCsrf['status'], 'footer send without a CSRF token: 403');
    $crossOrigin = curl_request('POST', "{$base}/session_send.php", ['-H', 'Origin: http://attacker.example', '-d', "csrf_token={$csrf}&session=" . V2S_SESSION . '&message=hi'], $jar);
    assert_equal(403, $crossOrigin['status'], 'footer send from another origin: 403');
    assert_equal(421, curl_request('GET', "{$base}/session?session=" . V2S_SESSION, ['-H', 'Host: attacker.example'])['status'], 'page with an unlisted Host: 421');
    assert_equal(404, curl_request('POST', "{$base}/session", ['-d', 'x=1'])['status'], 'POST to the page is not a route');

    $none = curl_request('GET', "{$base}/session", [], $jar);
    assert_equal(303, $none['status'], 'page with no session: redirects');
    assert_equal('/', $none['headers']['location'] ?? null, 'page with no session: to the v2 dashboard');

    $missing = curl_request('GET', "{$base}/session?session=cc-not-real", [], $jar);
    assert_equal(200, $missing['status'], 'page for an unknown session: renders a message, not an error page');
    assert_contains('Session not found.', $missing['body'], 'page for an unknown session: says so');
    assert_contains('href="/"', $missing['body'], 'page for an unknown session: way back to the dashboard');
    assert_true(!str_contains($missing['body'], 'window.V2S'), 'page for an unknown session: no script boots against nothing');

    $down = curl_request('GET', "{$downBase}/session?session=" . V2S_SESSION);
    assert_equal(200, $down['status'], 'page with the agent down: still renders');
    assert_contains('Session not found.', $down['body'], 'page with the agent down: says it could not load the session');
    $downHistory = json_decode(curl_request('GET', "{$downBase}/v2/history?session=" . V2S_SESSION)['body'], true);
    assert_true(($downHistory['ok'] ?? null) === false, 'history with the agent down: ok=false');

    // --- the classic page is untouched ---
    $classic = curl_request('GET', "{$base}/session.php?session=" . V2S_SESSION, [], $jar);
    assert_equal(200, $classic['status'], 'classic session page still renders');
    assert_contains('id="history-list"', $classic['body'], 'classic session page: its own markup');
    assert_true(!str_contains($classic['body'], 'v2-session'), 'classic session page: loads none of the v2 files');

    // --- scripts: plain ES5 (no transpiler, mobile Safari), and they parse ---
    foreach (['v2-session.js', 'v2-session-sheets.js', 'v2-archived.js'] as $file) {
        $path = dirname(__DIR__) . '/public/js/' . $file;
        $code = (string)file_get_contents($path);
        $stripped = preg_replace(['#/\*.*?\*/#s', '#(?<![:\'"\\\\])//[^\n]*#'], '', $code) ?? $code;
        assert_equal(0, preg_match('/=>|`|\blet\s|\bconst\s|\bclass\s|\.\.\.\w/', $stripped), "{$file}: no ES6+ syntax outside comments");
        assert_equal(200, curl_request('GET', $base . '/js/' . $file)['status'], "GET /js/{$file}: served as a static file");

        if (trim((string)shell_exec('command -v node')) !== '') {
            exec('node --check ' . escapeshellarg($path) . ' 2>&1', $out, $code2);
            assert_equal(0, $code2, "{$file}: parses (node --check)");
        }
    }
    assert_equal(200, curl_request('GET', $base . '/css/v2-session.css')['status'], 'GET /css/v2-session.css: served as a static file');
} finally {
    foreach ([$server, $downServer] as $s) {
        if ($s !== null) {
            proc_terminate($s['process']);
            proc_close($s['process']);
        }
    }

    stop_harness($agentHarness, $agentSocket);
    if (isset($jar)) {
        @unlink($jar);
    }
}

test_exit();
