<?php
declare(strict_types=1);

/**
 * Render smoke for the UI (the redesigned dashboard shipped beside the
 * classic one): happy path against the canned fake agent (same fixture
 * test_ui_smoke.php uses, never touches tmux), the JSON action endpoint's
 * success and refusal paths, and the sad paths - an unreachable host agent,
 * a missing CSRF token, a wrong Host. Also proves the classic UI is gone
 * (404 on /classic and the old .php pages).
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/free_port.php';

const UI_CANNED_SESSION = 'cc-20260101-1200';
const UI_CANNED_BLOCKED_SESSION = 'cc-20260101-1300';

/** @return array{process: resource, port: int}|null */
function ui_start_server(string $agentSocket): ?array
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

function ui_csrf(string $html): string
{
    return preg_match('/id="ui-app" data-csrf="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-ui-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);
$server = ui_start_server($agentSocket);
$downServer = ui_start_server(sys_get_temp_dir() . '/sessioneer-test-ui-no-such-agent.sock');

if ($server === null || $downServer === null) {
    fwrite(STDERR, "dashboard: could not start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}

$base = "http://127.0.0.1:{$server['port']}";
$downBase = "http://127.0.0.1:{$downServer['port']}";

try {
    $jar = tempnam(sys_get_temp_dir(), 'sessioneer-ui-cookies');

    // --- happy path: page ---
    $page = curl_request('GET', "{$base}/", [], $jar);
    $body = $page['body'];
    assert_equal(200, $page['status'], 'GET /: 200');
    $old = curl_request('GET', "{$base}/v2/");
    assert_equal(302, $old['status'], 'GET /v2/: redirects');
    assert_equal('/', $old['headers']['location'] ?? null, 'GET /v2/: to the dashboard');
    assert_equal('/', curl_request('GET', "{$base}/v2")['headers']['location'] ?? null, 'GET /v2: to the dashboard');
    assert_equal('/session?session=x&jump_line=3', curl_request('GET', "{$base}/v2/session?session=x&jump_line=3")['headers']['location'] ?? null, 'GET /v2/session: to /session, query kept');
    assert_equal('/archived-session?agent_session_id=y', curl_request('GET', "{$base}/v2/archived-session?agent_session_id=y")['headers']['location'] ?? null, 'GET /v2/archived-session: to /archived-session');
    assert_contains('<title>Sessioneer</title>', $body, 'GET /: titled');
    assert_contains('name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"', $body, 'GET /: mobile viewport with safe-area support');
    assert_contains("localStorage.getItem('v2-theme')", $body, 'GET /: theme is resolved before first paint');
    assert_true(preg_match('#href="/css/ui\.css\?v=\d+"#', $body) === 1, 'GET /: stylesheet is cache-busted');
    assert_true(preg_match('#src="/js/ui\.js\?v=\d+"#', $body) === 1, 'GET /: script is cache-busted');
    assert_true(!str_contains($body, 'tailwind.css') && !str_contains($body, 'id="sessions-container"'), 'GET /: none of the classic page\'s own markup');
    assert_true(!str_contains($body, '/classic'), 'GET /: no link to a classic UI');
    assert_contains('ui-fold ui-health"', $body, 'GET /: the health section collapses');
    assert_contains('<details class="ui-fold-inner">', $body, 'GET /: a health group whose checks all pass is collapsed');
    assert_contains('<details class="ui-fold-inner" open>', $body, 'GET /: a group with a failing check is open');
    assert_contains('<details class="ui-card ui-card-flat ui-col ui-fold" open>', $body, 'GET /: the usage section collapses too, open by default');
    assert_contains('/js/ui-sheets.js', $body, 'GET /: sheets close on a tap outside or a swipe down');
    assert_contains('/js/ui-quota.js', $body, 'GET /: loads the shared usage renderer');
    assert_contains('Fix the login redirect bug', $body, 'GET /: canned session title shown');
    assert_contains('/home/user/www/demo-project', $body, 'GET /: canned workdir shown');
    assert_contains('href="/session?session=' . UI_CANNED_SESSION . '"', $body, 'GET /: card links to the session page');
    assert_true(!str_contains($body, 'href="/session.php?session='), 'GET /: no card links to the classic session page any more');
    assert_contains('Bare title', $body, 'GET /: bare process listed');
    assert_contains('data-act="kill-bare"', $body, 'GET /: bare process can be killed');
    assert_equal(1, substr_count($body, 'class="ui-thinking"'), 'GET /: thinking indicator on the working session only, not the blocked one');
    assert_contains('I&#039;ll clean up the temp directory now.', $body, 'GET /: last message preview shown on the working card');
    assert_equal(1, substr_count($body, 'ui-preview'), 'GET /: the blocked card shows its prompt instead of a preview');
    assert_contains('data-status="blocked"', $body, 'GET /: blocked card marked');
    assert_contains('ui-chip-id" title="Conversation ', $body, 'GET /: a live card shows its conversation id tail');
    assert_contains('Do you want to proceed?', $body, 'GET /: blocked prompt question shown');
    assert_contains('rm -rf /tmp/dashboard-example', $body, 'GET /: blocked prompt context shown');
    assert_equal(2, substr_count($body, 'data-act="answer"'), 'GET /: both prompt options are one-tap buttons');
    assert_contains('data-option="1" data-label="Yes"', $body, 'GET /: option buttons carry the number and label the answer endpoint re-validates');
    assert_contains('<b id="ui-n-all">2</b>', $body, 'GET /: total count');
    assert_contains('<b id="ui-n-blocked">1</b>', $body, 'GET /: needs-input count');
    foreach (['sessions', 'search', 'archived', 'status'] as $tab) {
        assert_contains('data-tab="' . $tab . '"', $body, "GET /: {$tab} tab present");
    }
    assert_contains('id="ui-new-dialog"', $body, 'GET /: new-session sheet present');
    assert_contains('name="agent"', $body, 'GET /: new-session agent picker present');
    assert_true(!str_contains($body, 'Headless (no terminal)') && !str_contains($body, 'ui-new-runtime'), 'GET /: no Runs-in picker, everything is headless');
    assert_true(!str_contains($body, '<option value="antigravity"'), 'GET /: Antigravity (terminal only) is not offered for new sessions');
    assert_contains('id="ui-new-prompt-wrap"', $body, 'GET /: first message field always present');
    assert_equal(1, substr_count($body, 'Terminal, read only'), 'GET /: only the terminal (non-headless) card is marked read only');
    assert_contains('data-act="switch" data-runtime="headless">Take over</button>', $body, 'GET /: a terminal Claude card offers Take over');
    assert_true(!str_contains($body, 'Move to terminal'), 'GET /: no way back to a terminal');
    assert_contains('data-act="take-over"', $body, 'GET /: bare process has a Take over button');
    assert_contains('id="ui-takeover-dialog"', $body, 'GET /: take-over picker sheet present');
    assert_contains('id="ui-settings-dialog"', $body, 'GET /: settings sheet present');
    assert_contains('Hide idle unstarred', $body, 'GET /: hide-idle setting kept from classic');
    assert_contains('Show worker sessions', $body, 'GET /: worker toggle kept from classic');
    assert_true(!str_contains($body, 'id="ui-push-interval"'), 'GET /: no push-interval control when the agent reports no push timer');
    assert_true(!str_contains($body, 'App hooks aren'), 'GET /: no hook banner when the agent reports hooks installed');

    foreach (['/css/ui.css', '/js/ui.js'] as $asset) {
        assert_equal(200, curl_request('GET', $base . $asset)['status'], "GET {$asset}: served as a static file");
    }

    // --- happy path: fragments ---
    $fragment = json_decode(curl_request('GET', "{$base}/fragment", [], $jar)['body'], true);
    assert_true(is_array($fragment) && ($fragment['ok'] ?? false) === true, 'GET /fragment: ok');
    assert_equal(['total' => 2, 'blocked' => 1, 'working' => 1, 'idle' => 0], $fragment['counts'] ?? null, 'GET /fragment: counts by status');
    assert_contains('Fix the login redirect bug', (string)($fragment['sessions_html'] ?? ''), 'GET /fragment: cards rendered');
    assert_contains('Bare title', (string)($fragment['bare_html'] ?? ''), 'GET /fragment: bare list rendered');

    $archived = json_decode(curl_request('GET', "{$base}/archived", [], $jar)['body'], true);
    assert_true(is_array($archived) && ($archived['ok'] ?? false) === true, 'GET /archived: ok');
    assert_contains('Refactor the old widget', (string)($archived['archived_html'] ?? ''), 'GET /archived: archived row rendered');
    assert_contains('data-act="resume"', (string)($archived['archived_html'] ?? ''), 'GET /archived: row can be resumed');
    assert_true(!str_contains((string)($archived['archived_html'] ?? ''), 'data-runtime'), 'GET /archived: one Resume per row (it implies headless), no second Headless button');

    // --- action endpoint ---
    $csrf = ui_csrf($body);
    assert_true($csrf !== '', 'GET /: page embeds a CSRF token for its scripts');
    $post = static fn (string $fields): array => curl_request('POST', "{$base}/action", ['-d', 'csrf_token=' . urlencode($csrf) . '&' . $fields], $jar);

    $kill = json_decode($post('action=kill&session=' . UI_CANNED_SESSION)['body'], true);
    assert_true(($kill['ok'] ?? false) === true && !isset($kill['redirect']), 'POST /action kill: ok, no redirect');

    $badKill = json_decode($post('action=kill&session=cc-not-real')['body'], true);
    assert_true(($badKill['ok'] ?? null) === false && str_contains((string)$badKill['message'], 'Rejected'), 'POST /action kill of an unknown session: refused with the agent\'s reason');

    $new = json_decode($post('action=new&agent=claude&runtime=headless&workdir=' . urlencode('/home/user/www/demo-project'))['body'], true);
    assert_equal('/session?session=cc-fake-headless', $new['redirect'] ?? null, 'POST /action new: redirects to the new session and passes the runtime on');

    $dir = urlencode('/home/user/www/demo-project');
    $withPrompt = json_decode($post('action=new&agent=claude&runtime=headless&prompt=' . urlencode('Run the tests') . '&workdir=' . $dir)['body'], true);
    assert_true(($withPrompt['ok'] ?? false) === true && !isset($withPrompt['notice']), 'POST /action new with a first message (headless): created and the message sent');
    $viaCodex = json_decode($post('action=new&agent=codex&prompt=' . urlencode('hi') . '&workdir=' . $dir)['body'], true);
    assert_true(isset($viaCodex['notice']) && str_contains($viaCodex['notice'], 'first message was not sent'), 'POST /action new: a refused first message still opens the session, with a notice');
    $refused = json_decode($post('action=new&agent=antigravity&prompt=' . urlencode('hi') . '&workdir=' . $dir)['body'], true);
    assert_true(($refused['ok'] ?? null) === false && str_contains((string)$refused['message'], 'Antigravity'), 'POST /action new: Antigravity is refused before creating anything');
    $forced = json_decode($post('action=new&agent=claude&runtime=tmux&workdir=' . $dir)['body'], true);
    assert_equal('/session?session=cc-fake-headless', $forced['redirect'] ?? null, 'POST /action new: a requested terminal runtime is ignored, the session is headless');
    $blank = json_decode($post('action=new&agent=claude&prompt=%20%20&workdir=' . $dir)['body'], true);
    assert_true(($blank['ok'] ?? false) === true, 'POST /action new: a blank first message is ignored');
    $resume = json_decode($post('action=resume&agent_session_id=99999999-8888-4777-a666-555555555555&workdir=' . urlencode('/home/user/www/old-project') . '&profile=work')['body'], true);
    assert_equal('/session?session=claude-headless-20260101-1700', $resume['redirect'] ?? null, 'POST /action resume (headless): redirects to the resumed session');

    $switch = json_decode($post('action=switch_runtime&runtime=headless&session=' . UI_CANNED_SESSION)['body'], true);
    assert_equal('/session?session=claude-headless-20260101-1700', $switch['redirect'] ?? null, 'POST /action switch_runtime: redirects to the session carrying the conversation');

    $takeOver = json_decode($post('action=take_over&pid=54321')['body'], true);
    assert_true(($takeOver['needs_choice'] ?? false) === true && count($takeOver['candidates'] ?? []) === 1 && ($takeOver['workdir'] ?? '') === '/home/user/www/some-other-project', 'POST /action take_over: an unidentified process comes back with its candidates');
    $confirmed = json_decode($post('action=take_over_confirm&pid=54321&workdir=' . urlencode('/home/user/www/some-other-project') . '&agent_session_id=' . '99999999-8888-4777-a666-555555555555')['body'], true);
    assert_true(($confirmed['ok'] ?? false) === true && str_starts_with((string)($confirmed['redirect'] ?? ''), '/session?session='), 'POST /action take_over_confirm: redirects to the new session');
    $noPick = json_decode($post('action=take_over_confirm&pid=54321&agent_session_id=')['body'], true);
    assert_true(($noPick['ok'] ?? null) === false && !isset($noPick['redirect']), 'POST /action take_over_confirm without a conversation: refused before reaching the agent');
    $badTake = json_decode($post('action=take_over&pid=1')['body'], true);
    assert_true(($badTake['ok'] ?? null) === false, 'POST /action take_over of an unknown pid: refused');

    $killBare = json_decode($post('action=kill_bare&pid=54321')['body'], true);
    assert_true(($killBare['ok'] ?? false) === true, 'POST /action kill_bare: ok');

    $unknown = json_decode($post('action=rm_rf')['body'], true);
    assert_equal(['ok' => false, 'message' => 'Unknown action'], $unknown, 'POST /action with an unknown action: refused');

    // --- the answer button posts to the existing classic endpoint, which still works ---
    $answer = json_decode(curl_request('POST', "{$base}/answer_prompt.php", ['-d', 'csrf_token=' . urlencode($csrf) . '&session=' . UI_CANNED_SESSION . '&option=1&expected_label=Yes'], $jar)['body'], true);
    assert_true(($answer['ok'] ?? false) === true, 'answer buttons reuse POST /answer_prompt.php');

    // --- sad paths: guards ---
    $noCsrf = curl_request('POST', "{$base}/action", ['-d', 'action=kill&session=' . UI_CANNED_SESSION], $jar);
    assert_equal(403, $noCsrf['status'], 'POST /action without a CSRF token: 403');
    $wrongCsrf = curl_request('POST', "{$base}/action", ['-d', 'csrf_token=nope&action=cleanup'], $jar);
    assert_equal(403, $wrongCsrf['status'], 'POST /action with a wrong CSRF token: 403');
    $crossOrigin = curl_request('POST', "{$base}/action", ['-H', 'Origin: http://attacker.example', '-d', 'csrf_token=' . urlencode($csrf) . '&action=cleanup'], $jar);
    assert_equal(403, $crossOrigin['status'], 'POST /action from another origin: 403');
    assert_equal(405, curl_request('GET', "{$base}/action", [], $jar)['status'], 'GET /action: 405');
    assert_equal(421, curl_request('GET', "{$base}/", ['-H', 'Host: attacker.example'])['status'], 'GET / with an unlisted Host: 421');
    assert_equal(404, curl_request('GET', "{$base}/v2/nope")['status'], 'GET //nope: 404');

    // --- sad path: the host agent is down ---
    $down = curl_request('GET', "{$downBase}/");
    assert_equal(200, $down['status'], 'GET / with the agent down: still renders');
    assert_contains('Cannot reach the host agent', $down['body'], 'GET / with the agent down: says so');
    assert_contains('systemctl --user status sessioneer-agent.socket', $down['body'], 'GET / with the agent down: names the fix');
    assert_true(!str_contains($down['body'], 'data-session='), 'GET / with the agent down: no session cards');
    assert_true(!str_contains($down['body'], 'id="ui-new"'), 'GET / with the agent down: no New button to a dead agent');
    $downFragment = json_decode(curl_request('GET', "{$downBase}/fragment")['body'], true);
    assert_true(($downFragment['ok'] ?? null) === false && is_string($downFragment['message'] ?? null), 'GET /fragment with the agent down: ok=false with a message');
    $downArchived = json_decode(curl_request('GET', "{$downBase}/archived")['body'], true);
    assert_true(($downArchived['ok'] ?? null) === false, 'GET /archived with the agent down: ok=false');

    // --- the classic UI is gone ---
    assert_equal(404, curl_request('GET', "{$base}/classic", [], $jar)['status'], 'GET /classic: no classic UI any more');
    assert_equal(404, curl_request('GET', "{$base}/session.php?session=" . UI_CANNED_SESSION, [], $jar)['status'], 'GET /session.php: classic page gone');
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
