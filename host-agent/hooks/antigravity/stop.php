#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Antigravity's Stop hook (see AntigravityHookService and
 * docs/history/antigravity-adapter-plan.md Phase 3) - fires once the execution
 * loop terminates. Marks the session idle and clears any blocked state,
 * the third leg of the working/blocked/idle inference host-agent/hooks/
 * user_prompt_submit.php's own docblock describes for Claude Code -
 * SessionService::build_session_entry() reads this the same way
 * regardless of which agent governs the session ("blocked" itself isn't
 * populated for Antigravity sessions yet - see pre_tool_use.php's own
 * docblock for why).
 *
 * last_message comes from tailing transcript_full.jsonl's last
 * PLANNER_RESPONSE entry (see find_last_planner_response() below) - the
 * Stop payload itself carries no message-text field, unlike Claude
 * Code's own stop.php, which gets last_assistant_message directly on
 * stdin.
 *
 * decision (string, required per Antigravity's own docs): any value
 * other than "continue" lets the agent actually stop - Sessioneer never wants
 * to force continuation, so this always returns a fixed non-"continue"
 * sentinel ("allow_stop").
 *
 * SESSIONEER_SESSION_NAME gate: same convention as every hook script here - this
 * hook fires globally (every agy invocation on the machine), so an
 * untracked session still needs a valid decision returned.
 */

require __DIR__ . '/../../lib/Sessions.php';

use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SessionStatusStore;

/**
 * Same bounded-tail-read pattern TranscriptService::find_latest_ai_title()
 * already uses for Claude Code's own transcript file (see that method's
 * own docblock) - only reads the file's last TAIL_SCAN_BYTES, not the
 * whole thing, since Antigravity's transcript can grow across a whole
 * long session. Takes the LAST PLANNER_RESPONSE entry with real text
 * content - a tool-calls-only turn has content: null (confirmed live,
 * see docs/history/antigravity-adapter-plan.md), so those are skipped in favor of
 * an earlier or later entry that actually has text.
 */
function antigravity_find_last_planner_response(string $path): ?string
{
    // Same tail-scan window as TranscriptService::AI_TITLE_TAIL_SCAN_BYTES.
    $tailScanBytes = 262144;

    $size = @filesize($path);

    if ($size === false || $size === 0) {
        return null;
    }

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return null;
    }

    $tailBytes = min($size, $tailScanBytes);
    fseek($handle, -$tailBytes, SEEK_END);
    $chunk = fread($handle, $tailBytes);
    fclose($handle);

    if ($chunk === false) {
        return null;
    }

    $lastContent = null;

    // The read may start mid-line when $tailBytes < $size - a truncated
    // leading fragment simply fails json_decode below and is skipped,
    // same tolerance TranscriptService's own tail-read methods already have.
    foreach (explode("\n", $chunk) as $line) {
        if (!str_contains($line, 'PLANNER_RESPONSE')) {
            continue;
        }

        $decoded = json_decode($line, true);

        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'PLANNER_RESPONSE') {
            continue;
        }

        $content = is_string($decoded['content'] ?? null) ? trim($decoded['content']) : '';

        if ($content !== '') {
            $lastContent = $content;
        }
    }

    return $lastContent;
}

