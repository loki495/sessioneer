<?php
declare(strict_types=1);

/**
 * The web side of listing and cancelling scheduled wake-ups, against a fake host agent
 * (tests/fixtures/fake_wake_ui_agent.php; never a real `at`): GET /wakes (list JSON and its HTML
 * rows: escaping, the time attributes, the Cancel control, the compact variant), GET /wakes?job=N (the
 * full prompt), POST /action wake_cancel (CSRF, no bypass, the exact request sent to the agent), the
 * unreachable agent, the exact row markup of the Contract addendum (time[data-at], state chip, session
 * links, data-wake-show, data-wake-cancel, pre.ui-wake-full, empty and truncated notes), the dashboard
 * skeleton and card chip, the session page container, and that the archived session page lists no
 * wake-ups. The browser behaviour is covered by test_ui_wakes_browser.php.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/free_port.php';

const WUI_ARCHIVED = '99999999-8888-4777-a666-555555555555';

/** @return array{process: resource, port: int}|null */
function wui_start_server(string $agentSocket): ?array
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

/** @return list<array<string, mixed>> */
function wui_requests(string $log): array
{
    return array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string)@file_get_contents($log)))));
}

/** @return list<array<string, mixed>> only the wake requests the agent saw */
function wui_wake_requests(string $log): array
{
    return array_values(array_filter(wui_requests($log), static fn (array $r): bool => str_starts_with((string)($r['action'] ?? ''), 'launch_wake')));
}

/** @return array<string, mixed> the last wake request the agent saw, or [] */
function wui_last(string $log): array
{
    $all = wui_wake_requests($log);

    return $all === [] ? [] : $all[count($all) - 1];
}

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-ui-wakes-agent.sock';
$log = sys_get_temp_dir() . '/sessioneer-test-ui-wakes.log';
@unlink($log);
putenv('FAKE_WAKE_UI_LOG=' . $log);
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/fake_wake_ui_agent.php'], $agentSocket);
$server = wui_start_server($agentSocket);
$downServer = wui_start_server(sys_get_temp_dir() . '/sessioneer-test-ui-wakes-no-such-agent.sock');

