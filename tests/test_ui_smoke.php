<?php
declare(strict_types=1);

/**
 * Boots PHP's built-in server serving public/ (with public/index.php as
 * the router-script argument, same as production - see docker-compose.yml)
 * against a canned fake-agent socket (tests/fixtures/canned_agent.php -
 * never touches tmux) and drives it with curl. Always runs standalone (no
 * MCP / IDE dependency - just php, curl, and optionally a headless browser
 * binary already on the host), per the requirement that tests/run.sh works
 * outside Claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';

// Must match CANNED_TEST_IMAGE_BASE64 in fixtures/canned_agent.php - that
// file runs as its own separate process (spawned per-connection by
// socket_harness.php), so its constants aren't reachable from here.
const CANNED_TEST_IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const CANNED_VAPID_PUBLIC_KEY = 'BAhRdSrCIQS6QqCKKxkfmfSQ_DyQk63-8zoSMWlb2PXjhuTym7Lxyboe7HSFwi79IJN7-wqbUbZmYR1CkLvXZSc';
const CANNED_ARCHIVED_CLAUDE_SESSION_ID = '99999999-8888-4777-a666-555555555555';
const CANNED_ARCHIVED_CLAUDE_PROFILE = 'work';
const CANNED_BLOCKED_AGENT_SESSION_ID = 'aaaaaaaa-1111-4111-8111-111111111111';
const CANNED_RESUMED_SESSION_NAME = 'cc-20260101-1400';
const CANNED_HEADLESS_RESUMED_SESSION_NAME = 'claude-headless-20260101-1700';
const CANNED_TAKEN_OVER_SESSION_NAME = 'cc-20260101-1500';
const CANNED_NEW_SESSION_NAME = 'cc-20260101-1600';

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-ui-agent.sock';
$agentHarness = start_harness(['php', __DIR__ . '/fixtures/canned_agent.php'], $agentSocket);

require_once __DIR__ . '/lib/free_port.php';
$port = test_free_port();
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), [
    'SESSIONEER_AGENT_SOCKET' => $agentSocket,
]);
$serverProcess = proc_open(
    [
        'php', '-S', "127.0.0.1:{$port}",
        '-t', dirname(__DIR__) . '/public',
        dirname(__DIR__) . '/public/index.php', // absolute - resolves relative to CWD, not -t
    ],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "ui smoke: failed to start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}
fclose($serverPipes[0]);

$ready = false;
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(50000);
}

if (!$ready) {
    fwrite(STDERR, "ui smoke: server on port {$port} never became ready\n");
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    exit(1);
}

try {
    $cookieJar = tempnam(sys_get_temp_dir(), 'sessioneer-test-cookies');

    // --- Host allowlist: a rebinding hostname is refused before any route runs ---
    $rebound = curl_request('GET', "{$baseUrl}/", ['-H', 'Host: attacker.example:' . $port]);
    assert_equal(421, $rebound['status'], 'GET / with an unlisted Host: 421');
    assert_contains('ALLOWED_HOSTS', $rebound['body'], 'GET / with an unlisted Host: explains the fix');
    assert_true(!str_contains($rebound['body'], 'Sessioneer'), 'GET / with an unlisted Host: no page or CSRF token leaks');
    $reboundPost = curl_request('POST', "{$baseUrl}/", ['-H', 'Host: attacker.example', '-H', 'Origin: http://attacker.example', '-d', 'action=kill&name=cc-20260101-1200']);
    assert_equal(421, $reboundPost['status'], 'POST with an unlisted Host and matching Origin: 421');

    // --- dashboard renders; the CSRF token comes from the session page's bootstrap ---
    $result = curl_request('GET', "{$baseUrl}/", [], $cookieJar);
    $dashboardBody = $result['body'];
    assert_equal(200, $result['status'], 'GET /: 200');
    assert_contains('Sessioneer', $result['body'], 'GET /: page title present');
    assert_contains('id="push-notify-btn"', $result['body'], 'GET /: push-notification "Notify me" control present when the canned agent reports VAPID configured');
    assert_contains(CANNED_VAPID_PUBLIC_KEY, $result['body'], 'GET /: the actual VAPID public key is embedded for the frontend subscribe flow');
    $csrfToken = extract_csrf_token(curl_request('GET', "{$baseUrl}/session?session=cc-20260101-1200", [], $cookieJar)['body']);
    assert_true($csrfToken !== null, 'GET /session: page bootstrap includes the csrf token');

    // --- quota.php: passes the canned agent's quota action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/quota.php");
    assert_equal(200, $result['status'], 'GET /quota.php: 200');
    $quotaBody = json_decode($result['body'], true);
    assert_true(is_array($quotaBody) && ($quotaBody['ok'] ?? false), 'GET /quota.php: response decodes as ok=true JSON');
    assert_equal(73, $quotaBody['quota']['session']['pct'] ?? null, 'GET /quota.php: canned session percentage passed through');
    assert_true(!isset($quotaBody['context']), 'GET /quota.php: no context key at top level without a ?session= param (NEW)');
    assert_true(isset($quotaBody['agents']), 'GET /quota.php: agents map now always present (NEW)');

    // --- quota.php?session=...: the ?session= query param reaches the agent
    // call (QuotaController::show() -> agent_call(['session' => ...]) ->
    // Sessions.php's dispatch -> QuotaService::get_quota($session)) and the
    // full agents map plus per-session context come back ---
    $result = curl_request('GET', "{$baseUrl}/quota.php?session=cc-20260101-1200");
    assert_equal(200, $result['status'], 'GET /quota.php?session=...: 200');
    $quotaWithContext = json_decode($result['body'], true);
    assert_equal(12, $quotaWithContext['context']['pct'] ?? null, 'GET /quota.php?session=...: canned context percentage at top level (NEW)');
    assert_equal(73, $quotaWithContext['quota']['session']['pct'] ?? null, 'GET /quota.php?session=...: account-wide session percentage still passed through alongside context');
    assert_true(isset($quotaWithContext['agents']), 'GET /quota.php?session=...: agents map now present on session requests too (NEW)');

    // --- browse.php: passes the canned agent's browse_dir action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/browse.php?path=" . urlencode('/home/user/www'));
    assert_equal(200, $result['status'], 'GET /browse.php: 200');
    $browseBody = json_decode($result['body'], true);
    assert_true(is_array($browseBody) && ($browseBody['ok'] ?? false), 'GET /browse.php: response decodes as ok=true JSON');
    assert_equal(['project-a', 'project-b'], $browseBody['dirs'] ?? null, 'GET /browse.php: canned dirs passed through');

    // --- sessions_list.php: passes the canned agent's list action through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/sessions_list.php");
    assert_equal(200, $result['status'], 'GET /sessions_list.php: 200');
    $sessionsListBody = json_decode($result['body'], true);
    assert_true(is_array($sessionsListBody) && ($sessionsListBody['ok'] ?? false), 'GET /sessions_list.php: response decodes as ok=true JSON');
    assert_equal(2, count($sessionsListBody['sessions'] ?? []), 'GET /sessions_list.php: canned sessions passed through');

    // --- session_detail.php/v2/history/sessions_list.php/quota.php now join the
    // session (not just AgentClient.php) - a tab left open just polling (no send/answer)
    // must keep its session, and the CSRF token it holds, alive rather than letting it
    // expire via PHP's session GC out from under a still-open page. Verified by polling
    // with an established session's cookie, then confirming that session's CSRF token is
    // still accepted afterwards - a session_start() that rotated or dropped the session
    // would break this. ---
    $pollCookieJar = tempnam(sys_get_temp_dir(), 'sessioneer-test-poll-cookies');
    $pollPage = curl_request('GET', "{$baseUrl}/session?session=cc-20260101-1200", [], $pollCookieJar);
    $pollCsrfToken = extract_csrf_token($pollPage['body']);
    assert_true($pollCsrfToken !== null, 'GET /session: page includes a csrf_token field (poll-keepalive setup)');

    foreach (['session_detail.php?session=cc-20260101-1200', 'v2/history?session=cc-20260101-1200', 'sessions_list.php', 'quota.php'] as $pollEndpoint) {
        $pollResult = curl_request('GET', "{$baseUrl}/{$pollEndpoint}", [], $pollCookieJar);
        assert_equal(200, $pollResult['status'], "GET /{$pollEndpoint} (poll, with session cookie): 200");

        // start_app_session() sets Cache-Control: private, max-age=60 by
        // default (tuned for session.php's own HTML page's bfcache
        // behavior) - each of these 4 polling endpoints must override that
        // with no-store, or a browser can
        // legally serve a stale cached response to this exact fetch() URL
        // for up to a minute, no matter how fast the page polls.
        assert_equal('no-store', $pollResult['headers']['cache-control'] ?? null, "GET /{$pollEndpoint}: Cache-Control: no-store (never a stale cached poll response)");
    }

    $answerAfterPolling = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=1&csrf_token=' . urlencode((string)$pollCsrfToken),
    ], $pollCookieJar);
    $answerAfterPollingBody = json_decode($answerAfterPolling['body'], true);
    assert_true(
        is_array($answerAfterPollingBody) && ($answerAfterPollingBody['ok'] ?? false),
        'POST /answer_prompt.php: the original CSRF token still works after polling the 4 read-only endpoints - session was kept alive, not dropped or rotated'
    );
    unlink($pollCookieJar);

    // --- uploaded_file_view.php / session_plan_file.php: same binary-
    // endpoint contract as session_attachment.php, but for the sidebar's
    // "Uploaded files"/"Plan/handoff files" glances - not immutable
    // (see Controller::stream_binary_result()'s own doc comment), so no
    // Cache-Control assertion here the way an attachment might get one. ---
    $uploadedFileViewResult = curl_request('GET', "{$baseUrl}/uploaded_file_view.php?session=cc-20260101-1200&filename=notes.txt", [], $cookieJar);
    assert_equal(200, $uploadedFileViewResult['status'], 'GET /uploaded_file_view.php: 200 for a real, matching session/filename');
    assert_equal('canned attachment bytes', $uploadedFileViewResult['body'], 'GET /uploaded_file_view.php: streams the real (canned) file bytes');
    assert_true(str_starts_with($uploadedFileViewResult['headers']['content-type'] ?? '', 'text/plain'), 'GET /uploaded_file_view.php: Content-Type reflects the file\'s real media_type');
    assert_contains('inline', $uploadedFileViewResult['headers']['content-disposition'] ?? '', 'GET /uploaded_file_view.php: a text file opens inline (viewable in a new tab), not forced to download');
    assert_contains('notes.txt', $uploadedFileViewResult['headers']['content-disposition'] ?? '', 'GET /uploaded_file_view.php: Content-Disposition carries the real filename');
    $wrongUploadedFileViewResult = curl_request('GET', "{$baseUrl}/uploaded_file_view.php?session=cc-20260101-1200&filename=not-a-real-file.jpg", [], $cookieJar);
    assert_equal(404, $wrongUploadedFileViewResult['status'], 'GET /uploaded_file_view.php: an unrecognized filename -> 404, not a silent empty body');

    $planFileContentResult = curl_request('GET', "{$baseUrl}/session_plan_file.php?session=cc-20260101-1200&filename=PLAN.md", [], $cookieJar);
    assert_equal(200, $planFileContentResult['status'], 'GET /session_plan_file.php: 200 for a real, matching session/filename');
    assert_equal('canned attachment bytes', $planFileContentResult['body'], 'GET /session_plan_file.php: streams the real (canned) file bytes');
    assert_true(str_starts_with($planFileContentResult['headers']['content-type'] ?? '', 'text/markdown'), 'GET /session_plan_file.php: Content-Type reflects the file\'s real media_type');
    assert_contains('inline', $planFileContentResult['headers']['content-disposition'] ?? '', 'GET /session_plan_file.php: a markdown file opens inline (viewable in a new tab), not forced to download');
    $wrongPlanFileContentResult = curl_request('GET', "{$baseUrl}/session_plan_file.php?session=cc-20260101-1200&filename=not-a-real-file.md", [], $cookieJar);
    assert_equal(404, $wrongPlanFileContentResult['status'], 'GET /session_plan_file.php: an unrecognized filename -> 404, not a silent empty body');

    // --- answer_prompt.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/answer_prompt.php?session=cc-20260101-1200&option=1");
    assert_equal(405, $result['status'], 'GET /answer_prompt.php: 405 (POST required)');

    // --- answer_prompt.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=cc-20260101-1200&option=1&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /answer_prompt.php with a wrong csrf_token: 403');

    // --- answer_prompt.php: valid CSRF -> canned agent accepts option 1 for the canned session, returns JSON (no redirect) ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=1&csrf_token=' . urlencode((string)$csrfToken),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /answer_prompt.php with valid CSRF: 200 (JSON, not a redirect)');
    $answerBody = json_decode($result['body'], true);
    assert_true(is_array($answerBody) && ($answerBody['ok'] ?? false), 'POST /answer_prompt.php: canned agent accepts the option, response decodes as ok=true JSON');

    // --- answer_prompt.php: canned agent rejects an option it isn't currently offering ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=99&csrf_token=' . urlencode((string)$csrfToken),
    ], $cookieJar);
    $answerRejectBody = json_decode($result['body'], true);
    assert_equal(false, $answerRejectBody['ok'] ?? null, 'POST /answer_prompt.php: canned agent rejects an option not currently offered');

    // --- answer_prompt.php: a non-empty "text" field routes to the free-text (answer_prompt_with_text) action instead ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=3&text=' . urlencode('My custom reply') . '&csrf_token=' . urlencode((string)$csrfToken),
    ], $cookieJar);
    $freetextBody = json_decode($result['body'], true);
    assert_true(is_array($freetextBody) && ($freetextBody['ok'] ?? false), 'POST /answer_prompt.php: canned agent accepts a free-text reply, response decodes as ok=true JSON');

    // --- answer_prompt.php: a whitespace-only "text" field is treated as empty, falling back to the plain
    // answer_prompt path - option 3 isn't option 1, so the canned agent rejects it there too ---
    $result = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&option=3&text=' . urlencode('   ') . '&csrf_token=' . urlencode((string)$csrfToken),
    ], $cookieJar);
    $emptyFreetextBody = json_decode($result['body'], true);
    assert_equal(false, $emptyFreetextBody['ok'] ?? null, 'POST /answer_prompt.php: whitespace-only text is treated as no text, falling back to the plain (rejecting) path');

    // --- push-notify.js / sw.js: the CSRF-token-in-IndexedDB cache and the
    // notification Approve/Deny action buttons - see PushDeliveryService::approve_deny_actions() for
    // the host-agent side that decides when these show up at all. ---
    assert_true(
        preg_match('#<script src="(/js/push-notify\.js\?v=\d+)"></script>#', $dashboardBody, $pushNotifyScriptMatch) === 1,
        'GET /: loads push-notify.js, cache-busted with a ?v=<mtime> query string'
    );
    $pushNotifyJs = curl_request('GET', "{$baseUrl}{$pushNotifyScriptMatch[1]}");
    assert_equal(200, $pushNotifyJs['status'], 'GET /js/push-notify.js?v=...: 200 (served as a static file, no 404)');
    assert_contains("indexedDB.open('sessioneer-push', 1)", $pushNotifyJs['body'], 'GET /js/push-notify.js: caches the CSRF token in the same IndexedDB store sw.js reads from');
    assert_contains("tx.objectStore('kv').put(CSRF_TOKEN, 'csrf_token')", $pushNotifyJs['body'], 'GET /js/push-notify.js: writes the REAL page CSRF token (not a placeholder) into the cache');

    $swJs = curl_request('GET', "{$baseUrl}/sw.js");
    assert_equal(200, $swJs['status'], 'GET /sw.js: 200 (served as a static file, no 404)');
    assert_contains('function readPushCsrfToken(', $swJs['body'], 'GET /sw.js: readPushCsrfToken() (reads the CSRF token push-notify.js cached) is shipped');
    assert_contains("data.approve_option != null && data.deny_option != null", $swJs['body'], 'GET /sw.js: Approve/Deny action buttons are only added when the push payload actually carries both option numbers');
    assert_contains("{ action: 'approve', title: 'Approve' }", $swJs['body'], 'GET /sw.js: the Approve action button is shipped');
    assert_contains("{ action: 'deny', title: 'Deny' }", $swJs['body'], 'GET /sw.js: the Deny action button is shipped');
    assert_contains("event.action === 'approve' || event.action === 'deny'", $swJs['body'], 'GET /sw.js: notificationclick has a dedicated branch for the action buttons, separate from a plain tap');
    assert_contains("fetch('/answer_prompt.php'", $swJs['body'], 'GET /sw.js: answers the prompt directly via fetch() to the existing answer_prompt endpoint, no new route needed');
    assert_contains("expected_label: event.action === 'approve' ? 'Yes' : 'No'", $swJs['body'], 'GET /sw.js: notification actions submit their intent label so a live Claude menu can safely remap changed option numbers');
    assert_contains('return openOrFocusUrl(notifData.url || \'/\')', $swJs['body'], 'GET /sw.js: falls back to opening/focusing the app if the answer can\'t be sent headlessly (no cached token yet, or a malformed payload) rather than silently doing nothing');

    // --- session_send.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_send.php?session=cc-20260101-1200");
    assert_equal(405, $result['status'], 'GET /session_send.php: 405 (POST required)');

    // --- session_send.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token&message=hi',
    ]);
    assert_equal(403, $result['status'], 'POST /session_send.php with a wrong csrf_token: 403');

    // --- session_send.php: valid CSRF, real message -> canned agent accepts it, returns JSON (no redirect, no page reload) ---
    $csrfForSend = extract_csrf_token(curl_request('GET', "{$baseUrl}/session?session=cc-20260101-1200", [], $cookieJar)['body']);
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&message=' . urlencode('Please continue'),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_send.php with valid CSRF: 200 (JSON, not a redirect)');
    $sendBody = json_decode($result['body'], true);
    assert_true(is_array($sendBody) && ($sendBody['ok'] ?? false), 'POST /session_send.php: canned agent accepts the message, response decodes as ok=true JSON');

    // --- session_send.php: canned agent rejects an empty message ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&message=',
    ], $cookieJar);
    $emptyBody = json_decode($result['body'], true);
    assert_equal(false, $emptyBody['ok'] ?? null, 'POST /session_send.php: canned agent rejects an empty message');

    // --- session_send.php: an empty message with attachments[] present is
    // still accepted - proves attachments[] actually reaches the agent
    // action as attachment_paths (not just that a blank message alone is
    // rejected, already covered above). ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&message=&attachments%5B%5D=' . urlencode('.claude/uploads/report.pdf'),
    ], $cookieJar);
    $attachmentOnlySendBody = json_decode($result['body'], true);
    assert_true(is_array($attachmentOnlySendBody) && ($attachmentOnlySendBody['ok'] ?? false), 'POST /session_send.php: an empty message with attachments[] present is still accepted, proving attachments[] reaches the agent action');

    // --- session_mode.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_mode.php?session=cc-20260101-1200");
    assert_equal(405, $result['status'], 'GET /session_mode.php: 405 (POST required)');

    // --- session_mode.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_mode.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /session_mode.php with a wrong csrf_token: 403');

    // --- session_mode.php: valid CSRF, real mode -> canned agent accepts it, returns JSON ---
    $result = curl_request('POST', "{$baseUrl}/session_mode.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&mode=plan',
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_mode.php with valid CSRF: 200 (JSON, not a redirect)');
    $modeBody = json_decode($result['body'], true);
    assert_true(is_array($modeBody) && ($modeBody['ok'] ?? false), 'POST /session_mode.php: canned agent accepts the mode change, response decodes as ok=true JSON');

    // --- session_mode.php: canned agent rejects a session it doesn't recognize ---
    $result = curl_request('POST', "{$baseUrl}/session_mode.php", [
        '-d', 'session=' . urlencode('cc-not-a-real-session') . '&csrf_token=' . urlencode((string)$csrfForSend) . '&mode=plan',
    ], $cookieJar);
    $modeRejectBody = json_decode($result['body'], true);
    assert_equal(false, $modeRejectBody['ok'] ?? null, 'POST /session_mode.php: canned agent rejects an unrecognized session');

    // --- session_escape.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/session_escape.php?session=cc-20260101-1200");
    assert_equal(405, $result['status'], 'GET /session_escape.php: 405 (POST required)');

    // --- session_escape.php: CSRF enforced ---
    $result = curl_request('POST', "{$baseUrl}/session_escape.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /session_escape.php with a wrong csrf_token: 403');

    // --- session_escape.php: valid CSRF -> canned agent accepts it, returns JSON ---
    $result = curl_request('POST', "{$baseUrl}/session_escape.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /session_escape.php with valid CSRF: 200 (JSON, not a redirect)');
    $escapeBody = json_decode($result['body'], true);
    assert_true(is_array($escapeBody) && ($escapeBody['ok'] ?? false), 'POST /session_escape.php: canned agent accepts the stop request, response decodes as ok=true JSON');

    // --- session_escape.php: canned agent rejects a session it doesn't recognize ---
    $result = curl_request('POST', "{$baseUrl}/session_escape.php", [
        '-d', 'session=' . urlencode('cc-not-a-real-session') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $escapeRejectBody = json_decode($result['body'], true);
    assert_equal(false, $escapeRejectBody['ok'] ?? null, 'POST /session_escape.php: canned agent rejects an unrecognized session');

    // --- push_subscribe.php: GET not allowed ---
    $result = curl_request('GET', "{$baseUrl}/push_subscribe.php");
    assert_equal(405, $result['status'], 'GET /push_subscribe.php: 405 (POST required)');

    // --- push_subscribe.php: CSRF enforced ---
    $fakeSubscriptionJson = json_encode(['endpoint' => 'https://push.example/x', 'keys' => ['p256dh' => 'p', 'auth' => 'a']]);
    $result = curl_request('POST', "{$baseUrl}/push_subscribe.php", [
        '-d', 'subscription=' . urlencode((string)$fakeSubscriptionJson) . '&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /push_subscribe.php with a wrong csrf_token: 403');

    // --- push_subscribe.php: valid CSRF + well-formed subscription -> canned agent accepts it ---
    $result = curl_request('POST', "{$baseUrl}/push_subscribe.php", [
        '-d', 'subscription=' . urlencode((string)$fakeSubscriptionJson) . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /push_subscribe.php with valid CSRF: 200 (JSON, not a redirect)');
    $subscribeBody = json_decode($result['body'], true);
    assert_true(is_array($subscribeBody) && ($subscribeBody['ok'] ?? false), 'POST /push_subscribe.php: canned agent accepts a well-formed subscription, response decodes as ok=true JSON');

    // --- push_subscribe.php: malformed/missing subscription field is rejected before ever reaching the agent ---
    $result = curl_request('POST', "{$baseUrl}/push_subscribe.php", [
        '-d', 'csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $malformedSubscribeBody = json_decode($result['body'], true);
    assert_equal(false, $malformedSubscribeBody['ok'] ?? null, 'POST /push_subscribe.php: rejects a request with no subscription field at all');

    // --- push_unsubscribe.php: GET not allowed, CSRF enforced, valid request accepted ---
    $result = curl_request('GET', "{$baseUrl}/push_unsubscribe.php");
    assert_equal(405, $result['status'], 'GET /push_unsubscribe.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/push_unsubscribe.php", [
        '-d', 'endpoint=' . urlencode('https://push.example/x') . '&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /push_unsubscribe.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/push_unsubscribe.php", [
        '-d', 'endpoint=' . urlencode('https://push.example/x') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /push_unsubscribe.php with valid CSRF: 200 (JSON, not a redirect)');
    $unsubscribeBody = json_decode($result['body'], true);
    assert_true(is_array($unsubscribeBody) && ($unsubscribeBody['ok'] ?? false), 'POST /push_unsubscribe.php: canned agent accepts it, response decodes as ok=true JSON');

    // --- upload_file.php: GET not allowed, CSRF enforced, a real file upload relayed to the canned agent ---
    $result = curl_request('GET', "{$baseUrl}/upload_file.php");
    assert_equal(405, $result['status'], 'GET /upload_file.php: 405 (POST required)');

    $uploadFixturePath = sys_get_temp_dir() . '/sessioneer-test-upload-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($uploadFixturePath, 'fixture upload content');

    $result = curl_request('POST', "{$baseUrl}/upload_file.php", [
        '-F', 'session=cc-20260101-1200',
        '-F', 'csrf_token=not-the-real-token',
        '-F', 'file=@' . $uploadFixturePath,
    ]);
    assert_equal(403, $result['status'], 'POST /upload_file.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/upload_file.php", [
        '-F', 'session=cc-20260101-1200',
        '-F', 'csrf_token=' . (string)$csrfForSend,
        '-F', 'file=@' . $uploadFixturePath,
    ], $cookieJar);
    assert_equal(200, $result['status'], 'POST /upload_file.php with valid CSRF: 200 (JSON, not a redirect)');
    $uploadBody = json_decode($result['body'], true);
    assert_true(is_array($uploadBody) && ($uploadBody['ok'] ?? false), 'POST /upload_file.php: canned agent accepts the upload, response decodes as ok=true JSON');
    assert_equal(strlen('fixture upload content'), $uploadBody['size'] ?? null, 'POST /upload_file.php: the real file content was read and its true (decoded) size relayed through to the agent action, not e.g. the base64 length');

    $result = curl_request('POST', "{$baseUrl}/upload_file.php", [
        '-F', 'session=cc-20260101-1200',
        '-F', 'csrf_token=' . (string)$csrfForSend,
    ], $cookieJar);
    $noFileBody = json_decode($result['body'], true);
    assert_equal(false, $noFileBody['ok'] ?? null, 'POST /upload_file.php: rejects a request with no file field at all');

    @unlink($uploadFixturePath);

    // --- uploaded_files.php: GET-only, passes the canned agent's list through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/uploaded_files.php?session=cc-20260101-1200");
    assert_equal(200, $result['status'], 'GET /uploaded_files.php: 200');
    $filesBody = json_decode($result['body'], true);
    assert_true(is_array($filesBody) && ($filesBody['ok'] ?? false), 'GET /uploaded_files.php: response decodes as ok=true JSON');
    assert_equal(2, count($filesBody['files'] ?? []), 'GET /uploaded_files.php: canned files passed through');

    // --- session_plan_files.php: GET-only, passes the canned agent's plan/handoff-files list through as JSON ---
    $result = curl_request('GET', "{$baseUrl}/session_plan_files.php?session=cc-20260101-1200");
    assert_equal(200, $result['status'], 'GET /session_plan_files.php: 200');
    $planFilesBody = json_decode($result['body'], true);
    assert_true(is_array($planFilesBody) && ($planFilesBody['ok'] ?? false), 'GET /session_plan_files.php: response decodes as ok=true JSON');
    assert_equal(['PLAN.md', 'handoff-2026-08-08.md'], array_column($planFilesBody['files'] ?? [], 'name'), 'GET /session_plan_files.php: canned files passed through');

    $planFilesUnknownResult = curl_request('GET', "{$baseUrl}/session_plan_files.php?session=cc-not-a-real-session");
    $planFilesUnknownBody = json_decode($planFilesUnknownResult['body'], true);
    assert_equal(false, $planFilesUnknownBody['ok'] ?? null, 'GET /session_plan_files.php: ok=false for an unrecognized session, not a crash');

    // --- search_sessions.php: dashboard-wide content search, GET-only JSON, read-only (no CSRF needed) ---
    $searchResult = curl_request('GET', "{$baseUrl}/search_sessions.php?q=redirect");
    assert_equal(200, $searchResult['status'], 'GET /search_sessions.php: 200');
    $searchBody = json_decode($searchResult['body'], true);
    assert_true(is_array($searchBody) && ($searchBody['ok'] ?? false), 'GET /search_sessions.php: response decodes as ok=true JSON');
    assert_equal(2, count($searchBody['results'] ?? []), 'GET /search_sessions.php: both the live and archived canned matches come through');
    assert_equal('cc-20260101-1200', $searchBody['results'][0]['session_name'] ?? null, 'GET /search_sessions.php: a live result carries its tmux session_name');
    assert_true(array_key_exists('session_name', $searchBody['results'][1] ?? []) && $searchBody['results'][1]['session_name'] === null, 'GET /search_sessions.php: an archived result\'s session_name is null - that\'s what tells the client to link to archived_session.php instead of session.php');

    $searchNoMatchResult = curl_request('GET', "{$baseUrl}/search_sessions.php?q=nothing-matches-this");
    $searchNoMatchBody = json_decode($searchNoMatchResult['body'], true);
    assert_equal(true, $searchNoMatchBody['ok'] ?? null, 'GET /search_sessions.php: ok=true even for a query with no matches - empty results is not an error');
    assert_equal([], $searchNoMatchBody['results'] ?? null, 'GET /search_sessions.php: empty results array for no matches');

    // --- session_search.php: per-(live)session content search ---
    $sessionSearchResult = curl_request('GET', "{$baseUrl}/session_search.php?session=" . urlencode('cc-20260101-1200') . "&q=redirect");
    assert_equal(200, $sessionSearchResult['status'], 'GET /session_search.php: 200');
    $sessionSearchBody = json_decode($sessionSearchResult['body'], true);
    assert_true(is_array($sessionSearchBody) && ($sessionSearchBody['ok'] ?? false), 'GET /session_search.php: response decodes as ok=true JSON');
    assert_equal(1, count($sessionSearchBody['matches'] ?? []), 'GET /session_search.php: canned match passed through');
    assert_equal(2, $sessionSearchBody['matches'][0]['line'] ?? null, 'GET /session_search.php: match line number passed through - what the client\'s jump_line link uses');

    $sessionSearchUnknownResult = curl_request('GET', "{$baseUrl}/session_search.php?session=cc-not-a-real-session&q=redirect");
    $sessionSearchUnknownBody = json_decode($sessionSearchUnknownResult['body'], true);
    assert_equal(false, $sessionSearchUnknownBody['ok'] ?? null, 'GET /session_search.php: ok=false for an unrecognized session, not a crash');

    // --- archived_session_search.php: per-(archived)session content search ---
    $archivedSearchResult = curl_request('GET', "{$baseUrl}/archived_session_search.php?agent_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID . "&q=widget");
    assert_equal(200, $archivedSearchResult['status'], 'GET /archived_session_search.php: 200');
    $archivedSearchBody = json_decode($archivedSearchResult['body'], true);
    assert_true(is_array($archivedSearchBody) && ($archivedSearchBody['ok'] ?? false), 'GET /archived_session_search.php: response decodes as ok=true JSON');
    assert_equal(1, count($archivedSearchBody['matches'] ?? []), 'GET /archived_session_search.php: canned match passed through');

    $archivedSearchUnknownResult = curl_request('GET', "{$baseUrl}/archived_session_search.php?agent_session_id=00000000-0000-4000-8000-000000000000&q=widget");
    $archivedSearchUnknownBody = json_decode($archivedSearchUnknownResult['body'], true);
    assert_equal(false, $archivedSearchUnknownBody['ok'] ?? null, 'GET /archived_session_search.php: ok=false for an unrecognized agent_session_id, not a crash');

    // --- create_folder.php: GET not allowed, CSRF enforced, canned agent accepts/rejects by name ---
    $result = curl_request('GET', "{$baseUrl}/create_folder.php");
    assert_equal(405, $result['status'], 'GET /create_folder.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/create_folder.php", [
        '-d', 'path=' . urlencode('/home/user/www') . '&name=new-folder&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /create_folder.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/create_folder.php", [
        '-d', 'path=' . urlencode('/home/user/www') . '&name=' . urlencode('new-folder') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $createFolderBody = json_decode($result['body'], true);
    assert_true(is_array($createFolderBody) && ($createFolderBody['ok'] ?? false), 'POST /create_folder.php: canned agent accepts the name, response decodes as ok=true JSON');
    assert_equal('/home/user/www/new-folder', $createFolderBody['path'] ?? null, 'POST /create_folder.php: the canned new folder path is passed through');

    $result = curl_request('POST', "{$baseUrl}/create_folder.php", [
        '-d', 'path=' . urlencode('/home/user/www') . '&name=' . urlencode('bad name') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $createFolderRejectedBody = json_decode($result['body'], true);
    assert_equal(false, $createFolderRejectedBody['ok'] ?? null, 'POST /create_folder.php: canned agent rejects an unrecognized name');

    // --- delete_uploaded_file.php: GET not allowed, CSRF enforced, canned agent accepts/rejects by filename ---
    $result = curl_request('GET', "{$baseUrl}/delete_uploaded_file.php");
    assert_equal(405, $result['status'], 'GET /delete_uploaded_file.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/delete_uploaded_file.php", [
        '-d', 'session=cc-20260101-1200&filename=photo.jpg&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /delete_uploaded_file.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/delete_uploaded_file.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&filename=' . urlencode('photo.jpg') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $deleteFileBody = json_decode($result['body'], true);
    assert_true(is_array($deleteFileBody) && ($deleteFileBody['ok'] ?? false), 'POST /delete_uploaded_file.php: canned agent accepts the known filename, response decodes as ok=true JSON');

    $result = curl_request('POST', "{$baseUrl}/delete_uploaded_file.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&filename=' . urlencode('never-existed.txt') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $deleteMissingBody = json_decode($result['body'], true);
    assert_equal(false, $deleteMissingBody['ok'] ?? null, 'POST /delete_uploaded_file.php: canned agent rejects an unrecognized filename');

    // --- delete_all_uploaded_files.php: GET not allowed, CSRF enforced, valid request accepted ---
    $result = curl_request('GET', "{$baseUrl}/delete_all_uploaded_files.php");
    assert_equal(405, $result['status'], 'GET /delete_all_uploaded_files.php: 405 (POST required)');

    $result = curl_request('POST', "{$baseUrl}/delete_all_uploaded_files.php", [
        '-d', 'session=cc-20260101-1200&csrf_token=not-the-real-token',
    ]);
    assert_equal(403, $result['status'], 'POST /delete_all_uploaded_files.php with a wrong csrf_token: 403');

    $result = curl_request('POST', "{$baseUrl}/delete_all_uploaded_files.php", [
        '-d', 'session=' . urlencode('cc-20260101-1200') . '&csrf_token=' . urlencode((string)$csrfForSend),
    ], $cookieJar);
    $deleteAllBody = json_decode($result['body'], true);
    assert_true(is_array($deleteAllBody) && ($deleteAllBody['ok'] ?? false), 'POST /delete_all_uploaded_files.php: canned agent accepts it, response decodes as ok=true JSON');
    assert_equal(2, $deleteAllBody['deleted'] ?? null, 'POST /delete_all_uploaded_files.php: canned deleted count passed through');

    // --- cross-origin POST rejected ---
    $result = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-H', 'Origin: http://evil.example',
        '-d', 'session=cc-20260101-1200&message=hi&csrf_token=' . urlencode((string)$csrfToken),
    ]);
    assert_equal(403, $result['status'], 'POST with mismatched Origin: 403');

    // --- archived_session_attachment.php: same binary-endpoint contract as
    // session_attachment.php, just keyed by agent_session_id ---
    $archivedAttachmentUrl = "/archived_session_attachment.php?agent_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID . "&line=8&file_uuid=canned-file-uuid-1";
    $archivedAttachmentResult = curl_request('GET', "{$baseUrl}{$archivedAttachmentUrl}");
    assert_equal(200, $archivedAttachmentResult['status'], 'GET /archived_session_attachment.php: 200 for a real, matching agent_session_id/line/file_uuid');
    assert_equal('canned attachment bytes', $archivedAttachmentResult['body'], 'GET /archived_session_attachment.php: streams the real (canned) file bytes');
    assert_contains('notes.txt', $archivedAttachmentResult['headers']['content-disposition'] ?? '', 'GET /archived_session_attachment.php: Content-Disposition carries the real filename');

    $wrongArchivedAttachmentResult = curl_request('GET', "{$baseUrl}/archived_session_attachment.php?agent_session_id=" . CANNED_ARCHIVED_CLAUDE_SESSION_ID . "&line=8&file_uuid=not-the-real-uuid");
    assert_equal(404, $wrongArchivedAttachmentResult['status'], 'GET /archived_session_attachment.php: an unrecognized file_uuid -> 404, not a silent empty body');

} finally {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    if (isset($cookieJar)) {
        @unlink($cookieJar);
    }
}

test_exit();

/**
 * Pulls the csrf_token hidden field's value out of a rendered page, the
 * same way a real form submission would - tests must never hardcode a
 * token, since a real one only exists inside the session the cookie jar
 * is carrying.
 */
function extract_csrf_token(string $html): ?string
{
    if (preg_match('/window\.V2S = (\{.*?\});<\/script>/s', $html, $m) !== 1) {
        return null;
    }
    $boot = json_decode($m[1], true);

    return is_array($boot) && is_string($boot['csrfToken'] ?? null) ? $boot['csrfToken'] : null;
}