/**
 * Found live 2026-08-24 (Andres: asked a real question in an Antigravity
 * session that had hit "Individual quota reached", waited for a reply,
 * never got one - not even an error - anywhere in this app): confirmed by
 * grepping the real transcript file that Antigravity writes NOTHING at all
 * to transcript_full.jsonl for a turn that fails this way - only the
 * USER_INPUT line for the question itself, no PLANNER_RESPONSE, no error
 * entry, nothing (reproduced three times in the same live session). The
 * ONLY place the failure is ever shown is the live pane's own "⚠ ..."
 * banner text, and only ephemerally (scrolls away, gone once the session
 * exits) - so unlike find_last_planner_response() above (a pure file
 * read), this function needs the live pane too.
 *
 * Scans the transcript tail for the most recent USER_INPUT's step_index,
 * then checks whether anything with a HIGHER step_index exists after it -
 * if so, that turn got a real response (even a tool-calls-only one),
 * nothing to do here. If not, the pane is searched bottom-up for the last
 * "⚠ "-prefixed line, stopping (returning null) if a "> " prompt-echo line
 * is reached first - that would mean walking back into an OLDER exchange
 * without finding a fresh error, so nothing genuinely failed just now.
 *
 * Found live 2026-08-24 (a real reproduction, not a hypothetical): the
 * FIRST version of this function took a single pre-captured $paneContent
 * and came back null even on a turn independently confirmed (moments
 * later, manually) to have the "⚠ Individual quota reached" banner sitting
 * right there in the pane - Stop fires the instant Antigravity's own
 * internal turn-handling concludes, which can beat its own TUI's re-render
 * of the error banner text by enough to matter, unlike
 * find_last_planner_response() above (a pure file read with no such race).
 * Fixed by re-capturing the pane fresh on up to 3 attempts, TMUX_KEY_STEP_
 * DELAY_USEC (300ms, same delay unit PromptInteractionService's own
 * key-sequence pacing uses) apart - only ever reached on the rare
 * no-response path (the transcript-tail check above already short-circuits
 * every normal successful turn before this), so the common case pays
 * nothing extra.
 */
function antigravity_find_unanswered_turn_error(string $transcriptPath, string $sessionName): ?string
{
    // Same tail-scan window as find_last_planner_response() above.
    $tailScanBytes = 262144;

    $size = @filesize($transcriptPath);

    if ($size === false || $size === 0) {
        return null;
    }

    $handle = @fopen($transcriptPath, 'rb');

    if ($handle === false) {
        return null;
    }

    $tailBytes = min($size, $tailScanBytes);
    fseek($handle, -$tailBytes, SEEK_END);
    $chunk = fread($handle, $tailBytes);
    fclose($handle);

    if ($chunk === false) {
        return null;
    }

    $lastUserStepIndex = null;
    $hasResponseAfterLastUser = false;

    foreach (explode("\n", $chunk) as $line) {
        $decoded = json_decode($line, true);

        if (!is_array($decoded) || !isset($decoded['step_index'], $decoded['type']) || !is_int($decoded['step_index'])) {
            continue;
        }

        if ($decoded['type'] === 'USER_INPUT') {
            $lastUserStepIndex = $decoded['step_index'];
            $hasResponseAfterLastUser = false;
        } elseif ($lastUserStepIndex !== null && $decoded['step_index'] > $lastUserStepIndex) {
            $hasResponseAfterLastUser = true;
        }
    }

    if ($lastUserStepIndex === null || $hasResponseAfterLastUser) {
        return null;
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) {
            usleep(PromptInteractionService::TMUX_KEY_STEP_DELAY_USEC);
        }

        foreach (array_reverse(explode("\n", TmuxService::tmux_capture_pane($sessionName))) as $paneLine) {
            $trimmed = trim($paneLine);

            if (str_starts_with($trimmed, '⚠')) {
                return $trimmed;
            }

            if (str_starts_with($trimmed, '> ')) {
                break;
            }
        }
    }

    return null;
}

$sessionName = getenv('SESSIONEER_SESSION_NAME');

if ($sessionName === false || $sessionName === '') {
    echo json_encode(['decision' => 'allow_stop']);
    exit(0);
}

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);

if (!is_array($payload)) {
    echo json_encode(['decision' => 'allow_stop']);
    exit(0);
}

$fields = ['status' => 'idle', 'blocked' => null, 'last_turn_error' => null];

$transcriptPath = is_string($payload['transcriptPath'] ?? null) ? $payload['transcriptPath'] : null;
$lastMessage = $transcriptPath !== null ? antigravity_find_last_planner_response($transcriptPath) : null;

if ($lastMessage !== null) {
    $fields['last_message'] = $lastMessage;
}

if ($transcriptPath !== null) {
    $fields['last_turn_error'] = antigravity_find_unanswered_turn_error($transcriptPath, $sessionName);
}

SessionStatusStore::update_status($sessionName, $fields);

echo json_encode(['decision' => 'allow_stop']);
