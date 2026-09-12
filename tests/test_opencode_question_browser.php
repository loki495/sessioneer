<?php
declare(strict_types=1);

/**
 * Browser coverage for a pending OpenCode transcript question. It uses the
 * real session page, session.js, CSRF guard, and answer_prompt endpoint;
 * only the host-agent is synthetic. The fixture records the action received
 * after the PHP controller so this proves browser AJAX payloads rather than
 * merely a mocked fetch call.
 *
 * Best-effort: skips when Chrome is unavailable, matching the existing CDP
 * browser tier.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/cdp.php';

const OPENCODE_QUESTION_BROWSER_SESSION = 'oc-question-browser';

function opencode_question_browser_wait_until(callable $check, float $timeoutSeconds = 10.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        if ($check()) {
            return true;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);

    return $check();
}

function opencode_question_browser_assert(array &$page, bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "  [debug] question state: " . (string)cdp_evaluate($page, 'JSON.stringify({cards:document.querySelectorAll(".question-block").length, forms:document.querySelectorAll(".prompt-options-wrapper").length, text:document.body.innerText.slice(-1000)})') . "\n");
    }

    assert_true($condition, $message);
}

$chrome = cdp_find_chrome();
if ($chrome === null) {
    echo "  SKIP: no Chrome/Chromium executable available\n";
    exit(0);
}

$suffix = (string)getmypid();
$agentSocket = sys_get_temp_dir() . '/sessioneer-opencode-question-browser-' . $suffix . '.sock';
$statePath = sys_get_temp_dir() . '/sessioneer-opencode-question-browser-state-' . $suffix . '.json';
$requestLogPath = sys_get_temp_dir() . '/sessioneer-opencode-question-browser-requests-' . $suffix . '.jsonl';
$port = 18180 + (getmypid() % 500);
$baseUrl = "http://127.0.0.1:{$port}";
$state = ['answered' => false, 'answer' => null, 'history_calls' => 0];
file_put_contents($statePath, json_encode($state));
putenv('OPENCODE_QUESTION_BROWSER_STATE=' . $statePath);
putenv('OPENCODE_QUESTION_BROWSER_REQUEST_LOG=' . $requestLogPath);

$agentHarness = start_harness(['php', __DIR__ . '/fixtures/opencode_question_browser_agent.php'], $agentSocket);
$serverEnv = array_merge(getenv(), [
    'SESSIONEER_AGENT_SOCKET' => $agentSocket,
    'OPENCODE_QUESTION_BROWSER_STATE' => $statePath,
    'OPENCODE_QUESTION_BROWSER_REQUEST_LOG' => $requestLogPath,
]);
$serverProcess = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);

if (!is_resource($serverProcess)) {
    stop_harness($agentHarness, $agentSocket);
    @unlink($statePath);
    fwrite(STDERR, "opencode question browser: failed to start php -S\n");
    exit(1);
}
fclose($serverPipes[0]);

$page = null;
$browser = null;
try {
    $ready = opencode_question_browser_wait_until(static function () use ($port): bool {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($connection === false) {
            return false;
        }
        fclose($connection);
        return true;
    }, 3.0);
    assert_true($ready, 'synthetic OpenCode browser server starts');

    $browser = cdp_launch($chrome);
    if ($browser === null) {
        echo "  SKIP: Chrome could not launch\n";
    } else {
        $page = cdp_open_page($browser);
        if ($page === null) {
            echo "  SKIP: Chrome page could not open\n";
        } else {
            $url = $baseUrl . '/session.php?session=' . rawurlencode(OPENCODE_QUESTION_BROWSER_SESSION);
            opencode_question_browser_assert($page, cdp_navigate($page, $url), 'synthetic OpenCode session page loads');
            opencode_question_browser_assert($page, cdp_evaluate($page, "document.querySelector('.question-block .prompt-options-wrapper') !== null") === true, 'SSR pending question renders the reusable interactive prompt component');
            $polledQuestion = opencode_question_browser_wait_until(function () use (&$page): bool {
                return cdp_evaluate($page, "document.querySelectorAll('.question-block .prompt-options-wrapper').length >= 2") === true;
            });
            opencode_question_browser_assert($page, $polledQuestion, 'poll-created pending question uses the same actionable prompt component');
            opencode_question_browser_assert($page, cdp_evaluate($page, 'var cards=document.querySelectorAll(".question-block"); var button=cards[1] && cards[1].querySelector(".reveal-freetext-btn[data-option=\\"3\\"]"); if(button){button.click(); true;}else{false;}') === true, 'custom-answer reveal button works inside the poll-created transcript question card');

            // Avoid a native modal blocking the CDP session while still
            // asserting the handled error is shown to the user.
            cdp_evaluate($page, 'window.__questionAlerts=[]; window.alert=function(message){window.__questionAlerts.push(String(message));};');
            cdp_evaluate($page, 'var el=document.querySelectorAll(".question-block")[1].querySelector(".freetext-reply-textarea"); el.value="reject"; el.dispatchEvent(new Event("input", {bubbles:true}));');
            opencode_question_browser_assert($page, cdp_evaluate($page, 'var button=document.querySelectorAll(".question-block")[1].querySelector(".freetext-reply-send-btn"); if(button){button.click(); true;}else{false;}') === true, 'rejected custom answer submits through the poll-created transcript card');
            $rejectionHandled = opencode_question_browser_wait_until(function () use (&$page): bool {
                return cdp_evaluate($page, 'window.__questionAlerts.indexOf("Synthetic OpenCode question rejected this reply") !== -1') === true;
            });
            opencode_question_browser_assert($page, $rejectionHandled, 'rejected AJAX reply is handled in-page with the server message');
            opencode_question_browser_assert($page, cdp_evaluate($page, '(function(){var card=document.querySelectorAll(".question-block")[1]; return !!card && !card.classList.contains("opacity-50") && !card.querySelector(".freetext-reply-textarea").disabled;})()') === true, 'rejected reply restores the question card and editable custom field');

            cdp_evaluate($page, 'var el=document.querySelectorAll(".question-block")[1].querySelector(".freetext-reply-textarea"); el.value="Mango"; el.dispatchEvent(new Event("input", {bubbles:true}));');
            opencode_question_browser_assert($page, cdp_evaluate($page, 'var button=document.querySelectorAll(".question-block")[1].querySelector(".freetext-reply-send-btn"); if(button){button.click(); true;}else{false;}') === true, 'accepted custom answer submits through AJAX');
            $accepted = opencode_question_browser_wait_until(static function () use ($statePath): bool {
                $state = json_decode((string)file_get_contents($statePath), true);
                return is_array($state) && ($state['answer'] ?? null) === 'Mango';
            });
            assert_true($accepted, 'actual /answer_prompt.php request reaches the synthetic agent with the accepted custom answer');

            $pollRenderedResolution = opencode_question_browser_wait_until(function () use (&$page): bool {
                return cdp_evaluate($page, 'document.body.textContent.indexOf("Answered: Mango") !== -1') === true;
            });
            opencode_question_browser_assert($page, $pollRenderedResolution, 'poll-time transcript rendering shows the resolved custom answer');

            // Navigate away first: CDP can briefly observe the previous
            // document's complete readyState during same-URL navigation.
            cdp_navigate($page, 'about:blank');
            opencode_question_browser_assert($page, cdp_navigate($page, $url . '&verify=resolved'), 'session page reloads after answer');
            $ssrResolved = opencode_question_browser_wait_until(function () use (&$page): bool {
                return cdp_evaluate($page, 'document.body.textContent.indexOf("Answered: Mango") !== -1 && document.querySelector(".question-block .prompt-options-wrapper") === null') === true;
            });
            opencode_question_browser_assert($page, $ssrResolved, 'SSR resolved question shows its answer and no longer offers controls');
            $consoleErrors = cdp_drain_console_errors($page);
            assert_equal([], $consoleErrors, 'OpenCode question browser flow has no uncaught JavaScript errors');

            $records = is_file($requestLogPath) ? file($requestLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $decoded = array_map(static fn(string $line): mixed => json_decode($line, true), $records ?: []);
            assert_equal([
                ['action' => 'answer_prompt_with_text', 'session' => OPENCODE_QUESTION_BROWSER_SESSION, 'option' => 3, 'text' => 'reject'],
                ['action' => 'answer_prompt_with_text', 'session' => OPENCODE_QUESTION_BROWSER_SESSION, 'option' => 3, 'text' => 'Mango'],
            ], $decoded, 'AJAX custom-answer requests preserve the OpenCode session, free-text option, and text values');

            cdp_navigate($page, 'about:blank');
            file_put_contents($statePath, json_encode(['answered' => false, 'answer' => null]));
            cdp_navigate($page, $url . '&verify=option');
            $optionReady = opencode_question_browser_wait_until(function () use (&$page): bool {
                return cdp_evaluate($page, '!!document.querySelector(".question-block form button[type=submit]")') === true;
            });
            opencode_question_browser_assert($page, $optionReady, 'SSR option button is actionable');
            cdp_evaluate($page, 'window.__optionAlerts=[];window.alert=function(s){window.__optionAlerts.push(String(s));};');
            cdp_evaluate($page, 'localStorage.setItem("sessioneer-confirm-before-answer", "0");');
            assert_true(cdp_evaluate($page, 'shouldConfirmBeforeAnswer() === false') === true, 'option test uses the supported no-confirm preference');
            cdp_evaluate($page, 'document.querySelector(".question-block form button[type=submit]").click();');
            $optionAccepted = opencode_question_browser_wait_until(static function () use ($statePath): bool {
                return (json_decode((string)file_get_contents($statePath), true)['answer'] ?? null) === 'Apple';
            });
            if (!$optionAccepted) {
                fwrite(STDERR, 'Option fixture requests: ' . (string)file_get_contents($requestLogPath) . PHP_EOL);
                fwrite(STDERR, 'Option browser errors: ' . json_encode(cdp_drain_console_errors($page)) . ' alerts=' . json_encode(cdp_evaluate($page, 'window.__optionAlerts')) . PHP_EOL);
            }
            assert_true($optionAccepted, 'option button reaches the actual AJAX controller with the selected option');

            cdp_navigate($page, 'about:blank');
            file_put_contents($statePath, json_encode(['answered' => false, 'answer' => null, 'multi' => true]));
            cdp_navigate($page, $url . '&verify=multi');
            $multiReady = opencode_question_browser_wait_until(function () use (&$page): bool {
                return cdp_evaluate($page, '!!document.querySelector(".question-block .multi-question-wrapper")') === true;
            });
            opencode_question_browser_assert($page, $multiReady, 'multi-question transcript renders the full structured form');
            opencode_question_browser_assert($page, cdp_evaluate($page, '(function(){var form=document.querySelector(".question-block .multi-question-wrapper"); return form.querySelectorAll("input[type=checkbox]").length === 2 && !form.querySelector(".freetext-toggle");})()') === true, 'OpenCode multiple and custom=false render checkboxes without custom controls');
            cdp_evaluate($page, '(function(){var form=document.querySelector(".question-block .multi-question-wrapper"); form.querySelectorAll("input").forEach(function(el){el.checked=true;}); form.querySelector(".multi-question-submit-btn").click();})()');
            assert_true(opencode_question_browser_wait_until(static function () use ($statePath): bool {
                return (json_decode((string)file_get_contents($statePath), true)['answer'] ?? null) === 'Apple, Pear; Green';
            }), 'structured form submits all numeric selections through the AJAX controller');

        }
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }
    if ($browser !== null) {
        cdp_shutdown($browser);
    }
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    stop_harness($agentHarness, $agentSocket);
    @unlink($statePath);
    @unlink($requestLogPath);
}

test_exit();
