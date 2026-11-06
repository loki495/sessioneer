<?php
declare(strict_types=1);

/**
 * A pending OpenCode question inside the transcript on the session page, in a real headless Chrome: it renders
 * as an actionable prompt, a typed reply the agent refuses is reported in the page (the question stays
 * answerable), and an accepted typed reply reaches the host agent as the real /answer_prompt.php request and
 * comes back from the poll as "Answered: ...". Only the host agent is synthetic
 * (tests/fixtures/opencode_question_browser_agent.php). Skips (exit 0) when no Chrome is installed.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/cdp.php';

const OQP_SESSION = 'oc-question-browser';

function oqp_wait(array &$page, string $expression, float $timeout = 12.0): bool
{
    $deadline = microtime(true) + $timeout;

    do {
        if (cdp_evaluate($page, $expression) === true) {
            return true;
        }

        usleep(200000);
    } while (microtime(true) < $deadline);

    return cdp_evaluate($page, $expression) === true;
}

$chrome = cdp_find_chrome();

if ($chrome === null) {
    echo "  SKIP: no Chrome/Chromium executable available\n";
    exit(0);
}

$suffix = (string)getmypid();
$socket = sys_get_temp_dir() . '/sessioneer-oqp-' . $suffix . '.sock';
$statePath = sys_get_temp_dir() . '/sessioneer-oqp-state-' . $suffix . '.json';
$logPath = sys_get_temp_dir() . '/sessioneer-oqp-requests-' . $suffix . '.jsonl';
file_put_contents($statePath, json_encode(['answered' => false, 'answer' => null, 'history_calls' => 0]));
putenv('OPENCODE_QUESTION_BROWSER_STATE=' . $statePath);
putenv('OPENCODE_QUESTION_BROWSER_REQUEST_LOG=' . $logPath);

$harness = start_harness(['php', __DIR__ . '/fixtures/opencode_question_browser_agent.php'], $socket);
$port = test_free_port();
$server = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    array_merge(getenv(), ['SESSIONEER_AGENT_SOCKET' => $socket])
);
$browser = $page = null;

try {
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline && !($c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2))) {
        usleep(50000);
    }

    $browser = cdp_launch($chrome);
    $page = $browser === null ? null : cdp_open_page($browser);

    if ($page === null) {
        echo "  SKIP: Chrome could not launch\n";
    } else {
        cdp_set_viewport($page, 390, 844, 1.0, true);
        assert_true(cdp_navigate($page, "http://127.0.0.1:{$port}/session?session=" . OQP_SESSION), 'the synthetic OpenCode session page loads');
        assert_true(oqp_wait($page, "document.querySelector('.v2s-qblock [data-prompt-options]') !== null"), 'the pending question renders as an actionable prompt');
        assert_true(oqp_wait($page, "document.querySelector('.v2s-qblock').textContent.indexOf('Which fruit') !== -1"), 'it shows the question text');

        $typeAndSend = static fn (string $text): string => "(function () { var b = document.querySelector('.v2s-qblock'); b.querySelector('[data-act=\"reveal-free\"]').click(); var t = b.querySelector('.v2s-free textarea'); t.value = " . json_encode($text) . "; t.dispatchEvent(new Event('input', {bubbles: true})); b.querySelector('[data-act=\"send-free\"]').click(); return true; }())";

        cdp_evaluate($page, "window.alert = function () {}; true;");
        cdp_evaluate($page, $typeAndSend('reject'));
        assert_true(oqp_wait($page, "document.getElementById('v2-toast').textContent.indexOf('Synthetic OpenCode question rejected this reply') !== -1"), 'a refused reply is reported with the agent\'s message');
        assert_true(oqp_wait($page, "document.querySelector('.v2s-qblock [data-act=\"send-free\"]') !== null"), 'the question stays answerable after a refusal');

        cdp_evaluate($page, $typeAndSend('Mango'));
        assert_true(oqp_wait($page, "document.body.textContent.indexOf('Answered: Mango') !== -1"), 'the accepted reply comes back from the poll as the answer');
        $state = json_decode((string)file_get_contents($statePath), true);
        assert_equal('Mango', $state['answer'] ?? null, 'the real answer_prompt request reached the host agent with the typed text');
        assert_equal([], cdp_drain_console_errors($page), 'no browser errors');
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }

    if ($browser !== null) {
        cdp_shutdown($browser);
    }

    proc_terminate($server);
    proc_close($server);
    stop_harness($harness, $socket);
    @unlink($statePath);
    @unlink($logPath);
}

test_exit();
