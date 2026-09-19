<?php
declare(strict_types=1);

/**
 * "Replay" testing flow, Tier 1: grows a REAL transcript file for a fixture
 * session one line at a time (tests/lib/replay_fixture.php), behind a real
 * isolated tmux pane, and drives the actual app the same way a browser
 * would - curl against `php -S` serving public/ (exactly like
 * test_ui_smoke.php), talking to the REAL host-agent/agent.php over a real
 * Unix socket (exactly like test_agent_client_protocol.php), never a
 * canned/static agent response. Confirms the UI's own JSON endpoints see
 * each incremental step correctly, including a blocked prompt appearing
 * mid-stream and being answered for real (a genuine tmux send-keys reaching
 * the fixture pane) - the curl-only half of the replay flow. The browser-
 * interaction half (does the actual DOM/JS accept the answer, not just the
 * backend) is test_session_replay_browser.php.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/replay_fixture.php';

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';

if (getenv('TMUX_SOCKET') === $realTmuxSocket || getenv('TMUX_SOCKET') === false) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket (or is unset). Check tests/.env.testing.\n");
    exit(1);
}

/**
 * Same regex-over-the-rendered-page approach as test_ui_smoke.php's own
 * helper of the same name - copied rather than shared, since that file's
 * other constants/setup aren't meant to be pulled in here too.
 */
function extract_csrf_token(string $html): ?string
{
    return preg_match('/name="csrf_token" value="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;
}

$workdir = getenv('WWW_ROOT') . '/project-a';
$ctx = replay_setup('full-session', $workdir);

$agentSocket = sys_get_temp_dir() . '/sessioneer-test-replay-agent.sock';
$agentHarness = start_harness(['php', dirname(__DIR__) . '/host-agent/agent.php'], $agentSocket);

$port = 18199;
$baseUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $agentSocket]);
$serverProcess = proc_open(
    [
        'php', '-S', "127.0.0.1:{$port}",
        '-t', dirname(__DIR__) . '/public',
        dirname(__DIR__) . '/public/index.php',
    ],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "replay: failed to start php -S\n");
    stop_harness($agentHarness, $agentSocket);
    replay_teardown($ctx);
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
    fwrite(STDERR, "replay: server on port {$port} never became ready\n");
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    replay_teardown($ctx);
    exit(1);
}