if ($server === null || $downServer === null) {
    fwrite(STDERR, "wakes: could not start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}

$base = "http://127.0.0.1:{$server['port']}";
$downBase = "http://127.0.0.1:{$downServer['port']}";
$jar = tempnam(sys_get_temp_dir(), 'sessioneer-wui-cookies');

try {
    $page = curl_request('GET', "{$base}/", [], $jar);
    preg_match('/id="ui-app" data-csrf="([^"]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    assert_true($csrf !== '', 'fixture: the dashboard embeds a CSRF token');
    $skeleton = '<details id="ui-wakes" class="ui-card ui-card-flat ui-col ui-fold" hidden><summary class="ui-section-title">Scheduled wake-ups (<span id="ui-wakes-n">0</span>)</summary><div id="ui-wakes-body" class="ui-stack"></div></details>';
    assert_contains($skeleton, $page['body'], 'GET /: the dashboard has the closed, hidden "Scheduled wake-ups" fold with an empty body');
    $bare = strpos($page['body'], 'id="ui-bare"');
    $fold = strpos($page['body'], 'id="ui-wakes"');
    assert_true($bare !== false && $fold !== false && $fold > $bare, 'GET /: the fold comes after #ui-bare');
    assert_equal(1, substr_count($page['body'], 'class="ui-chip ui-chip-wake"'), 'GET /: exactly one card carries the wake chip');
    assert_contains('<span class="ui-chip ui-chip-wake" data-wake-at="1794756600" data-wake-count="2" title="Next wake-up 2026-11-15 10:30 EST">&#9200; 2</span>', $page['body'], 'GET /: the card chip has the next time, the count and the tooltip');
    $fragmentJson = json_decode(curl_request('GET', "{$base}/fragment", [], $jar)['body'], true);
    assert_true(!str_contains(json_encode($fragmentJson), 'ui-wakes'), 'GET /fragment: never carries the wake fold');
    assert_contains('ui-chip-wake', (string)($fragmentJson['sessions_html'] ?? ''), 'GET /fragment: the card chip refreshes with the cards');

    $sessionPage = curl_request('GET', "{$base}/session?session=cc-20260101-1200", [], $jar)['body'];
    assert_true(preg_match('#id="sess-wake-form".*?</form>\s*<div id="sess-wakes" class="ui-col"></div>#s', $sessionPage) === 1, 'GET /session: #sess-wakes follows the schedule form');
    assert_true(!str_contains($sessionPage, 'id="ui-wakes"'), 'GET /session: no dashboard fold');

    $archivedBefore = count(wui_wake_requests($log));
    $archived = curl_request('GET', "{$base}/archived-session?agent_session_id=" . WUI_ARCHIVED, [], $jar);
    assert_equal(200, $archived['status'], 'GET /archived-session: renders');
    assert_true(!preg_match('/wake-ups?/i', $archived['body']), 'GET /archived-session: no text about wake-ups');
    assert_true(!str_contains($archived['body'], 'id="ui-wakes"') && !str_contains($archived['body'], 'id="sess-wakes"'), 'GET /archived-session: no #ui-wakes and no #sess-wakes');
    assert_equal($archivedBefore, count(wui_wake_requests($log)), 'GET /archived-session: asks the host agent for no wake-ups');
    assert_equal(404, curl_request('GET', "{$base}/v2/wakes", [], $jar)['status'], 'GET /v2/wakes: not routed');
    assert_equal(404, curl_request('POST', "{$base}/wakes", [], $jar)['status'], 'POST /wakes: not routed');

    $get = static function (string $query, string $url = '') use ($base, $jar): array {
        $r = curl_request('GET', ($url ?: $base) . '/wakes' . $query, [], $jar);

        return ['status' => $r['status'], 'json' => json_decode($r['body'], true), 'body' => $r['body']];
    };

    echo "GET /wakes\n";
    @unlink($log);
    $r = $get('');
    assert_equal(200, $r['status'], '200');
    assert_equal(true, $r['json']['ok'] ?? null, 'ok');
    assert_equal(3, $r['json']['count'] ?? null, 'count is the number of wakes');
    assert_true(is_string($r['json']['wakes_html'] ?? null) && $r['json']['wakes_html'] !== '', 'wakes_html is the rendered rows');
    assert_true(!isset($r['json']['truncated']), 'no truncated flag when the agent sent none');
    $html = (string)($r['json']['wakes_html'] ?? '');
    assert_contains('2026-11-15 10:30 EST', $html, 'the local time from the host agent is in the row (the tooltip)');
    assert_contains('1794756611', $html, 'the unix time is in the row, so the browser can localise it');
    foreach (['scheduled', 'running', 'overdue'] as $state) {
        assert_true(stripos($html, $state) !== false, "the {$state} state is shown");
    }
    assert_contains('scheduled later', $html, 'the prompt preview is shown');
    assert_contains('claude-headless-sched', $html, 'the session is shown when the host agent resolved one');
    assert_contains('<time data-at="1794756611" title="2026-11-15 10:30 EST">2026-11-15 10:30 EST</time>', $html, 'row 11: time[data-at] carries the unix time and the local time as title and text');
    assert_contains('<div class="ui-wake" data-wake-job="11" data-wake-state="scheduled">', $html, 'row 11: job and state attributes');
    assert_contains('<span class="ui-chip ui-wake-state ui-wake-state-scheduled">scheduled</span>', $html, 'row 11: the state chip');
    assert_contains('<span class="ui-chip ui-wake-state ui-wake-state-running">running</span>', $html, 'row 12: a running chip');
    assert_contains('<span class="ui-chip ui-wake-state ui-wake-state-overdue">overdue</span>', $html, 'row 15: an overdue chip');
    assert_contains('<a class="ui-inline-link ui-wake-session" href="/session?session=claude-headless-sched">claude-headless-sched</a>', $html, 'row 11: a live session links to its page');
    assert_contains('href="/archived-session?agent_session_id=conv-15"', $html, 'row 15: with no session the link goes to the archived page for the conversation');
    assert_contains('<p class="ui-wake-preview">scheduled later</p>', $html, 'row 11: the preview paragraph');
    assert_contains('<button type="button" class="ui-btn ui-btn-quiet" data-wake-show="11">Show full prompt</button>', $html, 'row 11: the Show full prompt button');
    assert_equal(3, substr_count($html, '<pre class="ui-wake-full" hidden></pre>'), 'every row has an empty hidden pre for the full prompt');
    assert_equal(3, substr_count($html, 'class="ui-wake"'), 'three rows');
    assert_true(!str_contains($html, 'ui-wake-truncated'), 'no truncated note');
    assert_true(strpos($html, 'time data-at') < strpos($html, 'ui-wake-state ') && strpos($html, 'ui-wake-state ') < strpos($html, 'ui-wake-session') && strpos($html, 'ui-wake-session') < strpos($html, 'ui-wake-preview'), 'the row parts are in the Contract order: time, state, session, preview');
    $sent = wui_wake_requests($log)[0] ?? [];
    assert_equal('launch_wake_list', $sent['action'] ?? null, 'the page asks the agent for launch_wake_list');
    assert_true($sent !== [] && !isset($sent['from']), 'the dashboard never sends from');
    assert_true($sent !== [] && !array_key_exists('target', $sent), 'no session query: no target');

    $r = $get('?session=');
    assert_true(wui_wake_requests($log) !== [] && !array_key_exists('target', wui_last($log)), 'an empty session query: the target is omitted');
    $r = $get('?session=only-scheduled');
    assert_equal('only-scheduled', wui_last($log)['target'] ?? null, 'the session query is passed on as the target');
    assert_equal(1, $r['json']['count'] ?? null, 'count 1');

    echo "GET /wakes: the Cancel control\n";
    $scheduled = (string)($get('?session=only-scheduled')['json']['wakes_html'] ?? '');
    $running = (string)($get('?session=only-running')['json']['wakes_html'] ?? '');
    assert_contains('<button type="button" class="ui-btn ui-btn-danger" data-wake-cancel="11">Cancel</button>', $scheduled, 'a scheduled wake-up has the [data-wake-cancel] button');
    assert_true(!str_contains($running, 'data-wake-cancel'), 'a running wake-up has no [data-wake-cancel]');
    assert_contains('<p class="ui-wake-preview">running now</p>', $running, 'but the running row is listed');
    assert_equal(['11', '15'], array_map('strval', (function (string $h): array { preg_match_all('/data-wake-cancel="(\d+)"/', $h, $m); return $m[1]; })($html)), 'in the mixed list only the scheduled and overdue rows can be cancelled');

    echo "GET /wakes: escaping\n";
    $hostile = (string)($get('?session=hostile')['json']['wakes_html'] ?? '');
    assert_true(!str_contains($hostile, '<script>alert(1)</script>'), 'a script tag in the preview is escaped');
    assert_contains('&lt;script&gt;', $hostile, 'as text');
    assert_true(!str_contains($hostile, '<b>bold</b>'), 'markup in the preview is escaped');
    assert_true(!preg_match('/onmouseover="alert/', $hostile), 'a quote in the preview cannot open an attribute');
    assert_contains('&quot; onmouseover=&quot;alert(2)&quot;', $hostile, 'quotes are escaped with ENT_QUOTES');

    echo "GET /wakes: compact hides the session column\n";
    $full = (string)($get('?session=with-session')['json']['wakes_html'] ?? '');
    $compact = (string)($get('?session=with-session&compact=1')['json']['wakes_html'] ?? '');
    assert_contains('claude-headless-uniquename9', $full, 'the full list shows the session');
    assert_true(!str_contains($compact, 'claude-headless-uniquename9') && !str_contains($compact, 'ui-wake-session'), 'compact=1 leaves the session out');
    assert_contains('<p class="ui-wake-preview">has a session</p>', $compact, 'but keeps the row');

    echo "GET /wakes: empty, truncated and failing\n";
    $r = $get('?session=empty');
    assert_equal([true, 0], [$r['json']['ok'] ?? null, $r['json']['count'] ?? null], 'an empty list: ok, count 0');
    assert_contains('<p class="ui-muted ui-small ui-wake-empty">No wake-ups scheduled.</p>', (string)($r['json']['wakes_html'] ?? ''), 'with the empty-state paragraph');
    $r = $get('?session=trunc');
    assert_equal(true, $r['json']['truncated'] ?? null, 'a truncated list passes the flag on');
    assert_contains('<p class="ui-muted ui-small ui-wake-truncated">Only the first 200 queued jobs were checked.</p>', (string)($r['json']['wakes_html'] ?? ''), 'and the rows are followed by the truncated note');
    $r = $get('?session=boom');
    assert_equal(false, $r['json']['ok'] ?? null, 'a host agent failure: ok false');
    assert_contains('Could not read the at queue: at is broken', (string)($r['json']['message'] ?? ''), 'with the agent\'s message');
    assert_true(!isset($r['json']['wakes_html']), 'and no rows');
    $r = $get('?session=missing');
    assert_equal(false, $r['json']['ok'] ?? null, 'an unknown session: ok false');
    assert_contains("No tracked session found for 'missing'", (string)($r['json']['message'] ?? ''), 'with the not-found message');
    $down = $get('', $downBase);
    assert_equal(false, $down['json']['ok'] ?? null, 'agent down: ok false');
    assert_true(is_string($down['json']['message'] ?? null) && $down['json']['message'] !== '', 'with a message');
    assert_equal(421, curl_request('GET', "{$base}/wakes", ['-H', 'Host: attacker.example'])['status'], 'an unlisted Host: 421');

    echo "GET /wakes?job=N: the full prompt\n";
    @unlink($log);
    $r = $get('?job=14');
    assert_equal(true, $r['json']['ok'] ?? null, 'ok');
    assert_equal("<script>alert(1)</script>\n\" onmouseover=\"alert(2)\" 'x' & \$HOME `date` \\ end\n--workdir", $r['json']['prompt'] ?? null, 'the prompt comes back byte-exact as JSON text');
    assert_equal(['launch_wake_show', 14], [wui_wake_requests($log)[0]['action'] ?? null, wui_wake_requests($log)[0]['job'] ?? null], 'the agent is asked for launch_wake_show with the job as a number');
    foreach (['a job that is gone' => ['?job=77', 'no longer exists'], 'an unrelated job' => ['?job=76', 'is not a Sessioneer wake-up']] as $label => [$query, $needle]) {
        $r = $get($query);
        assert_equal(false, $r['json']['ok'] ?? null, "{$label}: ok false");
        assert_contains($needle, (string)($r['json']['message'] ?? ''), "{$label}: the agent's reason");
        assert_true(!isset($r['json']['prompt']), "{$label}: no prompt");
    }
    $showsBefore = count(array_filter(wui_wake_requests($log), static fn (array $q): bool => $q['action'] === 'launch_wake_show'));
    foreach (['a word' => '?job=abc', 'zero' => '?job=0', 'negative' => '?job=-3', 'empty' => '?job=', 'a decimal' => '?job=1.5', 'trailing text' => '?job=11abc', 'a leading zero' => '?job=011', 'a plus sign' => '?job=%2B11', 'a space' => '?job=%2011'] as $label => $query) {
        $r = $get($query);
        assert_equal(false, $r['json']['ok'] ?? null, "{$label}: ok false");
        assert_equal('Needs an at job number', $r['json']['message'] ?? null, "{$label}: refused locally with the standard message");
        assert_true(!isset($r['json']['prompt']), "{$label}: no prompt");
    }
    assert_equal($showsBefore, count(array_filter(wui_wake_requests($log), static fn (array $q): bool => $q['action'] === 'launch_wake_show')), 'none of the malformed jobs reached the agent');

    echo "POST /action wake_cancel\n";
    @unlink($log);
    $post = static fn (string $fields, array $extra = []): array => curl_request('POST', "{$base}/action", array_merge($extra, ['-d', $fields]), $jar);
    $withCsrf = static fn (string $fields): string => 'csrf_token=' . urlencode($csrf) . '&' . $fields;

    assert_equal(403, $post('action=wake_cancel&job=11')['status'], 'no CSRF token: 403');
    assert_equal(403, $post('csrf_token=nope&action=wake_cancel&job=11')['status'], 'a wrong CSRF token: 403');
    assert_equal(403, $post($withCsrf('action=wake_cancel&job=11'), ['-H', 'Origin: http://attacker.example'])['status'], 'another origin: 403');
    assert_equal(405, curl_request('GET', "{$base}/action?action=wake_cancel&job=11", [], $jar)['status'], 'a GET is never a cancel: 405');
    assert_equal([], wui_wake_requests($log), 'none of those reached the host agent');

    $r = json_decode($post($withCsrf('action=wake_cancel&job=11'))['body'], true);
    assert_equal([true, 'Cancelled wake-up 11 (2026-11-15 10:30 EST)'], [$r['ok'] ?? null, $r['message'] ?? null], 'a cancel: ok with the agent\'s message');
    $sent = wui_wake_requests($log)[0] ?? [];
    assert_equal(['launch_wake_cancel', 11], [$sent['action'] ?? null, $sent['job'] ?? null], 'the agent gets launch_wake_cancel with the job as a number');
    assert_true($sent !== [] && !array_key_exists('from', $sent), 'and no from');

    foreach (['a running job' => ['12', 'is already running'], 'a gone job' => ['77', 'no longer exists'], 'an unrelated job' => ['76', 'is not a Sessioneer wake-up']] as $label => [$job, $needle]) {
        $r = json_decode($post($withCsrf('action=wake_cancel&job=' . $job))['body'], true);
        assert_equal(false, $r['ok'] ?? null, "{$label}: refused");
        assert_contains($needle, (string)($r['message'] ?? ''), "{$label}: with the agent's reason");
    }

    $before = count(wui_wake_requests($log));
    foreach (['abc', '0', '-2', '', '1.5', '11abc', '011', '%2B11'] as $badJob) {
        $r = json_decode($post($withCsrf('action=wake_cancel&job=' . $badJob))['body'], true);
        assert_equal(false, $r['ok'] ?? null, "job '{$badJob}': refused");
        assert_equal('Needs an at job number', $r['message'] ?? null, "job '{$badJob}': with the standard message");
    }
    $r = json_decode($post($withCsrf('action=wake_cancel'))['body'], true);
    assert_equal([false, 'Needs an at job number'], [$r['ok'] ?? null, $r['message'] ?? null], 'no job: refused with the standard message');
    assert_equal($before, count(wui_wake_requests($log)), 'no malformed job reached the agent at all');
    $unknown = json_decode($post($withCsrf('action=wake_cancel_all'))['body'], true);
    assert_equal(['ok' => false, 'message' => 'Unknown action'], $unknown, 'an unrelated action name is still unknown');
} finally {
    foreach ([$server, $downServer] as $s) {
        if ($s !== null) {
            proc_terminate($s['process']);
            proc_close($s['process']);
        }
    }

    stop_harness($agentHarness, $agentSocket);
    @unlink($jar);
    @unlink($log);
}

test_exit();