try {
    $cookieJar = tempnam(sys_get_temp_dir(), 'sessioneer-test-replay-cookies');
    $sessionName = $ctx['session_name'];

    $page = curl_request('GET', "{$baseUrl}/session.php?session=" . urlencode($sessionName), [], $cookieJar);
    assert_equal(200, $page['status'], 'GET /session.php: 200 for the freshly-created replay fixture session');

    $csrfToken = extract_csrf_token($page['body']);
    assert_true($csrfToken !== null, 'GET /session.php: page includes a csrf_token field');

    // Data-driven off each step's own JSON (tests/fixtures/replay/
    // full-session.replay.json) rather than a hardcoded step index - lets
    // the scenario grow (more kinds, more prompts) without this loop
    // needing to change at all.
    $stepCount = count($ctx['scenario']['steps']);
    $lastSeenLine = 1; // the seed transcript line, already "seen" before this loop starts

    for ($i = 0; $i < $stepCount; $i++) {
        $advanced = replay_step($ctx);
        assert_true($advanced !== null, "replay_step: step {$i} advances (scenario has a matching line+step pair)");

        if ($advanced === null) {
            continue;
        }

        // null for an "append_line": false step (e.g. the second+ question
        // of one multi-question AskUserQuestion call) - no new transcript
        // content landed at all this step, so there's nothing to check via
        // session_history and $lastSeenLine stays exactly where it was.
        if ($advanced['line_number'] !== null) {
            $expectRender = $advanced['step']['expect_render'] ?? true;

            $history = curl_request(
                'GET',
                "{$baseUrl}/session_history.php?session=" . urlencode($sessionName) . "&after={$lastSeenLine}&limit=30",
                [],
                $cookieJar
            );
            $historyData = json_decode($history['body'], true);
            assert_true($historyData['ok'] ?? false, "session_history (step {$i}): ok=true");

            $newLines = array_column($historyData['entries'] ?? [], 'line');

            if ($expectRender) {
                assert_true(
                    in_array($advanced['line_number'], $newLines, true),
                    "session_history (step {$i}): the just-appended transcript line ({$advanced['line_number']}) shows up as new via ?after={$lastSeenLine}"
                );
            } else {
                // A meta-only line (e.g. "permission-mode" noise) - proves the
                // server-side filter actually drops it, not just that nothing
                // asserted it either way.
                assert_equal([], $historyData['entries'] ?? null, "session_history (step {$i}): a meta-only transcript line produces zero new entries");
            }

            $lastSeenLine = $advanced['line_number'];
        }

        $detail = curl_request('GET', "{$baseUrl}/session_detail.php?session=" . urlencode($sessionName), [], $cookieJar);
        $detailData = json_decode($detail['body'], true);
        assert_true($detailData['ok'] ?? false, "session_detail (step {$i}): ok=true");

        if (isset($advanced['step']['expect_working'])) {
            // Real end-to-end coverage of SessionStatusStore-driven
            // working-status (see replay_fixture.php's own "hook_status"
            // doc comment) through the actual session_detail.php JSON
            // endpoint - not just a unit test of build_session_entry()'s
            // logic in isolation. Working-status used to come from the pane
            // title's spinner glyph instead (PromptParser::
            // pane_title_is_working(), deleted 2026-08-22 once these hooks
            // became mandatory) - this same assertion covered THAT path
            // end-to-end before the swap, catching a real regression live
            // 2026-08-12 that a pure unit test of the string-matching
            // function alone had missed (Claude Code switching its spinner
            // glyph set).
            $expectedWorking = $advanced['step']['expect_working'];
            assert_equal($expectedWorking, $detailData['working'] ?? null, 'session_detail (step ' . $i . '): working=' . ($expectedWorking ? 'true' : 'false'));
        }

        $blockedPrompt = $advanced['step']['blocked_prompt'] ?? null;

        if ($blockedPrompt !== null) {
            assert_equal($blockedPrompt['question'], $detailData['blocked_reason'] ?? null, "session_detail (step {$i}): \"{$blockedPrompt['question']}\" is reported as blocking");

            if ($blockedPrompt['multi_question'] ?? false) {
                // Proves PromptParser actually recognized the tab-bar shape
                // (the "←  ☐ Region  ..." line in this step's own pane_text),
                // not just that some blocked_reason text happened to match -
                // real multi-question prompts skip the trailing Enter on
                // answer (see SessionService::answer_prompt()'s own doc
                // comment), which only actually applies when this is true.
                assert_equal(true, $detailData['prompt_multi_question'] ?? null, "session_detail (step {$i}): prompt_multi_question is reported true");
            }

            if ($blockedPrompt['answer']['mode'] === 'multi_question_form') {
                // The hook-fed prompt_questions field (see SessionService::
                // build_session_entry()) is what the app's own "answer
                // everything at once" form actually renders from - confirm
                // it's really there, with every question, before answering.
                assert_true(is_array($detailData['prompt_questions'] ?? null) && count($detailData['prompt_questions']) === count($blockedPrompt['answer']['answers']), "session_detail (step {$i}): prompt_questions carries one entry per question");
                // The hook-fed "answer every question at once" form (see
                // SessionService::answer_multi_question()) - a genuinely
                // different endpoint/payload shape from the single-option
                // answer_prompt.php case below, not just a different value
                // for the same call.
                $answer = curl_request('POST', "{$baseUrl}/answer_multi_question.php", [
                    '-d', 'session=' . urlencode($sessionName) . '&answers=' . urlencode(json_encode($blockedPrompt['answer']['answers'])) . '&csrf_token=' . urlencode((string)$csrfToken),
                ], $cookieJar);
                $answerData = json_decode($answer['body'], true);
                assert_true($answerData['ok'] ?? false, "answer_multi_question.php (step {$i}): ok=true for every question answered at once");
            } else {
                $optionNumbers = array_column($detailData['prompt_options'] ?? [], 'number');
                $answerOption = (int)$blockedPrompt['answer']['option'];
                assert_true(in_array($answerOption, $optionNumbers, true), "session_detail (step {$i}): option {$answerOption} is offered");

                if ($blockedPrompt['answer']['mode'] === 'freetext') {
                    $answerText = (string)$blockedPrompt['answer']['text'];
                    $answer = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
                        '-d', 'session=' . urlencode($sessionName) . '&option=' . $answerOption . '&text=' . urlencode($answerText) . '&csrf_token=' . urlencode((string)$csrfToken),
                    ], $cookieJar);
                    $answerData = json_decode($answer['body'], true);
                    assert_true($answerData['ok'] ?? false, "answer_prompt.php (step {$i}): ok=true for the free-text reply");
                } else {
                    $answer = curl_request('POST', "{$baseUrl}/answer_prompt.php", [
                        '-d', 'session=' . urlencode($sessionName) . '&option=' . $answerOption . '&csrf_token=' . urlencode((string)$csrfToken),
                    ], $cookieJar);
                    $answerData = json_decode($answer['body'], true);
                    assert_true($answerData['ok'] ?? false, "answer_prompt.php (step {$i}): ok=true for the currently-offered option");
                }
            }
        } else {
            assert_equal(null, $detailData['blocked_reason'] ?? null, "session_detail (step {$i}): no blocked prompt reported");
        }
    }

    $send = curl_request('POST', "{$baseUrl}/session_send.php", [
        '-d', 'session=' . urlencode($sessionName) . '&message=' . urlencode('Thanks, looks good.') . '&csrf_token=' . urlencode((string)$csrfToken),
    ], $cookieJar);
    $sendData = json_decode($send['body'], true);
    assert_true($sendData['ok'] ?? false, 'session_send.php: ok=true sending a follow-up message once the replay scenario finishes');
} finally {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    replay_teardown($ctx);
    if (isset($cookieJar)) {
        @unlink($cookieJar);
    }
}

test_exit();
