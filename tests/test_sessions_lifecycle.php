<?php
declare(strict_types=1);

/**
 * Exercises the real create/list/kill/cleanup logic in
 * host-agent/lib/Sessions.php against an isolated tmux socket and the
 * tests/fixtures/fake_claude stand-in (never the real tmux server or the
 * real claude binary - see tests/.env.testing). Calls dispatch_action()'s
 * underlying functions in-process; no socket layer involved here, that's
 * covered by test_agent_client_protocol.php.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\ArchivedSessionService;
use HostAgent\Services\BareProcessService;
use HostAgent\Services\Config;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\PlanFileService;
use HostAgent\Services\ProcessInspector;
use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\PromptParser;
use HostAgent\Services\QuotaService;
use HostAgent\Services\SessionDetailService;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\SessionService;
use HostAgent\Services\TmuxService;
use HostAgent\Services\TuiLayoutMismatchService;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;

const REAL_TMUX_SOCKET = '/tmp/tmux-1000/default';
$realPushSqliteFile = Config::push_sqlite_path();

if (Config::tmux_socket() === REAL_TMUX_SOCKET) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

$pushSqliteFixture = sys_get_temp_dir() . '/sessioneer-test-sessions-lifecycle-' . bin2hex(random_bytes(4)) . '/push.sqlite';
$opencodeDbFixtureLc = sys_get_temp_dir() . '/sessioneer-test-sessions-lifecycle-' . bin2hex(random_bytes(4)) . '/opencode.db';
$opencodeAuthFixtureLc = sys_get_temp_dir() . '/sessioneer-test-sessions-lifecycle-' . bin2hex(random_bytes(4)) . '/auth.json';
putenv("PUSH_SQLITE_FILE={$pushSqliteFixture}");
putenv("OPENCODE_DB_PATH={$opencodeDbFixtureLc}");
putenv("OPENCODE_AUTH_PATH={$opencodeAuthFixtureLc}");
putenv('OPENCODE_GO_API_KEY=');

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

/** @var string[] $createdSessions names still possibly running, for the finally-block safety net */
$createdSessions = [];

/** @var resource|null $bareProc a plain (non-tmux) fake claude process, for the finally-block safety net */
$bareProc = null;

/** @var string|null $adhocName a non-cc-* tmux session hosting a fake claude process, for the finally-block safety net */
$adhocName = null;

/** @var string|null $adoptedTestSession a non-cc-* tmux session carrying its own sidecar (simulating an adopted session), for the finally-block safety net */
$adoptedTestSession = null;

/** @var string|null $promptTestSession a cc-* session used to test PromptInteractionService::answer_prompt(), for the finally-block safety net */
$promptTestSession = null;

/** @var string|null $folderTrustTestSession a cc-* session used to test PromptInteractionService::answer_prompt()'s folder-trust arrow-key path, for the finally-block safety net */
$folderTrustTestSession = null;

/** @var string|null $archivedBareAdhocName a non-cc-* tmux session used to test BareProcessService::live_bare_agent_session_ids()'s exclusion from the archived list and resume guard, for the finally-block safety net */
$archivedBareAdhocName = null;

/** @var string|null $resumeArgTestSession a non-cc-* tmux session used to test the argv-derived --resume/--session-id resolution tier, for the finally-block safety net */
$resumeArgTestSession = null;

/** @var string|null $realpathMatchTestSession a non-cc-* tmux session used to test ProcessInspector::find_claude_processes()'s realpath-based match, for the finally-block safety net */
$realpathMatchTestSession = null;

/** @var string|null $daemonLabelTestSession a non-cc-* tmux session used to test ProcessInspector::find_claude_processes()'s "claude <subcommand>" argv[0] match, for the finally-block safety net */
$daemonLabelTestSession = null;

/** @var resource|null $rosterBareProc a plain (non-tmux) fake claude process used to test the daemon-roster resolution tier, for the finally-block safety net */
$rosterBareProc = null;

/** @var string|null $sendTestSession a cc-* session used to test PromptInteractionService::send_message(), for the finally-block safety net */
$sendTestSession = null;

/** @var string|null $raceSessionA a cc-* session used to test send_message()/answer_prompt_with_text()'s tmux buffer isolation, for the finally-block safety net */
$raceSessionA = null;

/** @var string|null $raceSessionB the second session in the same buffer-isolation test, for the finally-block safety net */
$raceSessionB = null;

/** @var string|null $wrapTestSession a cc-* session used to test TmuxService::tmux_capture_pane()'s line-wrap rejoin, for the finally-block safety net */
$wrapTestSession = null;

/** @var string|null $quotaTestSession a cc-* session used to test QuotaService::live_context_pct(), for the finally-block safety net */
$quotaTestSession = null;

/** @var string|null $statusEntrySession a cc-* session used to test build_session_entry()'s SessionStatusStore wiring, for the finally-block safety net */
$statusEntrySession = null;

/** @var resource|null $takeOverBareProc a plain (non-tmux) fake claude process used to test take_over_bare_process(), for the finally-block safety net */
$takeOverBareProc = null;

/** @var string|null $markerAdhocName a non-cc-* tmux session used to test take_over_bare_process()'s marker-matched path, for the finally-block safety net */
$markerAdhocName = null;

/** @var string[] $dualBareAdhocNames non-cc-* tmux sessions used to test bare_process_take_over_candidates()'s other-live-bare-process exclusion, for the finally-block safety net */
$dualBareAdhocNames = [];

/**
 * @return array{ok:bool, name:?string, message:string}
 */
function create_and_track(string $workdir, array &$createdSessions, bool $enableTaskTools = false, ?string $startingMode = null, ?string $agentId = null): array
{
    $result = SessionLifecycleService::create_agent_session($workdir, $enableTaskTools, $startingMode, $agentId);
    $name = null;

    if (preg_match('/Created session (cc-\S+) in/', (string)($result['message'] ?? ''), $m) === 1) {
        $name = $m[1];
        $createdSessions[] = $name;
    }

    return ['ok' => (bool)($result['ok'] ?? false), 'name' => $name, 'message' => (string)($result['message'] ?? '')];
}

function find_session(string $name): ?array
{
    foreach (SessionService::list_all_sessions()['sessions'] as $session) {
        if ($session['name'] === $name) {
            return $session;
        }
    }

    return null;
}

// --- PromptParser::clean_pane_title(): strips Claude Code's animated spinner glyph,
// leaving the short task description it sets via terminal title escapes.
// Both the old Braille-dot spinner (⠂⠐...) and the newer half-circle one
// (◐◑..., found live 2026-08-12 replacing the dots - see
// PromptParser::SPINNER_GLYPH_PATTERN's own doc comment) are covered by
// the same \p{So}-based match, not two special-cased branches - the whole
// point of matching by Unicode category instead of a hardcoded range is
// that a THIRD future spinner style shouldn't need its own test case
// either, only new ones actually worth asserting as a specific regression
// guard get one. ---
assert_equal('Fix login bug', PromptParser::clean_pane_title('⠂ Fix login bug'), 'clean_pane_title: strips a leading spinner glyph (old braille-dot style)');
assert_equal('Fix login bug', PromptParser::clean_pane_title('⠐ Fix login bug'), 'clean_pane_title: strips a different spinner frame (old braille-dot style)');
assert_equal('Fix login bug', PromptParser::clean_pane_title('◐ Fix login bug'), 'clean_pane_title: strips the newer half-circle spinner glyph too');
assert_equal('Fix login bug', PromptParser::clean_pane_title('◑ Fix login bug'), 'clean_pane_title: strips a different half-circle spinner frame');
assert_equal('No spinner here', PromptParser::clean_pane_title('No spinner here'), 'clean_pane_title: leaves a plain title untouched');
assert_equal(null, PromptParser::clean_pane_title(''), 'clean_pane_title: empty title -> null (caller falls back to session name)');
assert_equal(null, PromptParser::clean_pane_title('   '), 'clean_pane_title: whitespace-only title -> null');
assert_equal('Fix login bug', PromptParser::clean_pane_title("\u{2733} Fix login bug"), 'clean_pane_title: strips the static idle marker glyph too, same as a real spinner frame');

// PromptParser::pane_title_is_working() - the pane-title-spinner-glyph
// "is it doing something right now" signal - was removed 2026-08-22 once
// UserPromptSubmit/PermissionRequest/Stop became SessionStatusStore's only
// source for working/idle/blocked (see SessionService::build_session_entry()
// and the todo file's research entry) - its own unit tests went with it.
// clean_pane_title() above is SPINNER_GLYPH_PATTERN's only remaining caller.

// --- PromptParser::detect_blocking_prompt(): flags a session stuck on an interactive
// prompt (folder trust, tool permission, ...) via the leading "❯ N."
// cursor Claude Code renders on the selected option, regardless of the
// exact prompt wording ---
assert_equal(null, PromptParser::detect_blocking_prompt("Just some normal output\nmore output\n"), 'detect_blocking_prompt: plain output -> not blocked');
assert_equal(
    'Do you trust the files in this folder?',
    PromptParser::detect_blocking_prompt("Do you trust the files in this folder?\n\n❯ 1. Yes, proceed\n  2. No, exit\n"),
    'detect_blocking_prompt: finds the question line directly above the choice list'
);
assert_equal(
    'Do you want to proceed?',
    PromptParser::detect_blocking_prompt("Some other line\n\nDo you want to proceed?\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: works for the tool-permission prompt shape too (a blank line separates it from unrelated context above, like a real capture)'
);
assert_equal(
    'no question line here',
    PromptParser::detect_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: falls back to the nearest context line, even without a "?", rather than a bare generic message'
);
assert_equal(
    'Waiting on an interactive prompt (permission or trust dialog)',
    PromptParser::detect_blocking_prompt("❯ 1. Yes\n  2. No\n"),
    'detect_blocking_prompt: only falls back to the generic message when there is truly no context above the choices'
);

// --- PromptParser::parse_blocking_prompt(): the fuller parse behind PromptParser::detect_blocking_prompt(),
// also extracting the surrounding context (the actual tool call / command /
// trust-dialog explanation, not just the bare question) and every numbered
// option, so a caller can render real Approve/Deny buttons with enough
// information to decide, not a blind rubber stamp (used by PromptInteractionService::answer_prompt()
// below). The two multi-line fixtures below are verbatim tmux capture-pane
// output from a real, live session - a real trust dialog and a real Bash
// permission prompt - captured specifically because the original
// "line ending in ?" heuristic silently failed to find the real trust
// dialog's question (it wraps: the "?" lands mid-line, not at the end of
// any single one). ---
assert_equal(null, PromptParser::parse_blocking_prompt("Just some normal output\nmore output\n"), 'parse_blocking_prompt: plain output -> null');

$realTrustDialog = " Accessing workspace:\n"
    . "\n"
    . " /tmp/sessioneer-prompt-inspect-1122606/scratch\n"
    . "\n"
    . " Quick safety check: Is this a project you created or one you trust? (Like your\n"
    . " own code, a well-known open source project, or work from your team). If not,\n"
    . " take a moment to review what's in this folder first.\n"
    . "\n"
    . " Claude Code'll be able to read, edit, and execute files here.\n"
    . "\n"
    . " Security guide\n"
    . "\n"
    . " ❯ 1. Yes, I trust this folder\n"
    . "   2. No, exit\n"
    . "\n"
    . " Enter to confirm · Esc to cancel\n";
$trustParsed = PromptParser::parse_blocking_prompt($realTrustDialog);
assert_equal(
    "Quick safety check: Is this a project you created or one you trust? (Like your own code, a well-known open source project, or work from your team). If not, take a moment to review what's in this folder first.",
    $trustParsed['question'] ?? null,
    'parse_blocking_prompt: real trust dialog - question is the FULL sentence, wrapped lines merged, not cut off at the first "?"'
);
assert_true(str_contains($trustParsed['context'] ?? '', 'Accessing workspace'), 'parse_blocking_prompt: real trust dialog - context includes the workspace path line');
assert_true(str_contains($trustParsed['context'] ?? '', "Claude Code'll be able to read"), 'parse_blocking_prompt: real trust dialog - context includes the explanation, not just the question');
assert_equal(
    [['number' => 1, 'label' => 'Yes, I trust this folder'], ['number' => 2, 'label' => 'No, exit']],
    $trustParsed['options'] ?? null,
    'parse_blocking_prompt: real trust dialog - both options extracted'
);

// Claude Code v2.1.269 dropped this dialog's option numbers entirely and
// reversed the default order (verified live 2026-09-11 - see PromptParser's
// own docblock and tests/fixtures/claude_folder_trust_prompt_pane_v2_1_269.txt,
// a verbatim capture from a real, never-before-trusted directory). This is
// the actual regression Andres hit ("can't start a session in a new
// folder"): the old digit-anchored parse below found nothing at all here,
// so the app never saw the trust dialog as blocking, and answer_prompt()'s
// digit-then-Enter would have confirmed whatever's default-selected instead
// ("No, exit" - killing the session) rather than the option the human meant.
$realTrustDialogV21269 = (string)file_get_contents(__DIR__ . '/fixtures/claude_folder_trust_prompt_pane_v2_1_269.txt');
$trustParsedV21269 = PromptParser::parse_blocking_prompt($realTrustDialogV21269);
assert_true($trustParsedV21269 !== null, 'parse_blocking_prompt: v2.1.269 unnumbered trust dialog is still recognized as a blocking prompt, not silently missed');
assert_equal(
    "Quick safety check: Is this a project you created or one you trust? (Like your own code, a well-known open source project, or work from your team). If not, take a moment to review what's in this folder first.",
    $trustParsedV21269['question'] ?? null,
    'parse_blocking_prompt: v2.1.269 trust dialog - question still extracted with no leading marker to anchor on'
);
assert_equal(
    [['number' => 1, 'label' => 'No, exit'], ['number' => 2, 'label' => 'Yes, I trust this folder']],
    $trustParsedV21269['options'] ?? null,
    'parse_blocking_prompt: v2.1.269 trust dialog - both options extracted from the unnumbered list, in their real (now reversed) on-screen order'
);
assert_equal(true, $trustParsedV21269['is_folder_trust'] ?? null, 'parse_blocking_prompt: v2.1.269 trust dialog - still flagged is_folder_trust despite having no numbers to key off');

$realPermissionPrompt = "● Bash(echo hello-permission-test > /tmp/sessioneer-permission-test.txt)\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . " Bash command\n"
    . "\n"
    . "   echo hello-permission-test > /tmp/sessioneer-permission-test.txt\n"
    . "   Write test string to a temp file\n"
    . "\n"
    . " Do you want to proceed?\n"
    . " ❯ 1. Yes\n"
    . "   2. Yes, and always allow access to tmp/ from this project\n"
    . "   3. No\n"
    . "\n"
    . " Esc to cancel · Tab to amend · ctrl+e to explain\n";
$permissionParsed = PromptParser::parse_blocking_prompt($realPermissionPrompt);
assert_equal('Do you want to proceed?', $permissionParsed['question'] ?? null, 'parse_blocking_prompt: real permission prompt - question found directly');
assert_true(str_contains($permissionParsed['context'] ?? '', 'echo hello-permission-test > /tmp/sessioneer-permission-test.txt'), 'parse_blocking_prompt: real permission prompt - context includes the actual command being approved');
assert_true(str_contains($permissionParsed['context'] ?? '', 'Write test string to a temp file'), 'parse_blocking_prompt: real permission prompt - context includes the tool-provided description');
assert_true(!str_contains($permissionParsed['context'] ?? '', str_repeat('─', 40)), 'parse_blocking_prompt: real permission prompt - the purely-decorative separator line is stripped');
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'Yes, and always allow access to tmp/ from this project'], ['number' => 3, 'label' => 'No']],
    $permissionParsed['options'] ?? null,
    'parse_blocking_prompt: real permission prompt - all three options extracted'
);

// --- PromptParser::parse_blocking_prompt(): a command/preview taller than the old fixed
// BLOCKING_PROMPT_CONTEXT_WINDOW (15 lines) used to have its earlier lines
// silently cut off - found live (Andres reported a truncated command).
// Fixed by finding the real top of the block via Claude Code's own "● "
// tool-invocation marker line instead of a fixed window. This script body
// is 20 lines, deliberately more than the window, with the marker at the
// very top. ---
$longCommandLines = [];
for ($i = 1; $i <= 20; $i++) {
    $longCommandLines[] = "  echo \"step {$i}\"";
}
$realLongPermissionPrompt = "● Bash(run a 20-step deploy script)\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . " Bash command\n"
    . "\n"
    . implode("\n", $longCommandLines) . "\n"
    . "\n"
    . " Do you want to proceed?\n"
    . " ❯ 1. Yes\n"
    . "   2. No\n";
$longPermissionParsed = PromptParser::parse_blocking_prompt($realLongPermissionPrompt);
assert_true(str_contains($longPermissionParsed['context'] ?? '', 'step 1"'), 'parse_blocking_prompt: a long (>15-line) command preview includes its FIRST line - the old fixed window would have cut this off');
assert_true(str_contains($longPermissionParsed['context'] ?? '', 'step 20"'), 'parse_blocking_prompt: a long command preview also includes its last line');

$parsedNoQuestion = PromptParser::parse_blocking_prompt("no question line here\n❯ 1. Yes\n  2. No\n");
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    $parsedNoQuestion['options'] ?? null,
    'parse_blocking_prompt: options still extracted even when no question line precedes them'
);
assert_equal(false, $parsedNoQuestion['multi_question'] ?? null, 'parse_blocking_prompt: not multi_question when there is no tab bar');

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a multi-question
// AskUserQuestion prompt - a tabbed interface Claude Code itself renders
// (one tab per question plus a trailing Submit tab, cycled with Left/Right
// keypresses - answered via PromptInteractionService::answer_multi_question() now,
// which computes and sends the whole tab sequence at once rather than
// navigating tab by tab), where each numbered option is followed by its
// own indented description
// line and a purely decorative divider precedes the last option. Captured
// specifically because the original option-parsing loop (which stopped
// at the first line that didn't look like a numbered option) silently
// dropped every option after the first real one's description line. ---
$realMultiQuestion = "❯ Use the AskUserQuestion tool right now to ask me two separate questions at\n"
    . "  once, each with 2 short options: question 1 about favorite color (red or\n"
    . "  blue), question 2 about favorite animal (cat or dog).\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☐ Color  ☐ Animal  ✔ Submit  →\n"
    . "\n"
    . "What's your favorite color?\n"
    . "\n"
    . "❯ 1. Red\n"
    . "     Favorite color is red\n"
    . "  2. Blue\n"
    . "     Favorite color is blue\n"
    . "  3. Type something.\n"
    . str_repeat('─', 40) . "\n"
    . "  4. Chat about this\n"
    . "\n"
    . "Enter to select · Tab/Arrow keys to navigate · Esc to cancel\n";
$multiQuestionParsed = PromptParser::parse_blocking_prompt($realMultiQuestion);
assert_equal("What's your favorite color?", $multiQuestionParsed['question'] ?? null, 'parse_blocking_prompt: real multi-question prompt - question for the current tab found');
assert_equal(
    [
        ['number' => 1, 'label' => 'Red'],
        ['number' => 2, 'label' => 'Blue'],
        ['number' => 3, 'label' => 'Type something.'],
        ['number' => 4, 'label' => 'Chat about this'],
    ],
    $multiQuestionParsed['options'] ?? null,
    'parse_blocking_prompt: real multi-question prompt - all four options found despite interleaved description lines and a divider'
);
assert_equal(true, $multiQuestionParsed['multi_question'] ?? null, 'parse_blocking_prompt: real multi-question prompt - tab bar detected');

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a multi-question
// AskUserQuestion's own "Submit" review tab (found live 2026-08-09: Andres
// reported what looked like a redundant second confirmation on this exact
// screen - see this app's session.php, the "Awaiting approval" pending-
// context card immediately followed by a "Waiting on input" card asking
// what read as the same thing again). The nearest-● scan alone finds only
// the LAST answered question's own bullet, silently dropping every
// earlier one (and the "Review your answers"/tab-bar lines) from context -
// this is what made a complete two-question review look like a single,
// already-answered, oddly-repeated mini-prompt instead. ---
$realSubmitReview = " One quiet line in the AI-tooling section linking to the repo.\n"
    . "\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☒ Visibility  ☒ Repo name  ✔ Submit  →\n"
    . "\n"
    . "Review your answers\n"
    . "\n"
    . " ● Repo visibility — your phone number and personal email live in the content fragments (header.md). A public repo gets indexed/cached fast, which is hard to undo.\n"
    . "   → Public, but redact contact info from the repo\n"
    . " ● What should the GitHub repo be named?\n"
    . "   → resume\n"
    . "\n"
    . "Ready to submit your answers?\n"
    . "\n"
    . "❯ 1. Submit answers\n"
    . "  2. Cancel\n";
$submitReviewParsed = PromptParser::parse_blocking_prompt($realSubmitReview);
assert_equal('Ready to submit your answers?', $submitReviewParsed['question'] ?? null, 'parse_blocking_prompt: Submit review tab - question is the final "ready to submit" line');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'Review your answers'), 'parse_blocking_prompt: Submit review tab - context includes the "Review your answers" header, not just the last bullet');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'Repo visibility'), 'parse_blocking_prompt: Submit review tab - context includes the FIRST answered question\'s own bullet');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'Public, but redact contact info from the repo'), 'parse_blocking_prompt: Submit review tab - context includes the first question\'s real answer');
assert_true(str_contains($submitReviewParsed['context'] ?? '', 'What should the GitHub repo be named?'), 'parse_blocking_prompt: Submit review tab - context still includes the last question\'s own bullet too');
assert_true(str_contains($submitReviewParsed['context'] ?? '', "→ resume"), 'parse_blocking_prompt: Submit review tab - context still includes the last question\'s real answer too');
assert_equal(true, $submitReviewParsed['multi_question'] ?? null, 'parse_blocking_prompt: Submit review tab - still detected as multi_question (tab bar pulled into context alongside "Review your answers"), so Prev/Next navigation back to an earlier question stays available');
assert_equal(
    [['number' => 1, 'label' => 'Submit answers'], ['number' => 2, 'label' => 'Cancel']],
    $submitReviewParsed['options'] ?? null,
    'parse_blocking_prompt: Submit review tab - both real options extracted'
);

// --- PromptParser::parse_blocking_prompt(): a real, live capture of a
// RESHOWN single-question tab of a multi-question AskUserQuestion (found
// live 2026-08-09, same investigation as the Submit-review-tab case above -
// navigating back to an earlier tab has no tool-invocation marker of its
// own at all, unlike a fresh permission prompt). The nearest-● scan used
// to walk straight through the decorative separator above the tab bar and
// land on the PRECEDING, unrelated assistant message's own "● " bullet -
// Claude Code prefixes plain assistant TEXT with "● " too, not just tool
// invocations - sweeping a whole unrelated earlier paragraph into context.
// A first attempt at fixing this (stop the scan at ANY decorative
// separator) overcorrected and broke the long-command-preview case above,
// since a genuine tool invocation ALSO uses one internally, between its
// own marker and its own detail box - the real fix only stops at a
// separator once a multi-question tab bar has already been seen below
// it. ---
$realReshowTab = " On the \"built with AI, including the toolchain\" question: I'd do it — lightly. It's not cheeky if it's true and verifiable, and yours is: the resume already has a dedicated AI-tooling section\n"
    . "citing specific things you built (subagents, hooks, sessioneer). A one-line link to this actual repo as another concrete example is proof, not a claim.\n"
    . "\n"
    . "One real concern before I create/push a public repo: your phone number and personal email are in the content fragments. A public GitHub repo gets scraped/cached essentially immediately and that's\n"
    . "hard to fully undo. Let me get that sorted with you before I touch GitHub.\n"
    . str_repeat('─', 40) . "\n"
    . "←  ☒ Visibility  ☒ Repo name  ✔ Submit  →\n"
    . "\n"
    . "Repo visibility — your phone number and personal email live in the content fragments (header.md). A public repo gets indexed/cached fast, which is hard to undo.\n"
    . "\n"
    . "❯ 1. Public, but redact contact info from the repo\n"
    . "     Keep phone/email out of the committed fragments, inject real values only at build time.\n"
    . "  2. Public as-is\n"
    . "  3. Private repo\n"
    . "  4. Type something.\n";
$reshowParsed = PromptParser::parse_blocking_prompt($realReshowTab);
assert_true(
    !str_contains($reshowParsed['context'] ?? '', 'built with AI'),
    'parse_blocking_prompt: a reshown tab\'s context does NOT include the unrelated PRECEDING assistant message - it has no marker of its own, and the scan must not cross the separator above the tab bar to go find one'
);
assert_true(
    !str_contains($reshowParsed['context'] ?? '', 'public GitHub repo gets scraped'),
    'parse_blocking_prompt: a reshown tab\'s context does not include ANY of that preceding paragraph, not just its first line'
);
assert_true(str_contains($reshowParsed['context'] ?? '', '←  ☒ Visibility'), 'parse_blocking_prompt: a reshown tab\'s context DOES include its own tab bar');
assert_true(str_contains($reshowParsed['context'] ?? '', 'Repo visibility —'), 'parse_blocking_prompt: a reshown tab\'s context DOES include its own question');
assert_equal(true, $reshowParsed['multi_question'] ?? null, 'parse_blocking_prompt: a reshown tab is still correctly detected as multi_question');

// --- PromptParser::build_options_from_permission_suggestions() /
// classify_permission_option_intent(): the guessed-menu builder and the
// intent classifier PromptInteractionService::answer_prompt() uses to
// reconcile that guess against a real pane-scrape (see its own docblock
// for the live incident this whole pair exists to catch). ---
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    PromptParser::build_options_from_permission_suggestions([]),
    'build_options_from_permission_suggestions: no suggestions -> plain Yes/No'
);
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => "Yes, and don't ask again for: rm -rf /tmp/scratch"], ['number' => 3, 'label' => 'No']],
    PromptParser::build_options_from_permission_suggestions([['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'rm -rf /tmp/scratch']], 'behavior' => 'allow', 'destination' => 'session']]),
    'build_options_from_permission_suggestions: a single addRules suggestion gets its own middle option'
);
assert_equal(
    [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    PromptParser::build_options_from_permission_suggestions([['type' => 'setMode', 'mode' => 'plan']]),
    'build_options_from_permission_suggestions: a setMode suggestion this app has no label for is simply not offered'
);

assert_equal('yes_once', PromptParser::classify_permission_option_intent('Yes'), 'classify_permission_option_intent: bare "Yes"');
assert_equal('yes_once', PromptParser::classify_permission_option_intent('  yes  '), 'classify_permission_option_intent: case/whitespace-insensitive');
assert_equal('yes_always', PromptParser::classify_permission_option_intent("Yes, and don't ask again for: rm -rf /tmp/scratch"), 'classify_permission_option_intent: addRules-suggestion label');
assert_equal('yes_always', PromptParser::classify_permission_option_intent('Yes, and switch to accept edits (auto-approve file edits and common file commands) for this session (shift+tab)'), 'classify_permission_option_intent: setMode-suggestion label');
assert_equal('no', PromptParser::classify_permission_option_intent('No'), 'classify_permission_option_intent: bare "No"');
assert_equal('no', PromptParser::classify_permission_option_intent('No, and tell Claude what to do differently'), 'classify_permission_option_intent: Claude Code\'s longer real "No" phrasing');
assert_equal('unknown', PromptParser::classify_permission_option_intent('Type something else'), 'classify_permission_option_intent: a free-text-style label is neither yes nor no');

// --- PermissionMode::parse_current_mode(): reads Claude Code's own bottom status line -
// mode names and cycle order confirmed live against a real running
// session (Shift+Tab cycles manual -> accept edits -> plan -> auto). ---
assert_equal('manual', PermissionMode::parse_current_mode("  user@host ~\n  \xE2\x8F\xB8 manual mode on \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: manual');
assert_equal('accept edits', PermissionMode::parse_current_mode("  \xE2\x8F\xB5\xE2\x8F\xB5 accept edits on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: accept edits - the one mode whose status line omits the word "mode" entirely');
assert_equal('plan', PermissionMode::parse_current_mode("  \xE2\x8F\xB8 plan mode on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: plan');
assert_equal('auto', PermissionMode::parse_current_mode("  \xE2\x8F\xB5\xE2\x8F\xB5 auto mode on (shift+tab to cycle) \xC2\xB7 \xE2\x86\x90 for agents\n"), 'parse_current_mode: auto');
assert_equal(null, PermissionMode::parse_current_mode("Just some normal output\nmore output\n"), 'parse_current_mode: no status line -> null');
assert_equal(null, PermissionMode::parse_current_mode($realTrustDialog), 'parse_current_mode: a blocking prompt covering the status line -> null, not a false match');

// --- TmuxService::tmux_attach_hint(): the exact command shown to a human to go answer
// a blocked prompt themselves ---
assert_equal('tmux -S ' . Config::tmux_socket() . ' attach -t cc-example', TmuxService::tmux_attach_hint('cc-example'), 'tmux_attach_hint: uses the configured socket path');

// --- SessionLifecycleService::generate_uuid_v4(): the id passed to `claude --session-id` at launch ---
$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
assert_true(preg_match($uuidPattern, SessionLifecycleService::generate_uuid_v4()) === 1, 'generate_uuid_v4: matches the RFC 4122 v4 shape');
assert_true(SessionLifecycleService::generate_uuid_v4() !== SessionLifecycleService::generate_uuid_v4(), 'generate_uuid_v4: two calls produce different ids');

// --- SessionService::browse_dir(): powers the New Session folder browser, walking from
// WWW_ROOT up to (but never past) HOME_ROOT ---
$result = SessionService::browse_dir(Config::www_root());
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(www_root): ok=true');
assert_equal(['.hidden-dir', 'project-a', 'project-b'], $result['dirs'] ?? null, 'SessionService::browse_dir(www_root): includes hidden dirs, sorted');

$result = SessionService::browse_dir(Config::www_root() . '/project-a');
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(project-a): ok=true');
assert_equal(['nested'], $result['dirs'] ?? null, 'SessionService::browse_dir(project-a): lists its one subfolder');
assert_equal(Config::www_root(), $result['parent'] ?? null, 'SessionService::browse_dir(project-a): parent is WWW_ROOT');

$result = SessionService::browse_dir(Config::www_root() . '/project-a/nested');
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(nested): ok=true');
assert_equal([], $result['dirs'] ?? null, 'SessionService::browse_dir(nested): no subfolders');
assert_equal(Config::www_root() . '/project-a', $result['parent'] ?? null, 'SessionService::browse_dir(nested): parent is project-a');

$result = SessionService::browse_dir(Config::home_root());
assert_true($result['ok'] ?? false, 'SessionService::browse_dir(home_root): ok=true');
assert_equal(null, $result['parent'], 'SessionService::browse_dir(home_root): parent is null - can\'t go up further');

$result = SessionService::browse_dir('/etc');
assert_equal(false, $result['ok'] ?? null, 'SessionService::browse_dir(/etc): rejects a path outside home_root');

$result = SessionService::browse_dir(Config::www_root() . '/does-not-exist');
assert_equal(false, $result['ok'] ?? null, 'SessionService::browse_dir(missing dir): rejects a nonexistent path');

// --- SessionService::create_dir(): the "New folder" button on the same folder browser ---
$newDirPath = Config::www_root() . '/sessioneer-test-new-folder';
@rmdir($newDirPath); // in case a previous failed run left it behind

$result = SessionService::create_dir(Config::www_root(), 'sessioneer-test-new-folder');
assert_true($result['ok'] ?? false, 'SessionService::create_dir: ok=true');
assert_true(is_dir($newDirPath), 'SessionService::create_dir: the directory really exists on disk afterward');
assert_equal($newDirPath, $result['path'] ?? null, 'SessionService::create_dir: response is browse_dir() of the new folder itself - path is the new folder');
assert_equal([], $result['dirs'] ?? null, 'SessionService::create_dir: the new (empty) folder has no subfolders');
assert_equal(Config::www_root(), $result['parent'] ?? null, 'SessionService::create_dir: parent is the folder it was created in');

$result = SessionService::create_dir(Config::www_root(), 'sessioneer-test-new-folder');
assert_equal(false, $result['ok'] ?? null, 'SessionService::create_dir: rejects a name that already exists');

$result = SessionService::create_dir(Config::www_root(), '../sessioneer-test-escape');
assert_equal(false, $result['ok'] ?? null, 'SessionService::create_dir: rejects a name containing path traversal');

$result = SessionService::create_dir(Config::www_root(), 'nested/sessioneer-test-escape');
assert_equal(false, $result['ok'] ?? null, 'SessionService::create_dir: rejects a name containing a slash');

$result = SessionService::create_dir(Config::www_root(), '');
assert_equal(false, $result['ok'] ?? null, 'SessionService::create_dir: rejects an empty name');

$result = SessionService::create_dir('/etc', 'sessioneer-test-escape');
assert_equal(false, $result['ok'] ?? null, 'SessionService::create_dir: rejects a parent path outside home_root');

@rmdir($newDirPath);

// --- PlanFileService::list_plan_files(): the sidebar's "Plan/handoff
// files" glance - *.md files directly in a session's own cwd (never
// subdirectories), README.md/CLAUDE.md excluded, sorted most-recently-
// modified first. No real tmux pane needed - this only reads the
// sidecar's own recorded workdir, same as UploadService's own
// session_workdir() lookup. ---
assert_equal(false, PlanFileService::list_plan_files('cc-not-a-real-session')['ok'] ?? null, 'list_plan_files: rejects a session with no sidecar (unknown workdir)');

$planFilesSession = 'cc-test-plan-files-' . getmypid();
$planFilesDir = Config::www_root() . '/project-a';
SidecarStore::write_sidecar($planFilesSession, ['workdir' => $planFilesDir, 'spawned_at' => time()]);

file_put_contents($planFilesDir . '/README.md', 'readme');
file_put_contents($planFilesDir . '/CLAUDE.md', 'claude md');
file_put_contents($planFilesDir . '/notes.txt', 'not markdown');
file_put_contents($planFilesDir . '/nested/deep-plan.md', 'nested - must not appear, non-recursive');
file_put_contents($planFilesDir . '/older-plan.md', 'older');
touch($planFilesDir . '/older-plan.md', time() - 3600);
file_put_contents($planFilesDir . '/PLAN.md', 'newer');
touch($planFilesDir . '/PLAN.md', time());

$planFilesResult = PlanFileService::list_plan_files($planFilesSession);
assert_true($planFilesResult['ok'] ?? false, 'list_plan_files: ok=true for a session with a real, known workdir');
$planFileNames = array_column($planFilesResult['files'] ?? [], 'name');
assert_equal(['PLAN.md', 'older-plan.md'], $planFileNames, 'list_plan_files: only top-level *.md files, README.md/CLAUDE.md excluded, sorted most-recently-modified first');

$planFileSizes = array_column($planFilesResult['files'] ?? [], 'size', 'name');
assert_equal(strlen('newer'), $planFileSizes['PLAN.md'] ?? null, 'list_plan_files: reports the real file size');

// --- PlanFileService::read_plan_file(): the sidebar's new-tab link target
// for a plan file - re-validates the .md/README/CLAUDE.md rules itself
// rather than trusting a caller-supplied filename just because it looks
// like something list_plan_files() would have returned. ---
assert_equal(false, PlanFileService::read_plan_file('cc-not-a-real-session', 'PLAN.md')['ok'] ?? null, 'read_plan_file: rejects a session with no sidecar (unknown workdir)');

$readPlanFileResult = PlanFileService::read_plan_file($planFilesSession, 'PLAN.md');
assert_true($readPlanFileResult['ok'] ?? false, 'read_plan_file: ok=true for a real plan file');
assert_equal('newer', base64_decode((string)($readPlanFileResult['data'] ?? ''), true), 'read_plan_file: returns the real file content, base64-encoded');
assert_equal('text/markdown; charset=utf-8', $readPlanFileResult['media_type'] ?? null, 'read_plan_file: reports a markdown media_type');

assert_equal(false, PlanFileService::read_plan_file($planFilesSession, 'notes.txt')['ok'] ?? null, 'read_plan_file: rejects a non-.md file even though it really exists in the workdir');
assert_equal(false, PlanFileService::read_plan_file($planFilesSession, 'README.md')['ok'] ?? null, 'read_plan_file: rejects README.md even though it really exists - same exclusion as list_plan_files()');
assert_equal(false, PlanFileService::read_plan_file($planFilesSession, 'CLAUDE.md')['ok'] ?? null, 'read_plan_file: rejects CLAUDE.md too');
assert_equal(false, PlanFileService::read_plan_file($planFilesSession, 'does-not-exist.md')['ok'] ?? null, 'read_plan_file: rejects a filename that does not exist on disk');
assert_equal(false, PlanFileService::read_plan_file($planFilesSession, '../../../../etc/passwd')['ok'] ?? null, 'read_plan_file: rejects a path-traversal attempt (also fails the .md check)');
assert_equal(false, PlanFileService::read_plan_file($planFilesSession, 'nested/deep-plan.md')['ok'] ?? null, 'read_plan_file: rejects a subdirectory path, not just top-level filenames (basename() strips the directory part, so this resolves to a nonexistent top-level file)');

// --- PlanFileService::read_todo_file(): the sidebar's "Open todo file"
// link - reads the session's cwd-level `todo` file (no extension, unlike
// the *.md plan-file glance above). Independent of workdir existence and
// of the .md/README/CLAUDE.md rules. ---
assert_equal(false, PlanFileService::read_todo_file('cc-not-a-real-session')['ok'] ?? null, 'read_todo_file: rejects a session with no sidecar (unknown workdir)');

$todoFileSession = 'cc-test-todo-file-' . getmypid();
$todoFileDir = Config::www_root() . '/project-b';
SidecarStore::write_sidecar($todoFileSession, ['workdir' => $todoFileDir, 'spawned_at' => time()]);

assert_equal(false, PlanFileService::read_todo_file($todoFileSession)['ok'] ?? null, 'read_todo_file: ok=false when the cwd has no todo file yet');

file_put_contents($todoFileDir . '/todo', "- job one\n- job two\n");
$readTodoResult = PlanFileService::read_todo_file($todoFileSession);
assert_true($readTodoResult['ok'] ?? false, 'read_todo_file: ok=true for a real todo file');
assert_equal("- job one\n- job two\n", base64_decode((string)($readTodoResult['data'] ?? ''), true), 'read_todo_file: returns the real todo file content, base64-encoded');
assert_equal('todo', $readTodoResult['filename'] ?? null, 'read_todo_file: reports the filename');
assert_equal('text/plain; charset=utf-8', $readTodoResult['media_type'] ?? null, 'read_todo_file: reports a plain-text media_type');

@unlink($todoFileDir . '/todo');
SidecarStore::delete_sidecar($todoFileSession);

@unlink($planFilesDir . '/README.md');
@unlink($planFilesDir . '/CLAUDE.md');
@unlink($planFilesDir . '/notes.txt');
@unlink($planFilesDir . '/nested/deep-plan.md');
@unlink($planFilesDir . '/older-plan.md');
@unlink($planFilesDir . '/PLAN.md');
SidecarStore::delete_sidecar($planFilesSession);

$missingWorkdirSession = 'cc-test-plan-files-missing-' . getmypid();
SidecarStore::write_sidecar($missingWorkdirSession, ['workdir' => Config::www_root() . '/does-not-exist', 'spawned_at' => time()]);
$missingWorkdirResult = PlanFileService::list_plan_files($missingWorkdirSession);
assert_true($missingWorkdirResult['ok'] ?? false, 'list_plan_files: ok=true even when the workdir no longer exists on disk');
assert_equal([], $missingWorkdirResult['files'] ?? null, 'list_plan_files: empty list for a missing workdir, not an error');
SidecarStore::delete_sidecar($missingWorkdirSession);

try {
    // --- create ---
    $created = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    assert_true($created['ok'], 'create: ok=true');
    assert_true($created['name'] !== null, 'create: session name parsed from message');
    $name = $created['name'];

    // --- list sees it, sidecar + pid matching worked ---
    $session = $name !== null ? find_session($name) : null;
    assert_true($session !== null, 'list: created session appears');
    assert_equal(Config::www_root() . '/project-a', $session['workdir'] ?? null, 'list: workdir recorded via sidecar');
    assert_true($session['spawned_by_app'] ?? false, 'list: spawned_by_app is true');
    assert_true(($session['pid'] ?? null) !== null, 'list: pane process pid matched via argv[0]');
    // fake_claude (behaves like /bin/cat) never sets a terminal title like the
    // real claude CLI does, so its content isn't asserted here - only that
    // SessionService::list_all_sessions() always includes the key. The stripping behavior
    // itself is covered deterministically by the PromptParser::clean_pane_title() checks above.
    assert_true(array_key_exists('title', $session ?? []), 'list: title key present');
    assert_true(preg_match($uuidPattern, (string)($session['agent_session_id'] ?? '')) === 1, 'list: agent_session_id recorded via sidecar, uuid-shaped');

    // --- create: $enableTaskTools appends --allowedTools naming the
    // TaskCreate/TaskGet/TaskList/TaskUpdate family (Andres's own ask,
    // 2026-08-23 - the New Session form's opt-in checkbox) - verified via
    // tmux's own #{pane_start_command}, which reflects the FULL original
    // spawn argv regardless of what the pane's command does with them
    // afterward (fake_claude deliberately discards its own args and execs
    // straight into `cat` - see its own header comment - so
    // /proc/<pid>/cmdline alone would never show this).
    //
    // sleep(1) here (and between the two create_and_track() calls below) -
    // found live writing this test: create_agent_session()'s name is
    // date('Ymd-His')-based (1-second resolution), so a create landing in
    // the SAME wall-clock second as the $created one already made a few
    // lines up (or as the second call just below) collides on an identical
    // name and tmux's own new-session rejects it outright as a duplicate -
    // deterministic spacing avoids depending on how fast the intervening
    // assertions happen to run. ---
    sleep(1);
    $withTaskTools = create_and_track(Config::www_root() . '/project-a', $createdSessions, true);
    assert_true($withTaskTools['ok'], 'create (enableTaskTools=true): ok=true');
    $taskToolsPaneCommand = $withTaskTools['name'] !== null
        ? trim(TmuxService::tmux_run(['list-panes', '-t', $withTaskTools['name'], '-F', '#{pane_start_command}'])['stdout'])
        : '';
    assert_true(
        str_contains($taskToolsPaneCommand, '--allowedTools TaskCreate,TaskGet,TaskList,TaskUpdate'),
        'create (enableTaskTools=true): the spawned pane\'s actual command line carries --allowedTools naming the Task tool family'
    );

    if (is_string($withTaskTools['name'])) {
        SessionLifecycleService::kill_agent_session($withTaskTools['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$withTaskTools['name']]));
    }

    sleep(1);
    $withoutTaskTools = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    $noTaskToolsPaneCommand = $withoutTaskTools['name'] !== null
        ? trim(TmuxService::tmux_run(['list-panes', '-t', $withoutTaskTools['name'], '-F', '#{pane_start_command}'])['stdout'])
        : '';
    assert_true(
        !str_contains($noTaskToolsPaneCommand, '--allowedTools'),
        'create (enableTaskTools omitted, defaults to false): no --allowedTools flag at all - the default spawn command is unchanged'
    );

    assert_true(
        !str_contains($noTaskToolsPaneCommand, '--permission-mode'),
        'create (startingMode omitted, defaults to null): no --permission-mode flag either - confirms the same default spawn command covers both opt-in flags being absent'
    );

    if (is_string($withoutTaskTools['name'])) {
        SessionLifecycleService::kill_agent_session($withoutTaskTools['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$withoutTaskTools['name']]));
    }

    // --- create: $startingMode appends --permission-mode, translated from
    // this app's own vocabulary ("accept edits") to Claude Code's real enum
    // value ("acceptEdits") via array_flip(PermissionMode::
    // HOOK_PERMISSION_MODE_MAP) - same #{pane_start_command} verification
    // technique as the task-tools block above. ---
    sleep(1);
    $withStartingMode = create_and_track(Config::www_root() . '/project-a', $createdSessions, false, 'accept edits');
    assert_true($withStartingMode['ok'], 'create (startingMode="accept edits"): ok=true');
    $startingModePaneCommand = $withStartingMode['name'] !== null
        ? trim(TmuxService::tmux_run(['list-panes', '-t', $withStartingMode['name'], '-F', '#{pane_start_command}'])['stdout'])
        : '';
    assert_true(
        str_contains($startingModePaneCommand, '--permission-mode acceptEdits'),
        'create (startingMode="accept edits"): the spawned pane\'s actual command line carries --permission-mode, translated to Claude Code\'s real "acceptEdits" enum value'
    );

    if (is_string($withStartingMode['name'])) {
        SessionLifecycleService::kill_agent_session($withStartingMode['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$withStartingMode['name']]));
    }

    // A starting mode outside this app's own known vocabulary (malformed/
    // unexpected input - the host-agent's own whitelist re-check, same
    // discipline as every other state-changing action here) is silently
    // ignored rather than passed through raw to the real CLI.
    sleep(1);
    $withBogusMode = create_and_track(Config::www_root() . '/project-a', $createdSessions, false, 'not-a-real-mode');
    assert_true($withBogusMode['ok'], 'create (startingMode="not-a-real-mode"): still ok=true, not rejected outright');
    $bogusModePaneCommand = $withBogusMode['name'] !== null
        ? trim(TmuxService::tmux_run(['list-panes', '-t', $withBogusMode['name'], '-F', '#{pane_start_command}'])['stdout'])
        : '';
    assert_true(
        !str_contains($bogusModePaneCommand, '--permission-mode'),
        'create (startingMode="not-a-real-mode"): an unrecognized mode is silently ignored, not passed through raw to the real CLI'
    );

    if (is_string($withBogusMode['name'])) {
        SessionLifecycleService::kill_agent_session($withBogusMode['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$withBogusMode['name']]));
    }

    // --- TmuxService::list_all_tmux_sessions()'s 'activity' field: sourced
    // from #{window_activity}, not tmux's own #{session_activity} - found
    // live 2026-08-08 on this app's own real session (a live, actively-
    // working session's dashboard row looked stale/"detached" - Andres
    // noticed while dogfooding). #{session_activity} does NOT reliably
    // advance from real, continuous pane output (confirmed live: stayed
    // frozen at spawn time for 8+ real hours of heavy use on the actual
    // session that surfaced this), while #{window_activity} does. This
    // needs a REAL tmux session (the bug is real tmux behavior, not
    // something a fake tmux binary could ever exhibit) - reuses $name
    // from the create block above, real fake_claude (behaves like `cat`)
    // pane, real send-keys. ---
    $activityBeforeSend = $session['activity'] ?? null;
    sleep(2); // ensure the next timestamp is actually distinguishable
    if ($name !== null) {
        TmuxService::tmux_run(['send-keys', '-t', $name, 'activity freshness probe', 'Enter']);
    }
    usleep(300000);
    $sessionAfterSend = $name !== null ? find_session($name) : null;
    assert_true(
        $sessionAfterSend !== null && $activityBeforeSend !== null && ($sessionAfterSend['activity'] ?? 0) > $activityBeforeSend,
        'list: activity timestamp actually advances after real pane output, not frozen at session-creation time'
    );

    // --- SessionDetailService::session_detail(): the same re-derived-from-a-live-scan data as one
    // list() row, plus has_transcript - fake_claude never actually writes a
    // real ~/.claude/projects transcript, so has_transcript is expected
    // false here (see test_transcript.php for the file-found path) ---
    $detail = $name !== null ? SessionDetailService::session_detail($name) : ['ok' => false];
    assert_true($detail['ok'] ?? false, 'session_detail: ok=true for a live session');
    assert_equal($session['agent_session_id'] ?? null, $detail['agent_session_id'] ?? null, 'session_detail: same agent_session_id as list()');
    assert_equal(false, $detail['has_transcript'] ?? null, 'session_detail: has_transcript=false (no real transcript file exists for this fixture)');

    $missingDetail = SessionDetailService::session_detail('cc-not-a-real-session');
    assert_equal(false, $missingDetail['ok'] ?? null, 'session_detail: rejects a name that is not currently live');

    // --- SessionDetailService::session_history(): a agent_session_id is recorded, but with no
    // real transcript file behind it (fake_claude doesn't write one) this
    // must fail gracefully, not error out ---
    $history = $name !== null ? SessionDetailService::session_history($name, null, 10) : ['ok' => true];
    assert_equal(false, $history['ok'] ?? null, 'session_history: ok=false when no transcript file exists for a recorded agent_session_id');

    $noSidecarHistory = SessionDetailService::session_history('cc-not-a-real-session', null, 10);
    assert_equal(false, $noSidecarHistory['ok'] ?? null, 'session_history: ok=false for a session with no sidecar at all');

    // --- ArchivedSessionService::list_archived_dashboard(): excludes whatever's
    // currently tracked (this live session's own agent_session_id),
    // leaving only genuinely dormant transcripts. Real transcript files
    // are needed for this (fake_claude never writes one), so HOME_ROOT is
    // pointed at an isolated fixture dir just for this block - same
    // pattern as test_transcript.php's fakeHome, restored right after. ---
    $archivedFakeHome = sys_get_temp_dir() . '/sessioneer-test-archived-dashboard-home-' . getmypid();
    $liveAgentSessionId = $session['agent_session_id'] ?? null;
    $archivedUuid = '33333333-3333-4333-8333-333333333333';
    @mkdir($archivedFakeHome . '/.claude/projects/-tracked-project', 0700, true);
    @mkdir($archivedFakeHome . '/.claude/projects/-archived-project', 0700, true);
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-tracked-project/' . $liveAgentSessionId . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/tracked/path"}' . "\n"
    );
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-archived-project/' . $archivedUuid . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/archived/path"}' . "\n"
    );
    putenv("HOME_ROOT={$archivedFakeHome}");

    $dashboardArchived = ArchivedSessionService::list_archived_dashboard()['archived'] ?? [];
    $archivedIds = array_column($dashboardArchived, 'agent_session_id');
    assert_true(!in_array($liveAgentSessionId, $archivedIds, true), 'list_archived_dashboard: the currently-tracked (live) session is excluded');
    assert_true(in_array($archivedUuid, $archivedIds, true), 'list_archived_dashboard: a genuinely dormant transcript is included');

    @unlink($archivedFakeHome . '/.claude/projects/-tracked-project/' . $liveAgentSessionId . '.jsonl');
    @unlink($archivedFakeHome . '/.claude/projects/-archived-project/' . $archivedUuid . '.jsonl');
    @rmdir($archivedFakeHome . '/.claude/projects/-tracked-project');
    @rmdir($archivedFakeHome . '/.claude/projects/-archived-project');

    // --- Same two fixes, the BARE-process half: a bare (untracked, no
    // sidecar) claude process whose live agent_session_id is confidently
    // known via the statusline marker must ALSO be excluded from
    // "archived", and resume_agent_session() must ALSO refuse to resume
    // it. Found live 2026-09-11 (Andres: his own real terminal session -
    // no tmux, no sidecar at all - showed up as "archived" and "resuming"
    // it from there made no sense) - see BareProcessService::
    // live_bare_agent_session_ids()'s own docblock for the full incident.
    // Reuses the exact marker-matched bare-process technique the
    // take-over tests below use (a fake_claude pane fed a literal
    // "sessioneer-data:{...}" line), just to exercise these two OTHER
    // call sites instead. ---
    $archivedBareUuid = '44444444-4444-4444-8444-444444444444';
    @mkdir($archivedFakeHome . '/.claude/projects/-bare-project', 0700, true);
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-bare-project/' . $archivedBareUuid . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/bare/path"}' . "\n"
    );
    $archivedBareAdhocName = 'sessioneer-test-archived-bare-' . getmypid();
    $archivedBareSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $archivedBareAdhocName, '-c', Config::www_root(), Config::claude_bin()]);
    assert_equal(0, $archivedBareSetup['exit'], 'bare-liveness setup: created a live non-cc-* tmux session hosting a fake_claude process');
    usleep(300000);
    TmuxService::tmux_run(['send-keys', '-t', $archivedBareAdhocName, 'sessioneer-data:{"session_id":"' . $archivedBareUuid . '"}', 'Enter']);
    usleep(300000);

    $dashboardArchivedWithBare = ArchivedSessionService::list_archived_dashboard()['archived'] ?? [];
    $archivedIdsWithBare = array_column($dashboardArchivedWithBare, 'agent_session_id');
    assert_true(!in_array($archivedBareUuid, $archivedIdsWithBare, true), 'list_archived_dashboard: a BARE (untracked) process\'s own live session is excluded too, not just a tracked one');

    $bareResumeAttempt = SessionLifecycleService::resume_agent_session(Config::www_root(), $archivedBareUuid);
    assert_equal(false, $bareResumeAttempt['ok'] ?? null, 'resume_agent_session: refuses a agent_session_id that is currently live via a BARE process, not just a tracked one');

    // --- BareProcessService::resolve_bare_process_detail(): the dashboard's
    // "Identify" button - a marker-matched bare process (this exact fixture)
    // resolves with confidence='confirmed', not the weaker heuristic guess. ---
    $archivedBarePid = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['tmux_session'] ?? null) === $archivedBareAdhocName) {
            $archivedBarePid = (int)$b['pid'];
            break;
        }
    }
    assert_true($archivedBarePid !== null, 'resolve_bare_process_detail setup: the fixture bare process is visible as bare');
    $bareDetail = BareProcessService::resolve_bare_process_detail($archivedBarePid ?? 0);
    assert_equal(true, $bareDetail['ok'] ?? null, 'resolve_bare_process_detail: ok=true for a currently running process');
    assert_equal($archivedBareUuid, $bareDetail['agent_session_id'] ?? null, 'resolve_bare_process_detail: resolves the marker-matched agent_session_id');
    assert_equal('confirmed', $bareDetail['confidence'] ?? null, 'resolve_bare_process_detail: a marker match is reported as confirmed, not a guess');

    $missingBareDetail = BareProcessService::resolve_bare_process_detail(0);
    assert_equal(false, $missingBareDetail['ok'] ?? null, 'resolve_bare_process_detail: ok=false for a pid that is not a currently running claude process');

    TmuxService::tmux_run(['kill-session', '-t', $archivedBareAdhocName]);
    $archivedBareAdhocName = null;
    @unlink($archivedFakeHome . '/.claude/projects/-bare-project/' . $archivedBareUuid . '.jsonl');
    @rmdir($archivedFakeHome . '/.claude/projects/-bare-project');

    // --- BareProcessService::agent_session_id_from_resume_arg() (via
    // resolve_bare_process_detail()/live_bare_agent_session_ids()): a bare
    // process started with an explicit --resume/--session-id resolves with
    // confidence='confirmed' straight from its own argv - no marker, no
    // timing guess. Found live 2026-09-11 (Andres: a real claude-code-ui-
    // launched `claude --resume <path>.jsonl` process still showed up in
    // the archived list) - that shape's transcript can be arbitrarily
    // older than the resuming process's own start time, which is exactly
    // what the pre-existing closest-start-time heuristic gets wrong.
    //
    // fake_claude discards its own argv entirely before exec'ing into
    // `cat` (see its own header comment) - /proc/<pid>/cmdline would never
    // show a real --resume flag through that stand-in, so this spawns a
    // small bash-only pane directly instead, using `exec -a` the same way
    // fake_claude does to fix argv[0] back to $0, but (unlike fake_claude)
    // forwarding the rest of argv through to a second bash -c that just
    // blocks on stdin forever, never erroring out on flags it doesn't
    // understand the way a real coreutils tool would.
    $resumeArgUuid = '66666666-6666-4666-8666-666666666666';
    @mkdir($archivedFakeHome . '/.claude/projects/-resume-arg-project', 0700, true);
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-resume-arg-project/' . $resumeArgUuid . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/resume-arg/path"}' . "\n"
    );
    $resumeArgTestSession = 'sessioneer-test-resume-arg-' . getmypid();
    $resumeArgSetup = TmuxService::tmux_run([
        'new-session', '-d', '-s', $resumeArgTestSession, '-c', Config::www_root(),
        'bash', '-c', 'exec -a "$0" bash -c \'while :; do read -r _ 2>/dev/null || sleep 3600; done\' -- "$@"',
        Config::claude_bin(), '--resume', $resumeArgUuid,
    ]);
    assert_equal(0, $resumeArgSetup['exit'], 'resume-arg setup: created a live non-cc-* tmux session whose real argv carries --resume <uuid>');
    usleep(300000);

    $resumeArgPid = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['tmux_session'] ?? null) === $resumeArgTestSession) {
            $resumeArgPid = (int)$b['pid'];
            break;
        }
    }
    assert_true($resumeArgPid !== null, 'resume-arg setup: the fixture bare process is visible as bare');

    $resumeArgDetail = BareProcessService::resolve_bare_process_detail($resumeArgPid ?? 0);
    assert_equal($resumeArgUuid, $resumeArgDetail['agent_session_id'] ?? null, 'resolve_bare_process_detail: resolves the id straight from --resume in argv, no marker needed');
    assert_equal('confirmed', $resumeArgDetail['confidence'] ?? null, 'resolve_bare_process_detail: an argv-derived id is confirmed, not a guess');

    $dashboardArchivedWithResumeArg = ArchivedSessionService::list_archived_dashboard()['archived'] ?? [];
    assert_true(
        !in_array($resumeArgUuid, array_column($dashboardArchivedWithResumeArg, 'agent_session_id'), true),
        'list_archived_dashboard: a bare --resume\'d process\'s own live session is excluded via its argv-derived id too'
    );

    TmuxService::tmux_run(['kill-session', '-t', $resumeArgTestSession]);
    $resumeArgTestSession = null;
    @unlink($archivedFakeHome . '/.claude/projects/-resume-arg-project/' . $resumeArgUuid . '.jsonl');
    @rmdir($archivedFakeHome . '/.claude/projects/-resume-arg-project');

    // --- ProcessInspector::find_claude_processes(): matches a process
    // whose argv[0] is the REALPATH-resolved target of CLAUDE_BIN (a
    // symlink, same as the real ~/.local/bin/claude install), not just an
    // exact basename match. Found live 2026-09-11 alongside the argv-
    // resume fix above: a real claude-code-ui-launched process's argv[0]
    // was the fully-resolved versioned binary path
    // (~/.local/share/claude/versions/X.Y.Z, basename "X.Y.Z") because
    // that tool resolves the ~/.local/bin/claude symlink itself before
    // exec'ing, rather than exec'ing the stable launcher path - invisible
    // to the pre-existing basename-only check, so this whole class of
    // process was never even seen as "bare" at all, let alone excluded
    // from anywhere. ---
    $fakeClaudeRealPath = realpath(Config::claude_bin());
    assert_true($fakeClaudeRealPath !== false, 'realpath-match setup: CLAUDE_BIN resolves to a real file');
    $claudeBinSymlink = sys_get_temp_dir() . '/sessioneer-test-claude-bin-symlink-' . getmypid();
    @unlink($claudeBinSymlink);
    symlink((string)$fakeClaudeRealPath, $claudeBinSymlink);
    $originalClaudeBinEnv = getenv('CLAUDE_BIN');
    putenv("CLAUDE_BIN={$claudeBinSymlink}");

    $realpathMatchTestSession = 'sessioneer-test-realpath-match-' . getmypid();
    $realpathMatchSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $realpathMatchTestSession, '-c', Config::www_root(), (string)$fakeClaudeRealPath]);
    assert_equal(0, $realpathMatchSetup['exit'], 'realpath-match setup: spawned a process using the REAL target path directly, not the CLAUDE_BIN symlink');
    usleep(300000);

    $foundViaRealpath = false;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['tmux_session'] ?? null) === $realpathMatchTestSession) {
            $foundViaRealpath = true;
            break;
        }
    }
    assert_true($foundViaRealpath, 'find_claude_processes: recognizes a process via realpath match even though its argv[0] basename does not match CLAUDE_BIN\'s own basename at all');

    TmuxService::tmux_run(['kill-session', '-t', $realpathMatchTestSession]);
    $realpathMatchTestSession = null;
    putenv($originalClaudeBinEnv !== false ? "CLAUDE_BIN={$originalClaudeBinEnv}" : 'CLAUDE_BIN');
    @unlink($claudeBinSymlink);

    // --- ProcessInspector::find_claude_processes(): matches a process
    // whose argv[0] is "claude <subcommand>" as ONE space-containing argv
    // element, not "claude" followed by a separate element. Found live
    // 2026-09-12: the CLI's own background daemon (`claude daemon run`)
    // rewrites its bg-spare/bg-pty-host worker processes' argv[0] to
    // exactly this shape for `ps` readability - basename() of a string
    // with no "/" in it is the string itself, so it never equals plain
    // "claude" and was invisible to both the pre-existing basename check
    // and the realpath check just above (there's no real file at a path
    // like "claude bg-spare" to resolve at all). ---
    $daemonLabelTestSession = 'sessioneer-test-daemon-label-' . getmypid();
    $daemonLabelSetup = TmuxService::tmux_run([
        'new-session', '-d', '-s', $daemonLabelTestSession, '-c', Config::www_root(),
        'bash', '-c', 'exec -a "' . basename(Config::claude_bin()) . ' bg-spare" cat',
    ]);
    assert_equal(0, $daemonLabelSetup['exit'], 'daemon-label setup: created a live session whose argv[0] mimics the CLI daemon\'s own "claude bg-spare" process-title trick');
    usleep(300000);

    $foundViaDaemonLabel = false;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['tmux_session'] ?? null) === $daemonLabelTestSession) {
            $foundViaDaemonLabel = true;
            break;
        }
    }
    assert_true($foundViaDaemonLabel, 'find_claude_processes: recognizes a process whose argv[0] is "claude <subcommand>" - the CLI daemon\'s own worker process-title convention');

    TmuxService::tmux_run(['kill-session', '-t', $daemonLabelTestSession]);
    $daemonLabelTestSession = null;

    // --- BareProcessService's daemon-roster resolution tier
    // (daemon_roster_session_ids()/resolve_via_daemon_roster()): the ONLY
    // way to identify a bg-spare/bg-pty-host worker's own conversation at
    // all, since that shape has no --resume in its own argv anywhere (the
    // daemon dispatches it over a private rendezvous socket instead) -
    // this is exactly what was still hiding Andres's own live session
    // (running through this exact pool) in the archived list even after
    // the argv-resume and realpath fixes above. A plain bare fake_claude
    // process stands in for a real daemon worker here - only roster.json's
    // OWN bookkeeping (pid + sessionId) is under test, not the real
    // daemon's argv-rewriting trick (already covered just above). ---
    $rosterUuid = '88888888-8888-4888-8888-888888888888';
    @mkdir($archivedFakeHome . '/.claude/daemon', 0700, true);
    @mkdir($archivedFakeHome . '/.claude/projects/-roster-project', 0700, true);
    file_put_contents(
        $archivedFakeHome . '/.claude/projects/-roster-project/' . $rosterUuid . '.jsonl',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hi"}]},"cwd":"/some/roster/path"}' . "\n"
    );

    $rosterBareCwd = Config::www_root() . '/project-a';
    $rosterBareProc = proc_open([Config::claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rosterBarePipes, $rosterBareCwd);
    assert_true(is_resource($rosterBareProc), 'roster setup: spawned a plain (non-tmux) fake claude process to act as a daemon "worker"');
    $rosterBarePid = is_resource($rosterBareProc) ? (proc_get_status($rosterBareProc)['pid'] ?? null) : null;
    usleep(300000);

    file_put_contents($archivedFakeHome . '/.claude/daemon/roster.json', json_encode([
        'proto' => 1,
        'workers' => [
            'rostertst' => [
                'pid' => $rosterBarePid,
                'sessionId' => $rosterUuid,
                'cwd' => $rosterBareCwd,
            ],
        ],
    ]));

    $rosterIds = BareProcessService::live_bare_agent_session_ids();
    assert_true(in_array($rosterUuid, $rosterIds, true), 'live_bare_agent_session_ids: includes the daemon roster\'s own sessionId for a worker whose pid is still actually running');

    $rosterArchived = ArchivedSessionService::list_archived_dashboard()['archived'] ?? [];
    assert_true(!in_array($rosterUuid, array_column($rosterArchived, 'agent_session_id'), true), 'list_archived_dashboard: excludes a session the daemon roster says is currently live');

    $rosterDetail = BareProcessService::resolve_bare_process_detail($rosterBarePid ?? 0);
    assert_equal($rosterUuid, $rosterDetail['agent_session_id'] ?? null, 'resolve_bare_process_detail: resolves via the daemon roster when the pid itself has no --resume in its own argv');
    assert_equal('confirmed', $rosterDetail['confidence'] ?? null, 'resolve_bare_process_detail: a daemon-roster match is confirmed, not a guess');

    // --- liveness safety net: once the worker's pid is actually gone, the
    // roster's own sessionId must stop being trusted - otherwise a crashed
    // daemon that never prunes its own stale roster would permanently hide
    // a truly-dead session from "archived" and refuse to ever let it be
    // resumed again (see daemon_roster_session_ids()'s own docblock). ---
    if (is_resource($rosterBareProc)) {
        proc_terminate($rosterBareProc);
        proc_close($rosterBareProc);
    }
    $rosterBareProc = null;
    usleep(300000);

    $rosterIdsAfterDeath = BareProcessService::live_bare_agent_session_ids();
    assert_true(!in_array($rosterUuid, $rosterIdsAfterDeath, true), 'live_bare_agent_session_ids: stops trusting the roster\'s sessionId once its worker pid is actually gone');

    @unlink($archivedFakeHome . '/.claude/daemon/roster.json');
    @rmdir($archivedFakeHome . '/.claude/daemon');
    @unlink($archivedFakeHome . '/.claude/projects/-roster-project/' . $rosterUuid . '.jsonl');
    @rmdir($archivedFakeHome . '/.claude/projects/-roster-project');

    @rmdir($archivedFakeHome . '/.claude/projects');
    @rmdir($archivedFakeHome . '/.claude');
    @rmdir($archivedFakeHome);
    putenv('HOME_ROOT');

    // --- reject kill of a name that isn't currently active ---
    $result = SessionLifecycleService::kill_agent_session('cc-not-a-real-session');
    assert_equal(false, $result['ok'] ?? null, 'kill: rejects a name not in the live whitelist');

    // --- kill ---
    if ($name !== null) {
        $result = SessionLifecycleService::kill_agent_session($name);
        assert_true($result['ok'] ?? false, 'kill: ok=true');
        $createdSessions = array_values(array_diff($createdSessions, [$name]));

        assert_true(find_session($name) === null, 'kill: session no longer listed');
        assert_true(!file_exists(Config::sidecar_dir() . "/{$name}.json"), 'kill: sidecar file removed');
    }

    // --- SessionLifecycleService::resume_agent_session(): input validation ---
    $result = SessionLifecycleService::resume_agent_session('relative/path', '11111111-1111-4111-8111-111111111111');
    assert_equal(false, $result['ok'] ?? null, 'resume: rejects a relative workdir');

    $result = SessionLifecycleService::resume_agent_session(Config::www_root() . '/project-a', '');
    assert_equal(false, $result['ok'] ?? null, 'resume: rejects an empty agent_session_id');

    // --- resume: happy path, reusing the agent_session_id freed up by the
    // kill just above. fake_claude ignores every arg (see its own header
    // comment) so this exercises the real tmux-spawn + sidecar-write path
    // without needing a real claude binary to actually honor --resume. ---
    $resumeId = $session['agent_session_id'] ?? null;
    assert_true($resumeId !== null, 'resume setup: have a agent_session_id to resume (from the killed session above)');

    $resumed = $resumeId !== null ? SessionLifecycleService::resume_agent_session(Config::www_root() . '/project-a', (string)$resumeId) : ['ok' => false];
    assert_true($resumed['ok'] ?? false, 'resume: ok=true for a dormant agent_session_id');
    $resumedName = $resumed['name'] ?? null;
    assert_true(is_string($resumedName) && str_starts_with($resumedName, 'cc-'), 'resume: returns the new pane name');

    if (is_string($resumedName)) {
        $createdSessions[] = $resumedName;
    }

    $resumedEntry = is_string($resumedName) ? find_session($resumedName) : null;
    assert_true($resumedEntry !== null, 'resume: the new session appears in list_all_sessions()');
    assert_equal($resumeId, $resumedEntry['agent_session_id'] ?? null, 'resume: sidecar records the resumed agent_session_id, not a freshly generated one');
    assert_equal(Config::www_root() . '/project-a', $resumedEntry['workdir'] ?? null, 'resume: sidecar records the requested workdir');

    // --- resume: refuses a agent_session_id that already has a live pane
    // (the one just resumed above) - the guard against two panes fighting
    // over the same transcript. ---
    $dupResume = $resumeId !== null ? SessionLifecycleService::resume_agent_session(Config::www_root() . '/project-a', (string)$resumeId) : ['ok' => true];
    assert_equal(false, $dupResume['ok'] ?? null, 'resume: refuses a agent_session_id that already has a live pane');

    if (is_string($resumedName)) {
        SessionLifecycleService::kill_agent_session($resumedName);
        $createdSessions = array_values(array_diff($createdSessions, [$resumedName]));
    }

    // --- resume: a concurrent resume attempt for the SAME agent_session_id
    // is rejected via flock() (not just the post-spawn sidecar check above)
    // - found live 2026-08-22 (codebase audit): the sidecar-only check left
    // a TOCTOU window between the check and the sidecar write (tmux spawn +
    // a 300ms settle sleep in between), where two near-simultaneous
    // requests could both pass it and spawn two panes on the same
    // transcript. Simulated here by manually holding the exact lock
    // resume_agent_session() itself acquires internally, before calling it -
    // mirrors what a second real in-flight request's own flock() call
    // would see. Path formula duplicated from SessionService::
    // resume_lock_path() (private) rather than exposed for testing, same
    // as this suite already does for SidecarStore's own file-naming
    // convention a few lines up. Run only after $resumedName above is
    // already killed - found live while writing this test: tmux session
    // names here are date('Ymd-His')-based (1-second resolution), so a
    // resume issued while an earlier same-second session is still alive
    // can collide with it and fail for a reason unrelated to the lock
    // itself. ---
    $lockContentionId = '22222222-2222-4222-8222-222222222222';
    $lockPath = Config::sidecar_dir() . '/' . sha1($lockContentionId) . '.resume-lock';
    @mkdir(Config::sidecar_dir(), 0700, true);
    $heldLock = fopen($lockPath, 'c');
    assert_true($heldLock !== false, 'resume lock test setup: lock file opened');
    assert_true(flock($heldLock, LOCK_EX | LOCK_NB), 'resume lock test setup: lock acquired (simulating an in-flight resume)');

    $blockedResume = SessionLifecycleService::resume_agent_session(Config::www_root() . '/project-a', $lockContentionId);
    assert_equal(false, $blockedResume['ok'] ?? null, 'resume: a second resume for the SAME agent_session_id is rejected while another is holding the lock, even before any sidecar exists for it yet');
    assert_equal('This session already has a live pane - refusing to open a second one on the same transcript', $blockedResume['message'] ?? null, 'resume: lock contention gives the SAME rejection message as the post-spawn sidecar check - one consistent user-facing reason regardless of which guard actually caught it');

    flock($heldLock, LOCK_UN);
    fclose($heldLock);

    // Lock released - the same agent_session_id now resumes normally,
    // proving the lock isn't stuck held/leaked from the contention test above.
    $afterLockReleased = SessionLifecycleService::resume_agent_session(Config::www_root() . '/project-a', $lockContentionId);
    assert_true($afterLockReleased['ok'] ?? false, 'resume: once the lock is released, the same agent_session_id resumes normally - the lock does not leak across requests');

    $afterLockReleasedName = $afterLockReleased['name'] ?? null;

    if (is_string($afterLockReleasedName)) {
        $createdSessions[] = $afterLockReleasedName;
        SessionLifecycleService::kill_agent_session($afterLockReleasedName);
        $createdSessions = array_values(array_diff($createdSessions, [$afterLockReleasedName]));
    }

    @unlink($lockPath);

    // --- input validation: relative path rejected before touching tmux ---
    $result = SessionLifecycleService::create_agent_session('relative/path');
    assert_equal(false, $result['ok'] ?? null, 'create: rejects a relative workdir');

    // --- self-healing: the tmux socket's parent directory can vanish
    // entirely (e.g. a host reboot wipes /tmp) since it's addressed via an
    // explicit -S path, which - unlike tmux's own default $TMPDIR/tmux-$UID
    // naming - tmux never auto-creates. TmuxService::tmux_run() must recreate it on
    // demand rather than every command failing until someone notices. ---
    TmuxService::tmux_run(['kill-server']); // empties the isolated test socket dir so it can be removed
    $socketDir = dirname(Config::tmux_socket());
    foreach (glob("{$socketDir}/*") ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($socketDir);
    assert_true(!is_dir($socketDir), 'self-heal setup: tmux socket dir removed');

    $healed = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    assert_true($healed['ok'], 'create: recreates a missing tmux socket dir and still succeeds');
    if ($healed['name'] !== null) {
        SessionLifecycleService::kill_agent_session($healed['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$healed['name']]));
    }

    // --- claude binary fails to start: tmux registers the session, then the pane
    // exits immediately since the command doesn't exist - SessionLifecycleService::create_agent_session()'s
    // post-creation check must catch that and report failure ---
    $originalClaudeBin = Config::claude_bin();
    putenv('CLAUDE_BIN=/definitely/does/not/exist/sessioneer-test-claude-binary');
    $bad = create_and_track(Config::www_root() . '/project-a', $createdSessions);
    putenv("CLAUDE_BIN={$originalClaudeBin}");
    assert_true(!$bad['ok'], 'create: a claude binary that fails to start is reported as failure');

    // --- AgentAdapter: create_agent_session() with an $agentId
    // (docs/antigravity-adapter-plan.md Phase 2) ---

    // Same failure shape as the CLAUDE_BIN case above, for antigravity.
    $originalAntigravityBin = Config::antigravity_bin();
    putenv('ANTIGRAVITY_BIN=/definitely/does/not/exist/sessioneer-test-agy-binary');
    $badAg = SessionLifecycleService::create_agent_session(Config::www_root() . '/project-a', false, null, 'antigravity');
    putenv("ANTIGRAVITY_BIN={$originalAntigravityBin}");
    assert_true(!($badAg['ok'] ?? true), 'create(agent: antigravity): an agy binary that fails to start is reported as failure, same as the Claude Code path');

    // Unrecognized agent id falls back to Claude Code's own default,
    // whitelisted rather than trusted straight through (same discipline
    // create_agent_session()'s own docblock already documents for
    // $startingMode) - proves this against a REAL spawn, not just
    // AgentRegistry::get()'s own unit-level behavior in test_agent_adapter.php.
    $fallback = create_and_track(Config::www_root() . '/project-a', $createdSessions, false, null, 'not-a-real-agent');
    assert_true($fallback['ok'], 'create(agent: not-a-real-agent): falls back to the default agent rather than failing outright');
    assert_true($fallback['name'] !== null && str_starts_with($fallback['name'], 'cc-'), 'create(agent: not-a-real-agent): uses Claude Code\'s cc- prefix, proving the fallback actually happened');
    if ($fallback['name'] !== null) {
        SessionLifecycleService::kill_agent_session($fallback['name']);
        $createdSessions = array_values(array_diff($createdSessions, [$fallback['name']]));
    }

    // Real happy path: a genuine Antigravity-flavored session, spawned
    // through the same tests/fixtures/fake_agy stand-in fake_claude uses
    // for Claude Code (see tests/.env.testing) - proves the whole
    // spawn -> tmux -> sidecar round-trip, not just build_spawn_argv()'s
    // own argv shape (already covered in test_agent_adapter.php).
    $agSession = SessionLifecycleService::create_agent_session(Config::www_root() . '/project-a', false, null, 'antigravity');
    assert_true($agSession['ok'] ?? false, 'create(agent: antigravity): succeeds against the fake_agy fixture binary');
    $agName = null;
    if (preg_match('/Created session (ag-\S+) in/', (string)($agSession['message'] ?? ''), $agMatch) === 1) {
        $agName = $agMatch[1];
        $createdSessions[] = $agName;
    }
    assert_true($agName !== null, 'create(agent: antigravity): the created session name uses the ag- prefix, distinct from Claude Code\'s cc-');

    if ($agName !== null) {
        $agSidecar = SidecarStore::read_sidecar($agName);
        assert_equal('antigravity', $agSidecar['agent'] ?? null, 'create(agent: antigravity): the sidecar records agent=antigravity');
        assert_equal(null, $agSidecar['agent_session_id'], 'create(agent: antigravity): agent_session_id is null - no pre-assignable id exists for a fresh interactive Antigravity session (see AntigravityAdapter::build_spawn_argv())');

        $agListed = find_session($agName);
        assert_true($agListed !== null, 'create(agent: antigravity): the new session shows up in list_all_sessions() like any other tracked session');

        SessionLifecycleService::kill_agent_session($agName);
        $createdSessions = array_values(array_diff($createdSessions, [$agName]));
    }

    // --- cleanup respects the (short, test-only) inactivity threshold ---
    $created = create_and_track(Config::www_root() . '/project-b', $createdSessions);
    assert_true($created['ok'], 'cleanup setup: session created');

    sleep(Config::cleanup_threshold_seconds() + 1);

    $result = SessionLifecycleService::cleanup_inactive_sessions();
    assert_true($result['ok'] ?? false, 'cleanup: ok=true');
    assert_true(
        $created['name'] !== null && in_array($created['name'], $result['killed'] ?? [], true),
        'cleanup: killed the inactive session'
    );
    if ($created['name'] !== null) {
        $createdSessions = array_values(array_diff($createdSessions, [$created['name']]));
    }

    // --- bare processes: a plain (non-tmux) fake claude process must show
    // up in SessionService::list_all_sessions()['bare'] with no tmux_session/title, and
    // BareProcessService::kill_bare_process() must SIGTERM it directly ---
    $bareCwd = Config::www_root() . '/project-a';
    $bareProc = proc_open([Config::claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $barePipes, $bareCwd);
    assert_true(is_resource($bareProc), 'bare setup: spawned a plain (non-tmux) fake claude process');
    $barePid = is_resource($bareProc) ? (proc_get_status($bareProc)['pid'] ?? null) : null;
    usleep(300000); // let /proc reflect the new process's argv/cwd

    $bareEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if ($b['pid'] === $barePid) {
            $bareEntry = $b;
            break;
        }
    }
    assert_true($bareEntry !== null, 'list: plain bare process appears in bare[]');
    assert_equal($bareCwd, $bareEntry['cwd'] ?? null, 'list: bare process cwd read via /proc');
    assert_equal(null, $bareEntry['tmux_session'], 'list: plain bare process has no owning tmux session');
    assert_equal(null, $bareEntry['title'], 'list: plain bare process has no title');

    assert_equal(false, BareProcessService::kill_bare_process(999999)['ok'] ?? null, 'kill_bare_process: rejects a pid that is not a running claude process');

    $killResult = $barePid !== null ? BareProcessService::kill_bare_process($barePid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a plain process');
    usleep(300000);

    $stillThere = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $barePid) {
            $stillThere = true;
        }
    }
    assert_true(!$stillThere, 'kill_bare_process: plain process no longer found after SIGTERM');

    if (is_resource($bareProc)) {
        proc_close($bareProc);
    }
    $bareProc = null;

    // --- regression, found live 2026-08-08: find_claude_processes() used
    // to require argv[0] to equal Config::claude_bin()'s FULL configured
    // path exactly. Typing a bare `claude` in a terminal (PATH-resolved by
    // the shell, not the full path) gives that process argv[0] "claude"
    // verbatim - a real running session was invisible to this scan (not in
    // bare[], not excluded from the archived list) purely because of how
    // it happened to be typed. Now matched by basename instead. ---
    // bash's `exec -a` sets argv[0] directly (same technique fake_claude
    // itself uses - see tests/fixtures/fake_claude) - simpler and more
    // reliable than depending on proc_open's own PATH-search behavior to
    // simulate a PATH-resolved invocation.
    $bareNameProc = proc_open(
        ['bash', '-c', 'exec -a ' . escapeshellarg(basename(Config::claude_bin())) . ' ' . escapeshellarg(Config::claude_bin())],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $bareNamePipes,
        $bareCwd
    );
    assert_true(is_resource($bareNameProc), 'bare-name setup: spawned a plain process invoked by bare name (PATH-resolved), not the full configured path');
    $bareNamePid = is_resource($bareNameProc) ? (proc_get_status($bareNameProc)['pid'] ?? null) : null;
    usleep(300000);

    $foundBareName = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $bareNamePid) {
            $foundBareName = true;
        }
    }
    assert_true($foundBareName, 'find_claude_processes: a bare-name (PATH-resolved) invocation is still found, not just the exact-full-path one');

    if (is_resource($bareNameProc)) {
        proc_terminate($bareNameProc);
        proc_close($bareNameProc);
    }

    // --- bare processes: a fake claude process living inside a tmux
    // session this tool doesn't manage (not cc-* prefixed) must be
    // enriched with that session's name and pane title, and
    // BareProcessService::kill_bare_process() must kill the whole session rather than just
    // SIGTERM the pid ---
    $adhocName = 'sessioneer-test-adhoc-' . getmypid();
    $adhocCwd = Config::www_root() . '/project-b';
    $adhocCreate = TmuxService::tmux_run(['new-session', '-d', '-s', $adhocName, '-c', $adhocCwd, Config::claude_bin()]);
    assert_equal(0, $adhocCreate['exit'], 'bare setup: created an ad-hoc (non-cc-*) tmux session');
    usleep(300000);
    TmuxService::tmux_run(['select-pane', '-t', $adhocName, '-T', 'Adhoc bare title']);
    usleep(100000);

    $adhocEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['cwd'] ?? null) === $adhocCwd) {
            $adhocEntry = $b;
            break;
        }
    }
    assert_true($adhocEntry !== null, "list: ad-hoc tmux session's claude process appears in bare[]");
    assert_equal($adhocName, $adhocEntry['tmux_session'] ?? null, 'list: bare process inside a non-cc-* tmux session reports that session name');
    assert_equal('Adhoc bare title', $adhocEntry['title'] ?? null, 'list: bare process picks up its tmux pane title');

    // --- regression: an adopted sidecar keyed off a real, non-cc-*-named
    // tmux session must survive list_all_sessions()'s own orphan-pruning
    // pass. prune_orphaned_sidecars() used to only be told about cc-*
    // names, so an adopted session's sidecar was deleted as a false
    // "orphan" on the very next dashboard load (see host-agent/hooks/
    // session_start.php's adoption path in commit 9462e25) ---
    SidecarStore::write_sidecar($adhocName, ['workdir' => $adhocCwd, 'spawned_at' => time()]);
    SessionService::list_all_sessions();
    assert_true(SidecarStore::read_sidecar($adhocName) !== null, 'list_all_sessions: an adopted sidecar for a live non-cc-* tmux session is not pruned as an orphan');

    $adhocPid = $adhocEntry['pid'] ?? null;
    $killResult = $adhocPid !== null ? BareProcessService::kill_bare_process($adhocPid) : ['ok' => false];
    assert_true($killResult['ok'] ?? false, 'kill_bare_process: ok=true for a tmux-hosted bare process');

    $hasSession = TmuxService::tmux_run(['has-session', '-t', $adhocName]);
    assert_true($hasSession['exit'] !== 0, 'kill_bare_process: ad-hoc tmux session no longer exists');
    $adhocName = null;

    // --- BareProcessService::take_over_bare_process()/take_over_bare_process_with_id():
    // unify-claude-sessions plan's phase 6. Two paths: a cwd-scoped
    // candidate list (nothing killed until a human picks one - a plain,
    // no-tmux bare process can only ever go this way, since there's no
    // pane to read a statusline marker from), and a confident one-click
    // match via the marker (tested separately below). ---
    assert_equal(false, BareProcessService::take_over_bare_process(999999)['ok'] ?? null, 'take_over_bare_process: rejects a pid that is not a running claude process');

    $takeOverCwd = Config::www_root() . '/project-a';
    $takeOverFakeHome = sys_get_temp_dir() . '/sessioneer-test-take-over-home-' . getmypid();
    @mkdir($takeOverFakeHome . '/.claude/projects/-take-over-project', 0700, true);

    $dormantUuid = '55555555-5555-4555-8555-555555555555';
    file_put_contents(
        $takeOverFakeHome . '/.claude/projects/-take-over-project/' . $dormantUuid . '.jsonl',
        json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]], 'cwd' => $takeOverCwd, 'timestamp' => date('c', time() - 3600)]) . "\n"
    );
    putenv("HOME_ROOT={$takeOverFakeHome}");

    $takeOverBareProc = proc_open([Config::claude_bin()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $takeOverBarePipes, $takeOverCwd);
    assert_true(is_resource($takeOverBareProc), 'take-over setup: spawned a plain (non-tmux) bare process');
    $takeOverBarePid = is_resource($takeOverBareProc) ? (proc_get_status($takeOverBareProc)['pid'] ?? null) : null;
    usleep(300000);

    $resolved = $takeOverBarePid !== null ? BareProcessService::take_over_bare_process($takeOverBarePid) : ['ok' => false];
    assert_true($resolved['ok'] ?? false, 'take_over_bare_process: ok=true when no confident marker match exists');
    assert_true($resolved['needs_choice'] ?? false, 'take_over_bare_process: needs_choice=true for a no-tmux bare process (no pane to read a marker from)');
    assert_equal($takeOverCwd, $resolved['workdir'] ?? null, 'take_over_bare_process: workdir read via /proc, matches the process\'s real cwd');
    assert_equal([$dormantUuid], array_column($resolved['candidates'] ?? [], 'agent_session_id'), 'take_over_bare_process: candidates scoped to exactly this cwd');
    assert_equal($dormantUuid, $resolved['suggested_agent_session_id'] ?? null, 'take_over_bare_process: suggests the sole candidate for this cwd');

    $stillRunningAfterResolve = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $takeOverBarePid) {
            $stillRunningAfterResolve = true;
        }
    }
    assert_true($stillRunningAfterResolve, 'take_over_bare_process: the needs_choice path kills nothing - the original process is still running, fully cancelable');

    $confirmed = BareProcessService::take_over_bare_process_with_id((int)$takeOverBarePid, $takeOverCwd, $dormantUuid);
    assert_true($confirmed['ok'] ?? false, 'take_over_bare_process_with_id: ok=true');
    $takeOverName = $confirmed['name'] ?? null;
    assert_true(is_string($takeOverName) && str_starts_with($takeOverName, 'cc-'), 'take_over_bare_process_with_id: returns the new pane name');

    if (is_string($takeOverName)) {
        $createdSessions[] = $takeOverName;
    }

    usleep(300000);
    $stillRunningAfterConfirm = false;
    foreach (ProcessInspector::find_claude_processes() as $p) {
        if ($p['pid'] === $takeOverBarePid) {
            $stillRunningAfterConfirm = true;
        }
    }
    assert_true(!$stillRunningAfterConfirm, 'take_over_bare_process_with_id: the original bare process was killed');

    $takeOverEntry = is_string($takeOverName) ? find_session($takeOverName) : null;
    assert_true($takeOverEntry !== null, 'take_over_bare_process_with_id: the new session appears in list_all_sessions()');
    assert_equal($dormantUuid, $takeOverEntry['agent_session_id'] ?? null, 'take_over_bare_process_with_id: sidecar records the chosen agent_session_id');
    assert_equal($takeOverCwd, $takeOverEntry['workdir'] ?? null, 'take_over_bare_process_with_id: sidecar records the chosen workdir');

    if (is_string($takeOverName)) {
        SessionLifecycleService::kill_agent_session($takeOverName);
        $createdSessions = array_values(array_diff($createdSessions, [$takeOverName]));
    }

    if (is_resource($takeOverBareProc)) {
        proc_close($takeOverBareProc);
    }
    $takeOverBareProc = null;

    // --- take_over_bare_process_with_id(): tolerates the pid already
    // having exited on its own between resolve and confirm (a real
    // possibility - some time passes while a human picks from the
    // picker) - the kill step is skipped, but the resume still goes
    // through, reusing the now-free $dormantUuid from above. ---
    $toleranceResult = BareProcessService::take_over_bare_process_with_id(999999, $takeOverCwd, $dormantUuid);
    assert_true($toleranceResult['ok'] ?? false, 'take_over_bare_process_with_id: still resumes even when the pid is already gone (kill step skipped, tolerated)');
    $toleranceName = $toleranceResult['name'] ?? null;

    if (is_string($toleranceName)) {
        SessionLifecycleService::kill_agent_session($toleranceName);
    }

    @unlink($takeOverFakeHome . '/.claude/projects/-take-over-project/' . $dormantUuid . '.jsonl');
    @rmdir($takeOverFakeHome . '/.claude/projects/-take-over-project');
    @rmdir($takeOverFakeHome . '/.claude/projects');
    @rmdir($takeOverFakeHome . '/.claude');
    @rmdir($takeOverFakeHome);
    putenv('HOME_ROOT');

    // --- take_over_bare_process(): the confident, one-click path - a
    // statusline marker (StatuslineMarkerService::parse_marker_from_pane())
    // in the bare process's own tmux pane names an exact agent_session_id
    // backed by a real transcript, so nothing needs to be shown to a human
    // at all: kill + resume happen inside this one call. Uses project-b
    // (not project-a, already exercised above) to keep this fixture's cwd
    // uncorrelated with the candidate-list test's own dormant transcript. ---
    $markerCwd = Config::www_root() . '/project-b';
    $markerFakeHome = sys_get_temp_dir() . '/sessioneer-test-take-over-marker-home-' . getmypid();
    @mkdir($markerFakeHome . '/.claude/projects/-marker-project', 0700, true);
    $markerUuid = '77777777-7777-4777-8777-777777777777';
    file_put_contents(
        $markerFakeHome . '/.claude/projects/-marker-project/' . $markerUuid . '.jsonl',
        json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]], 'cwd' => $markerCwd]) . "\n"
    );
    putenv("HOME_ROOT={$markerFakeHome}");

    $markerAdhocName = 'sessioneer-test-marker-adhoc-' . getmypid();
    $markerSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $markerAdhocName, '-c', $markerCwd, Config::claude_bin()]);
    assert_equal(0, $markerSetup['exit'], 'marker take-over setup: created a live non-cc-* tmux session hosting a fake_claude process');
    usleep(300000);

    TmuxService::tmux_run(['send-keys', '-t', $markerAdhocName, 'sessioneer-data:{"session_id":"' . $markerUuid . '"}', 'Enter']);
    usleep(300000);

    $markerBareEntry = null;
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (($b['tmux_session'] ?? null) === $markerAdhocName) {
            $markerBareEntry = $b;
            break;
        }
    }
    assert_true($markerBareEntry !== null, 'marker take-over setup: the fake_claude process is visible as a bare (untracked) process');
    $markerPid = $markerBareEntry['pid'] ?? null;

    $markerTakeOver = $markerPid !== null ? BareProcessService::take_over_bare_process((int)$markerPid) : ['ok' => false];
    assert_true($markerTakeOver['ok'] ?? false, 'take_over_bare_process: ok=true for a marker-matched bare process');
    assert_true(!($markerTakeOver['needs_choice'] ?? false), 'take_over_bare_process: no picker needed - the marker gave a confident match');
    $markerTakeOverName = $markerTakeOver['name'] ?? null;
    assert_true(is_string($markerTakeOverName) && str_starts_with($markerTakeOverName, 'cc-'), 'take_over_bare_process: returns the new pane name, already resumed');

    $markerHasSession = TmuxService::tmux_run(['has-session', '-t', $markerAdhocName]);
    assert_true($markerHasSession['exit'] !== 0, 'take_over_bare_process: the original ad-hoc tmux session was killed as part of the one-click take-over');

    $markerEntry = is_string($markerTakeOverName) ? find_session($markerTakeOverName) : null;
    assert_true($markerEntry !== null, 'take_over_bare_process: the new session appears in list_all_sessions()');
    assert_equal($markerUuid, $markerEntry['agent_session_id'] ?? null, 'take_over_bare_process: sidecar records the exact agent_session_id read from the statusline marker, not a guess');

    if (is_string($markerTakeOverName)) {
        SessionLifecycleService::kill_agent_session($markerTakeOverName);
    }

    @unlink($markerFakeHome . '/.claude/projects/-marker-project/' . $markerUuid . '.jsonl');
    @rmdir($markerFakeHome . '/.claude/projects/-marker-project');
    @rmdir($markerFakeHome . '/.claude/projects');
    @rmdir($markerFakeHome . '/.claude');
    @rmdir($markerFakeHome);
    putenv('HOME_ROOT');

    // --- bare_process_take_over_candidates(): excludes another OTHER
    // live (marker-matched) bare process's own session for the same
    // cwd, even though nothing tracks it via a sidecar (Andres's own
    // concern, 2026-08-08) - resume_agent_session()'s already-live guard
    // only checks sidecars, so without this a candidate transcript
    // still being actively written by a different live bare process
    // could get a second pane fighting over it. A genuinely dormant
    // transcript (no live process at all) must still be offered. ---
    $dualBareCwd = Config::www_root() . '/project-a';
    $dualBareFakeHome = sys_get_temp_dir() . '/sessioneer-test-dual-bare-home-' . getmypid();
    @mkdir($dualBareFakeHome . '/.claude/projects/-dual-bare-project', 0700, true);

    $targetUuid = '88888888-8888-4888-8888-888888888888';
    $otherLiveUuid = '99999999-9999-4999-9999-999999999999';
    $genuinelyDormantUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    foreach ([$targetUuid, $otherLiveUuid, $genuinelyDormantUuid] as $i => $uuid) {
        file_put_contents(
            $dualBareFakeHome . '/.claude/projects/-dual-bare-project/' . $uuid . '.jsonl',
            json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]], 'cwd' => $dualBareCwd, 'timestamp' => date('c', time() - 3600 + $i)]) . "\n"
        );
    }
    putenv("HOME_ROOT={$dualBareFakeHome}");

    $targetAdhocName = 'sessioneer-test-dual-bare-target-' . getmypid();
    $otherAdhocName = 'sessioneer-test-dual-bare-other-' . getmypid();
    TmuxService::tmux_run(['new-session', '-d', '-s', $targetAdhocName, '-c', $dualBareCwd, Config::claude_bin()]);
    TmuxService::tmux_run(['new-session', '-d', '-s', $otherAdhocName, '-c', $dualBareCwd, Config::claude_bin()]);
    $dualBareAdhocNames = [$targetAdhocName, $otherAdhocName];
    usleep(300000);

    // Only the OTHER pane gets a marker - the target pane has none,
    // forcing take_over_bare_process() into the needs_choice path for it.
    TmuxService::tmux_run(['send-keys', '-t', $otherAdhocName, 'sessioneer-data:{"session_id":"' . $otherLiveUuid . '"}', 'Enter']);
    usleep(300000);

    $dualBareEntries = [];
    foreach (SessionService::list_all_sessions()['bare'] as $b) {
        if (in_array($b['tmux_session'] ?? null, [$targetAdhocName, $otherAdhocName], true)) {
            $dualBareEntries[$b['tmux_session']] = $b;
        }
    }
    assert_true(isset($dualBareEntries[$targetAdhocName], $dualBareEntries[$otherAdhocName]), 'dual-bare setup: both fake_claude processes are visible as bare');

    $dualBareResolved = BareProcessService::take_over_bare_process((int)$dualBareEntries[$targetAdhocName]['pid']);
    assert_true($dualBareResolved['needs_choice'] ?? false, 'take_over_bare_process: target pid (no marker of its own) falls to the picker path');
    $dualBareCandidateIds = array_column($dualBareResolved['candidates'] ?? [], 'agent_session_id');
    assert_true(in_array($targetUuid, $dualBareCandidateIds, true), 'bare_process_take_over_candidates: includes the target pid\'s own (dormant, since it has no marker) transcript');
    assert_true(in_array($genuinelyDormantUuid, $dualBareCandidateIds, true), 'bare_process_take_over_candidates: includes a genuinely dormant transcript with no live process at all');
    assert_true(!in_array($otherLiveUuid, $dualBareCandidateIds, true), 'bare_process_take_over_candidates: excludes another BARE process\'s own live session (marker-matched), even though nothing tracks it via a sidecar');

    TmuxService::tmux_run(['kill-session', '-t', $targetAdhocName]);
    TmuxService::tmux_run(['kill-session', '-t', $otherAdhocName]);
    $dualBareAdhocNames = [];

    foreach ([$targetUuid, $otherLiveUuid, $genuinelyDormantUuid] as $uuid) {
        @unlink($dualBareFakeHome . '/.claude/projects/-dual-bare-project/' . $uuid . '.jsonl');
    }
    @rmdir($dualBareFakeHome . '/.claude/projects/-dual-bare-project');
    @rmdir($dualBareFakeHome . '/.claude/projects');
    @rmdir($dualBareFakeHome . '/.claude');
    @rmdir($dualBareFakeHome);
    putenv('HOME_ROOT');

    // --- PromptInteractionService::answer_prompt(): sends the chosen option's number + Enter to a
    // live session's pane, exactly like a human attached over tmux would
    // type. fake_claude/cat doesn't understand a real permission prompt,
    // so a raw pane running `cat` with local echo disabled (stty -echo)
    // stands in here - crafted prompt text is typed via send-keys (which
    // cat echoes back exactly once, since nothing else is echoing it),
    // giving PromptParser::parse_blocking_prompt() something real to detect via an
    // actual capture-pane call, not a hand-fed string like the pure
    // PromptParser::parse_blocking_prompt() tests above. ---
    $promptTestSession = 'cc-test-answer-prompt-' . getmypid();
    $promptSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $promptTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $promptSetup['exit'], 'answer_prompt setup: created a live cc-* session to answer a prompt in');
    // A raw ad-hoc pane (not made via create_agent_session()) has no sidecar of
    // its own - write one directly so it counts as "tracked" (see
    // TmuxService::list_tracked_tmux_sessions()), same as answer_prompt() et
    // al. require of any session they'll act on.
    SidecarStore::write_sidecar($promptTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Do you want to proceed?', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Yes', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. No', 'Enter']);
    usleep(300000);

    assert_equal(
        false,
        PromptInteractionService::answer_prompt($promptTestSession, 99)['ok'] ?? null,
        'answer_prompt: rejects an option not currently offered by the prompt'
    );
    assert_equal(
        false,
        PromptInteractionService::answer_prompt('cc-not-a-real-session', 1)['ok'] ?? null,
        'answer_prompt: rejects a session name that is not currently live'
    );

    $answered = PromptInteractionService::answer_prompt($promptTestSession, 1);
    assert_true($answered['ok'] ?? false, 'answer_prompt: ok=true for a currently-offered option');
    usleep(300000);

    $paneAfterAnswer = trim(TmuxService::tmux_capture_pane($promptTestSession));
    assert_true(str_ends_with($paneAfterAnswer, '1'), 'answer_prompt: the option number was actually sent into the pane (echoed back by cat)');

    // --- PromptInteractionService::answer_prompt(): Claude Code permission-suggestion
    // cross-check (see PromptParser::classify_permission_option_intent()'s
    // own docblock for the live incident - "Allow always" silently sending
    // the digit for "No" instead). Case 1: the real menu re-ordered but
    // still HAS the intended option - self-corrects to its real number. ---
    SessionStatusStore::update_status($promptTestSession, [
        'status' => 'blocked',
        'blocked' => [
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'sed -n 1,5p file.lua', 'description' => 'Inspect file'],
            'permission_suggestions' => [[
                'type' => 'addRules',
                'rules' => [['toolName' => 'Read', 'ruleContent' => '//some/glob/**']],
                'behavior' => 'allow',
                'destination' => 'session',
            ]],
        ],
    ]);
    // Guessed layout (from the status above) would be 1=Yes, 2=Yes-always,
    // 3=No - the real pane here instead has the always-option at 3, with
    // No at 2, simulating a real menu that doesn't match that guess. Uses
    // the same realistic "● Bash(...)" marker + divider shape as the
    // $realPermissionPrompt fixture above (not a bare "Do you want to
    // proceed?" line) - needed so parse_blocking_prompt()'s context/marker
    // scan has a clean boundary to stop at, now that this session's pane
    // already has earlier tests' content sitting above it.
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '● Bash(sed -n 1,5p file.lua)', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Do you want to proceed?', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Yes', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. No', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, "  3. Yes, and don't ask again for: //some/glob/**", 'Enter']);
    usleep(300000);

    $reorderedAnswer = PromptInteractionService::answer_prompt($promptTestSession, 2);
    assert_true($reorderedAnswer['ok'] ?? false, 'answer_prompt: still succeeds when the real menu re-ordered the intended option');
    assert_true(str_contains((string)($reorderedAnswer['message'] ?? ''), '3'), 'answer_prompt: reports the REAL corrected option number (3), not the guessed one (2)');
    usleep(300000);

    $paneAfterReorderedAnswer = trim(TmuxService::tmux_capture_pane($promptTestSession));
    assert_true(str_ends_with($paneAfterReorderedAnswer, '3'), 'answer_prompt: sent the corrected option number (3) into the pane, not the guessed one (2)');

    // Case 2: the real menu doesn't offer the intended option AT ALL (only
    // a plain Yes/No this time, matching Andres's own report that the iOS
    // app/TUI can show just Allow/Deny for this same prompt shape) - must
    // refuse rather than silently sending whatever's really at that
    // number, and the health check must then flag it.
    SessionStatusStore::update_status($promptTestSession, [
        'status' => 'blocked',
        'blocked' => [
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'sed -n 1,5p file.lua', 'description' => 'Inspect file'],
            'permission_suggestions' => [[
                'type' => 'addRules',
                'rules' => [['toolName' => 'Read', 'ruleContent' => '//some/glob/**']],
                'behavior' => 'allow',
                'destination' => 'session',
            ]],
        ],
    ]);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '● Bash(sed -n 1,5p file.lua)', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Do you want to proceed?', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Yes', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. No', 'Enter']);
    usleep(300000);

    $liveMismatchEntry = find_session($promptTestSession);
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
        $liveMismatchEntry['prompt_options'] ?? null,
        'build_session_entry: displays the live permission menu instead of a hook-suggested option the TUI omitted'
    );

    $paneBeforeMismatchAnswer = trim(TmuxService::tmux_capture_pane($promptTestSession));
    $mismatchAnswer = PromptInteractionService::answer_prompt($promptTestSession, 2);
    assert_equal(false, $mismatchAnswer['ok'] ?? null, "answer_prompt: refuses when the real menu doesn't offer the intended option at all");
    assert_true(str_contains((string)($mismatchAnswer['message'] ?? ''), 'Refusing'), 'answer_prompt: the refusal message says so, not a generic rejection');
    usleep(300000);

    $paneAfterMismatchAnswer = trim(TmuxService::tmux_capture_pane($promptTestSession));
    assert_equal($paneBeforeMismatchAnswer, $paneAfterMismatchAnswer, 'answer_prompt: nothing was actually sent to the pane when refusing on a layout mismatch');

    $mismatchHealth = TuiLayoutMismatchService::health_check();
    assert_equal(false, $mismatchHealth['ok'] ?? null, 'TuiLayoutMismatchService::health_check: reports unhealthy after a recorded mismatch');
    assert_true(str_contains((string)($mismatchHealth['detail'] ?? ''), $promptTestSession), 'TuiLayoutMismatchService::health_check: names the affected session in its detail');

    // A freshly rendered dashboard submits the label it actually showed.
    // When that label is the live pane's "No", option 2 must remain a
    // denial instead of being mistaken for the hook's absent always-allow
    // suggestion at the same ordinal.
    $liveMenuAnswer = PromptInteractionService::answer_prompt($promptTestSession, 3, 'No');
    assert_true($liveMenuAnswer['ok'] ?? false, 'answer_prompt: accepts the live pane label as the user intent when hook suggestions disagree (' . ($liveMenuAnswer['message'] ?? 'no message') . ')');
    usleep(300000);
    assert_true(str_ends_with(trim(TmuxService::tmux_capture_pane($promptTestSession)), '2'), 'answer_prompt: sends the live pane option selected by label');

    GlobalStateStore::write('tui_layout_mismatch', [
        'session' => $promptTestSession,
        'expected_label' => 'Old guessed option',
        'real_label' => 'Old live option',
        'detected_at' => time(),
    ]);
    assert_equal(true, TuiLayoutMismatchService::health_check()['ok'] ?? null, 'TuiLayoutMismatchService::health_check: ignores a legacy warning produced by the fixed ordinal-only flow');

    // The refusal above returns early, before the usual "mark working,
    // clear blocked" cleanup - clear it explicitly so this session's
    // leftover 'blocked' status can't affect anything answered on it
    // afterward (the tests below don't rely on this either way now that
    // answer_prompt() itself also gates its cross-check on the real pane's
    // question text, not just hook status - see its own comment - but a
    // test session shouldn't carry stale state forward regardless).
    SessionStatusStore::update_status($promptTestSession, ['status' => 'working', 'blocked' => null]);

    // --- PromptInteractionService::answer_prompt(): for a MULTI-QUESTION prompt specifically,
    // sends ONLY the digit - no trailing Enter (found live 2026-08-09,
    // Andres reported answering a question "skipped" the next one; verified
    // against a real, disposable claude session: the digit alone already
    // selects+confirms+auto-advances on this prompt shape, so this app's
    // old always-send-Enter behavior landed that extra Enter on whatever
    // was showing NEXT and silently confirmed ITS current default too -
    // one real answer, two tabs advanced). Still relevant even though the
    // frontend no longer reaches this shape via its own UI (see
    // PromptInteractionService::answer_multi_question() instead) - this endpoint is
    // reachable regardless of which client called it, so it still needs to
    // get the real interaction model right. Same canonical-mode-cat
    // fixture reasoning as elsewhere in this file: a plain digit byte with
    // no following Enter never completes a line, so
    // cat's own read() never sees it and never echoes it back - the pane
    // staying UNCHANGED after answer_prompt() is exactly the signal that
    // no Enter followed it (a regression back to always-Enter would flush
    // the digit and change the pane, same as the single-question case
    // earlier in this file already proves FOR that shape). ---
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, "←  ☐ Color  ☐ Animal  ✔ Submit  →", 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Pick one', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. A', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. B', 'Enter']);
    usleep(300000);
    $paneBeforeMultiAnswer = TmuxService::tmux_capture_pane($promptTestSession);

    $multiAnswered = PromptInteractionService::answer_prompt($promptTestSession, 1);
    assert_true($multiAnswered['ok'] ?? false, 'answer_prompt: ok=true for a currently-offered option on a multi-question prompt');
    usleep(300000);

    $paneAfterMultiAnswer = TmuxService::tmux_capture_pane($promptTestSession);
    assert_equal(
        $paneBeforeMultiAnswer,
        $paneAfterMultiAnswer,
        'answer_prompt: for a multi-question prompt, the pane is UNCHANGED after answering - the digit alone never completes a line without a following Enter, proving none was sent'
    );

    // --- PromptInteractionService::answer_multi_question(): answers every question of
    // a multi-question AskUserQuestion prompt in one shot, computed from
    // SessionStatusStore's hook-fed questions[] rather than the pane (see
    // PromptParser::build_multi_question_key_sequence()'s own docblock for
    // the confirmed real mechanics this drives). Sad paths first, matching
    // this project's own coverage discipline - only the final accept-path
    // case actually sends anything. ---
    $multiQuestionSet = [
        ['question' => 'Pick a color', 'header' => 'Color', 'multiSelect' => false, 'options' => [['label' => 'Red'], ['label' => 'Blue'], ['label' => 'Green']]],
        ['question' => 'Pick toppings', 'header' => 'Toppings', 'multiSelect' => true, 'options' => [['label' => 'Cheese'], ['label' => 'Pepperoni'], ['label' => 'Mushroom']]],
        ['question' => 'Confirm the order?', 'header' => 'Confirm', 'multiSelect' => false, 'options' => [['label' => 'Yes'], ['label' => 'No']]],
    ];

    assert_equal(
        false,
        PromptInteractionService::answer_multi_question('cc-not-a-real-session', [1, [1], 1])['ok'] ?? null,
        'answer_multi_question: rejects a session name that is not currently live'
    );

    // No SessionStatusStore data at all yet for this session -> rejected,
    // same "hooks fully own this, no fallback" reasoning as build_session_entry().
    SessionStatusStore::delete_status($promptTestSession);
    assert_equal(
        false,
        PromptInteractionService::answer_multi_question($promptTestSession, [1, [1], 1])['ok'] ?? null,
        'answer_multi_question: rejects when there is no hook-fed status at all'
    );

    // Status says blocked, but on a plain Bash prompt, not AskUserQuestion.
    SessionStatusStore::write_status($promptTestSession, ['status' => 'blocked', 'blocked' => ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]]);
    assert_equal(
        false,
        PromptInteractionService::answer_multi_question($promptTestSession, [1, [1], 1])['ok'] ?? null,
        'answer_multi_question: rejects when the blocked tool is not AskUserQuestion'
    );

    // AskUserQuestion, but only 1 question - no tab bar exists for that shape,
    // use answer_prompt()/answer_prompt_with_text() instead.
    SessionStatusStore::write_status($promptTestSession, ['status' => 'blocked', 'blocked' => ['tool_name' => 'AskUserQuestion', 'tool_input' => ['questions' => [$multiQuestionSet[0]]]]]);
    assert_equal(
        false,
        PromptInteractionService::answer_multi_question($promptTestSession, [1])['ok'] ?? null,
        'answer_multi_question: rejects a single-question AskUserQuestion (no tab bar exists for one question)'
    );

    // Real multi-question hook data, but the pane doesn't show it at all
    // (still whatever was left from the earlier answer_prompt() tests above)
    // - the live pre-flight check must catch this, not blindly trust the
    // hook data alone.
    SessionStatusStore::write_status($promptTestSession, ['status' => 'blocked', 'blocked' => ['tool_name' => 'AskUserQuestion', 'tool_input' => ['questions' => $multiQuestionSet]]]);
    assert_equal(
        false,
        PromptInteractionService::answer_multi_question($promptTestSession, [1, [1], 1])['ok'] ?? null,
        'answer_multi_question: rejects when the live pane does not show this prompt\'s first question (stale/already-moved-on)'
    );

    // Malformed $answers (PromptParser::build_multi_question_key_sequence()
    // itself already unit-tests every rejection shape - this just confirms
    // answer_multi_question() actually wires that rejection through end to
    // end, not just "some other reason" like the pane check above).
    //
    // Real ANSI clear-screen first (same mechanism replay_fixture.php's own
    // "clear_before" uses) - without it, this pane's own accumulated
    // history from every earlier test above (none of which happened to
    // include a "●" marker line either) has no boundary for
    // parse_blocking_prompt()'s BLOCKING_PROMPT_CONTEXT_WINDOW fallback to
    // stop at, so it merges everything back to "Do you want to proceed?"
    // into one giant paragraph instead of just this prompt's own "Pick a
    // color" - found live writing this exact test. A real Claude Code pane
    // never has this problem: every new interactive prompt is its own full
    // redraw, never a bare append onto old content like this fixture would
    // do without the explicit clear.
    //
    // The blank line between the tab bar and the question text matters too
    // - a real captured multi-question tab bar always has one there (see
    // full-session.replay.json's own "Multi-question prompt: Q1" step);
    // without it, parse_blocking_prompt()'s paragraph-grouping (joining
    // CONSECUTIVE non-blank lines into one paragraph) merges the tab bar
    // itself into the question text - also found live writing this test.
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '-l', "\x1b[2J\x1b[H"]);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, "←  ☐ Color  ☐ Toppings  ☐ Confirm  ✔ Submit  →", 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'Pick a color', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '❯ 1. Red', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  2. Blue', 'Enter']);
    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, '  3. Green', 'Enter']);
    usleep(300000);

    assert_equal(
        false,
        PromptInteractionService::answer_multi_question($promptTestSession, [1, [1]])['ok'] ?? null,
        'answer_multi_question: rejects answers that do not match the real questions (wrong count) even with a matching live pane'
    );

    // --- accept path: real pane match + valid answers -> sends the whole
    // computed sequence without any individual tmux command erroring. Not
    // re-asserting the exact byte-for-byte pane content afterward (unlike
    // the plain-digit answer_prompt() case above) - most of this sequence
    // is escape-sequence Right-arrow presses cat's canonical-mode echo
    // never renders as visible text (same reasoning as the plain arrow-key
    // presses used elsewhere in this file); the sequence CONTENT
    // itself (which digits, in which order) is already thoroughly covered
    // by PromptParser::build_multi_question_key_sequence()'s own unit tests
    // in tests/test_session_hook.php - this only needs to prove
    // answer_multi_question() actually reaches send_keys and every call in
    // the loop succeeds. ---
    $multiQuestionAnswered = PromptInteractionService::answer_multi_question($promptTestSession, [1, [1, 2], 1]);
    assert_true($multiQuestionAnswered['ok'] ?? false, 'answer_multi_question: ok=true for a real matching prompt with valid answers for every question');

    $statusAfterMultiAnswer = SessionStatusStore::read_status($promptTestSession);
    assert_equal('working', $statusAfterMultiAnswer['status'] ?? null, 'answer_multi_question: marks the session working once the whole sequence is sent, same as answer_prompt()');
    assert_true(array_key_exists('blocked', $statusAfterMultiAnswer) && $statusAfterMultiAnswer['blocked'] === null, 'answer_multi_question: clears the blocked status, same as answer_prompt()');

    SessionStatusStore::delete_status($promptTestSession);

    // --- PromptInteractionService::set_mode(): jumps straight to a chosen mode by working out how
    // many Shift+Tab ("BTab") presses that is from the current mode, read
    // live from the pane. Each press is itself an escape sequence (not a
    // printable byte cat echoes back visibly, same as the Right-arrow
    // presses used elsewhere in this file), but here the *count* of presses is
    // independently verifiable: seed the pane with a real status line
    // (echoed back by cat) so PermissionMode::parse_current_mode() reads a known starting
    // mode, then confirm ok=true for the multi-step jump. ---
    assert_equal(false, PromptInteractionService::set_mode($promptTestSession, 'not-a-real-mode')['ok'] ?? null, 'set_mode: rejects an unrecognized mode');
    assert_equal(false, PromptInteractionService::set_mode('cc-not-a-real-session', 'plan')['ok'] ?? null, 'set_mode: rejects a session name that is not currently live');
    assert_equal(
        false,
        PromptInteractionService::set_mode($promptTestSession, 'plan')['ok'] ?? null,
        'set_mode: rejects when the current mode cannot be read from the pane (no real Claude Code status line here yet)'
    );

    TmuxService::tmux_run(['send-keys', '-t', $promptTestSession, 'manual mode on', 'Enter']);
    usleep(300000);
    $modeSet = PromptInteractionService::set_mode($promptTestSession, 'auto'); // manual -> auto is 3 steps, the largest possible jump
    assert_true($modeSet['ok'] ?? false, 'set_mode: ok=true once the current mode is readable, for a live session');
    assert_equal(
        'auto',
        SessionStatusStore::read_status($promptTestSession)['mode'] ?? null,
        'set_mode: also updates SessionStatusStore\'s own cached mode field, so the next poll reflects the change instead of snapping back to the stale value - the actual bug reported live 2026-08-23'
    );

    // --- PromptInteractionService::set_model(): drives the real /model picker's
    // Up-normalize-to-row-1-then-Down-to-target-then-'s' sequence (see its
    // own docblock for the full mechanics, confirmed live 2026-08-24
    // against a real running session - not independently re-verifiable
    // here, same reasoning set_mode()'s own test above already settled on:
    // Up/Down/Enter are escape sequences, not printable bytes cat echoes
    // back visibly). Unlike set_mode(), no "current mode must be readable
    // first" guard exists - the sequence always normalizes to row 1
    // itself, so there's nothing to read from the pane up front. ---
    assert_equal(false, PromptInteractionService::set_model($promptTestSession, 'not-a-real-model')['ok'] ?? null, 'set_model: rejects an unrecognized model');
    assert_equal(false, PromptInteractionService::set_model('cc-not-a-real-session', 'opus')['ok'] ?? null, 'set_model: rejects a session name that is not currently live');

    SessionStatusStore::update_status($promptTestSession, ['status' => 'blocked', 'blocked' => ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]]);
    assert_equal(
        false,
        PromptInteractionService::set_model($promptTestSession, 'opus')['ok'] ?? null,
        'set_model: rejects while the session is blocked on a prompt - the pane isn\'t showing its normal input line'
    );
    SessionStatusStore::delete_status($promptTestSession);

    $modelSet = PromptInteractionService::set_model($promptTestSession, 'haiku'); // row 5, the largest possible jump (5 ups + 4 downs + 's')
    assert_true($modelSet['ok'] ?? false, 'set_model: ok=true for a live, unblocked session');
    assert_true(str_contains($modelSet['message'] ?? '', 'haiku'), 'set_model: success message names the model that was set');

    // --- PromptInteractionService::send_escape(): interrupts whatever
    // Claude is currently doing (mid-generation or mid-tool-call), same as
    // pressing Escape while attached. Found live 2026-08-30: Escape is a
    // true interrupt (not a natural turn completion), so the Stop hook -
    // which fires ONLY on natural completion - never fires. Without the
    // update_status() call after the tmux send-keys, a session interrupted
    // mid-turn has no mechanism to ever clear its stale `working: true`
    // status. This is the same bug class already fixed for set_mode()
    // (see that method's own docblock, 2026-08-23). ---
    SessionStatusStore::update_status($promptTestSession, ['status' => 'working', 'blocked' => null]);
    assert_equal('working', SessionStatusStore::read_status($promptTestSession)['status'] ?? null, 'send_escape test setup: seeded SessionStatusStore with working status');

    $escapeResult = PromptInteractionService::send_escape($promptTestSession);
    assert_true($escapeResult['ok'] ?? false, 'send_escape: ok=true for a live session');

    assert_equal(
        'idle',
        SessionStatusStore::read_status($promptTestSession)['status'] ?? null,
        'send_escape: also updates SessionStatusStore to mark the session idle, so the next poll reflects the interrupted state instead of staying stuck on working - the actual bug reported live 2026-08-30'
    );
    assert_true(array_key_exists('blocked', SessionStatusStore::read_status($promptTestSession)) && SessionStatusStore::read_status($promptTestSession)['blocked'] === null, 'send_escape: clears the blocked status, same as other prompt-interaction methods');

    TmuxService::tmux_run(['kill-session', '-t', $promptTestSession]);
    SidecarStore::delete_sidecar($promptTestSession);
    $promptTestSession = null;

    // --- PromptInteractionService::answer_prompt(): the initial per-folder
    // trust dialog specifically. Found live 2026-09-11 (Andres: couldn't
    // start a session in a new folder): Claude Code v2.1.269 dropped this
    // dialog's option numbers entirely (see PromptParser's own docblock),
    // so the plain digit-then-Enter path used for every other prompt shape
    // would type a digit the dialog just ignores, then Enter confirms
    // whatever's DEFAULT-highlighted instead ("No, exit") - silently
    // killing the whole session rather than trusting the folder. This
    // verifies the ACTUAL keys sent, not just the reported ok/message:
    // cat's own stdout is teed to a raw log file, bypassing tmux's own
    // escape-sequence interpretation of the captured "screen" (a bare
    // Up/Down arrow moves the virtual cursor without printing anything
    // visible, so the plain capture-pane trick the test above uses
    // wouldn't show whether an arrow was sent at all) - so both the real
    // Down-arrow-then-Enter sequence, and the absence of a literal digit,
    // can be checked directly. ---
    $folderTrustTestSession = 'cc-test-trust-answer-' . getmypid();
    $folderTrustKeyLog = sys_get_temp_dir() . '/sessioneer-test-trust-keys-' . getmypid() . '.bin';
    @unlink($folderTrustKeyLog);
    $trustSetup = TmuxService::tmux_run([
        'new-session', '-d', '-s', $folderTrustTestSession, '-c', Config::www_root(),
        'bash', '-c', 'stty -echo; exec cat | tee ' . escapeshellarg($folderTrustKeyLog),
    ]);
    assert_equal(0, $trustSetup['exit'], 'answer_prompt (folder trust) setup: created a live cc-* session to answer the trust dialog in');
    SidecarStore::write_sidecar($folderTrustTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    // Types the real (unnumbered, reversed-order) v2.1.269 dialog text in
    // verbatim, one real pane line per fixture line - same technique the
    // digit-based prompt tests above use, just with the real captured
    // fixture instead of a hand-typed shape.
    foreach (explode("\n", $realTrustDialogV21269) as $fixtureLine) {
        TmuxService::tmux_run(['send-keys', '-t', $folderTrustTestSession, '-l', $fixtureLine]);
        TmuxService::tmux_run(['send-keys', '-t', $folderTrustTestSession, 'Enter']);
    }
    usleep(300000);

    // The fixture text itself (a real captured workspace path, e.g.
    // ".../blah-newproj-test3-1789184921") legitimately contains digits,
    // including "2" - only the bytes answer_prompt() itself sends, AFTER
    // this setup phase, are what the assertions below care about.
    $trustKeyLogOffsetBeforeAnswer = filesize($folderTrustKeyLog);

    $trustAnswer = PromptInteractionService::answer_prompt($folderTrustTestSession, 2); // option 2 = "Yes, I trust this folder"
    assert_true($trustAnswer['ok'] ?? false, 'answer_prompt: folder trust dialog answers ok=true');
    assert_equal(
        "Confirmed 'Yes, I trust this folder' for {$folderTrustTestSession}",
        $trustAnswer['message'] ?? null,
        'answer_prompt: folder trust dialog reports the real option label, resolved from the live cursor position rather than a fixed offset'
    );
    usleep(300000);

    $trustKeysSent = substr((string)file_get_contents($folderTrustKeyLog), $trustKeyLogOffsetBeforeAnswer);
    assert_true(!str_contains($trustKeysSent, '2'), 'answer_prompt: folder trust dialog - never sends the bare digit "2" (the old, now-broken behavior) - v2.1.269 ignores it entirely and would go on to confirm whatever is already default-highlighted instead of the requested option');
    assert_true(str_ends_with($trustKeysSent, "\x1b[B\n"), 'answer_prompt: folder trust dialog - sends exactly one Down-arrow (off the default "No, exit") then Enter, to actually reach "Yes, I trust this folder"');

    TmuxService::tmux_run(['kill-session', '-t', $folderTrustTestSession]);
    SidecarStore::delete_sidecar($folderTrustTestSession);
    @unlink($folderTrustKeyLog);
    $folderTrustTestSession = null;

    // --- TmuxService::tmux_capture_pane(): a long single logical line (e.g. the command
    // in a permission prompt) that the terminal soft-wraps across several
    // pane rows must come back rejoined into one line, not split mid-word -
    // this is what PromptParser::parse_blocking_prompt() relies on to show the real,
    // complete command rather than a mangled fragment. Verified against a
    // real narrow pane, not a hand-fed string. ---
    $wrapTestSession = 'cc-test-capture-wrap-' . getmypid();
    $wrapSetup = TmuxService::tmux_run(['new-session', '-d', '-x', '60', '-y', '20', '-s', $wrapTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $wrapSetup['exit'], 'tmux_capture_pane wrap test setup: created a narrow live pane');
    usleep(300000);

    $longCommand = "ssh media 'rm /tmp/apply_dashboard.py /tmp/another_very_long_filename_that_will_definitely_wrap_across_the_narrow_pane_width.py'";
    TmuxService::tmux_run(['set-buffer', '--', $longCommand]);
    TmuxService::tmux_run(['paste-buffer', '-t', $wrapTestSession]);
    TmuxService::tmux_run(['send-keys', '-t', $wrapTestSession, 'Enter']);
    usleep(300000);

    assert_true(
        str_contains(TmuxService::tmux_capture_pane($wrapTestSession), $longCommand),
        'tmux_capture_pane: a long line the terminal soft-wrapped across multiple pane rows is rejoined intact (-J), not split mid-word'
    );

    TmuxService::tmux_run(['kill-session', '-t', $wrapTestSession]);
    $wrapTestSession = null;

    // --- PromptInteractionService::send_message(): sends free text to a session's pane via tmux
    // paste-buffer + Enter, exactly as if a human had typed it while
    // attached. Verified end-to-end against a real pane: the full
    // multi-line message lands as one block (echoed back by cat), not
    // split into separate premature submits the way send-keys with the
    // raw text would (each embedded newline acting as its own Enter). ---
    $sendTestSession = 'cc-test-send-message-' . getmypid();
    $sendSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $sendTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $sendSetup['exit'], 'send_message setup: created a live cc-* session to send a message to');
    SidecarStore::write_sidecar($sendTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    assert_equal(false, PromptInteractionService::send_message('cc-not-a-real-session', 'hello')['ok'] ?? null, 'send_message: rejects a session name that is not currently live');
    assert_equal(false, PromptInteractionService::send_message($sendTestSession, '   ')['ok'] ?? null, 'send_message: rejects a whitespace-only message');

    $sent = PromptInteractionService::send_message($sendTestSession, "Line one\nLine two");
    assert_true($sent['ok'] ?? false, 'send_message: ok=true for a live session');
    usleep(300000);

    $paneAfterSend = TmuxService::tmux_capture_pane($sendTestSession);
    assert_true(
        str_contains($paneAfterSend, 'Line one') && str_contains($paneAfterSend, 'Line two'),
        'send_message: the full multi-line message landed in the pane (echoed back by cat), not split into separate premature submits'
    );

    // --- send_message()'s $attachmentPaths param: compose-bar file uploads
    // still pending when Send is pressed each become their own "[Attached:
    // path]" line, added here (not client-side) so the user's own typed
    // draft never shows that bookkeeping text - see session.js's compose-
    // attachments preview. An attachment with no typed text at all is a
    // valid send on its own. ---
    $sentAttachmentOnly = PromptInteractionService::send_message($sendTestSession, '', ['.claude/uploads/report.pdf']);
    assert_true($sentAttachmentOnly['ok'] ?? false, 'send_message: an attachment with no typed text at all is still a valid send');
    usleep(300000);
    assert_contains('[Attached: .claude/uploads/report.pdf]', TmuxService::tmux_capture_pane($sendTestSession), 'send_message: the attachment line lands in the pane even with no typed text');

    $sentWithText = PromptInteractionService::send_message($sendTestSession, 'Check this out', ['.claude/uploads/photo.png']);
    assert_true($sentWithText['ok'] ?? false, 'send_message: typed text plus an attachment together is a valid send');
    usleep(300000);
    $paneAfterBoth = TmuxService::tmux_capture_pane($sendTestSession);
    assert_true(
        str_contains($paneAfterBoth, 'Check this out') && str_contains($paneAfterBoth, '[Attached: .claude/uploads/photo.png]'),
        'send_message: typed text and the attachment line both land in the pane, on separate lines'
    );

    TmuxService::tmux_run(['kill-session', '-t', $sendTestSession]);
    SidecarStore::delete_sidecar($sendTestSession);
    $sendTestSession = null;

    // --- SessionService::build_session_entry(): the hook-fed SessionStatusStore
    // is the ONLY source for mode/working-status/blocked-prompt-content (see
    // host-agent/hooks/{permission_request,user_prompt_submit,stop}.php and
    // the todo file's research entry) - these hooks are mandatory (see the
    // dashboard's health box), not "preferred with a pane-scraping fallback".
    // Proven here against a pane that never shows ANY Claude Code output at
    // all (a bare `cat` pane, same as send_message()'s own setup above):
    // every signal asserted below can only have come from the status file,
    // never from the empty pane - and, further down, that an EMPTY status
    // file genuinely yields nothing (no fallback), except for the one
    // permanent carve-out (the trust dialog, which fires no hooks at all). ---
    $statusEntrySession = 'cc-test-hook-status-' . getmypid();
    TmuxService::tmux_run(['new-session', '-d', '-s', $statusEntrySession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    SidecarStore::write_sidecar($statusEntrySession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    SessionStatusStore::write_status($statusEntrySession, [
        'status' => 'blocked',
        'mode' => 'accept edits',
        'blocked' => [
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'rtk curl example.com', 'description' => 'Fetch example.com'],
            'permission_suggestions' => [['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'rtk curl *']], 'behavior' => 'allow', 'destination' => 'localSettings']],
        ],
    ]);
    $hookBlockedEntry = find_session($statusEntrySession);
    assert_equal('Do you want to proceed?', $hookBlockedEntry['blocked_reason'] ?? null, 'build_session_entry: blocked_reason comes from the hook-fed status file, not the (empty) pane');
    assert_equal("Fetch example.com\n\nrtk curl example.com", $hookBlockedEntry['prompt_context'] ?? null, 'build_session_entry: prompt_context is the full hook-sourced tool_input rendering');
    assert_equal(
        [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => "Yes, and don't ask again for: rtk curl *"], ['number' => 3, 'label' => 'No']],
        $hookBlockedEntry['prompt_options'] ?? null,
        'build_session_entry: prompt_options built from the status file\'s permission_suggestions'
    );
    assert_equal('Bash', $hookBlockedEntry['prompt_tool_name'] ?? null, 'build_session_entry: prompt_tool_name comes from the status file');
    assert_equal('accept edits', $hookBlockedEntry['current_mode'] ?? null, 'build_session_entry: current_mode comes from the status file, not PermissionMode::parse_current_mode() on an empty pane');
    assert_equal(false, $hookBlockedEntry['working'] ?? null, 'build_session_entry: working=false while hook-fed status is blocked');

    // AskUserQuestion bypasses the hook-fed path entirely for prompt CONTENT
    // (see build_session_entry()'s own comment) - it keeps using
    // PromptParser::parse_blocking_prompt() on the real pane, which finds
    // nothing here (still empty), so blocked_reason must be null even
    // though the status file itself says blocked.
    SessionStatusStore::write_status($statusEntrySession, [
        'status' => 'blocked',
        'mode' => 'manual',
        'blocked' => [
            'tool_name' => 'AskUserQuestion',
            'tool_input' => ['questions' => [['question' => 'Pick a fruit', 'header' => 'Fruit', 'options' => [['label' => 'Apple'], ['label' => 'Banana']]]]],
        ],
    ]);
    $hookAskUserQuestionEntry = find_session($statusEntrySession);
    assert_equal(null, $hookAskUserQuestionEntry['blocked_reason'], 'build_session_entry: AskUserQuestion never uses the hook-fed blocked prompt content - falls through to the (here, empty) pane-scraped path instead');

    // A plain "working" status (no blocked prompt at all) also comes from
    // the file, not the pane's own spinner-glyph title (which this bare cat
    // pane never sets).
    SessionStatusStore::write_status($statusEntrySession, ['status' => 'working', 'mode' => 'plan', 'blocked' => null]);
    $hookWorkingEntry = find_session($statusEntrySession);
    assert_equal(true, $hookWorkingEntry['working'] ?? null, 'build_session_entry: working=true from the status file alone, no pane spinner glyph needed');
    assert_equal(null, $hookWorkingEntry['blocked_reason'], 'build_session_entry: no blocked prompt while status is working');
    assert_equal('plan', $hookWorkingEntry['current_mode'] ?? null, 'build_session_entry: current_mode still comes from the status file');

    // No status file at all (predates these hooks, an adopted session with
    // no SESSIONEER_SESSION_NAME, or the hooks genuinely never installed) yields
    // NOTHING for mode/working/blocked_reason - there is no pane-scraping
    // fallback for these anymore, confirmed here against a pane that (still)
    // shows nothing at all, so a bug that silently reintroduced a fallback
    // reading real content off this specific pane would be caught by the
    // trust-dialog assertion right below, not accidentally masked by it.
    SessionStatusStore::delete_status($statusEntrySession);
    $noStatusFileEntry = find_session($statusEntrySession);
    assert_equal(null, $noStatusFileEntry['current_mode'], 'build_session_entry: no status file -> current_mode is unknown (no pane-scraping fallback anymore)');
    assert_equal(false, $noStatusFileEntry['working'] ?? null, 'build_session_entry: no status file -> working defaults to false (no pane-scraping fallback anymore)');
    assert_equal(null, $noStatusFileEntry['blocked_reason'], 'build_session_entry: no status file -> blocked_reason is null when the pane shows no real prompt either');

    // The ONE case that's still pane-scraped regardless of hook installation
    // status: the initial per-folder trust dialog, which fires none of
    // Claude Code's hooks at all (confirmed live, permanent - see
    // CONTRIBUTING.md). Still no status file here - proves this path is
    // reached independently of SessionStatusStore, not as some leftover
    // general-purpose fallback. Reuses $realTrustDialog (defined above, a
    // real capture already verified against PromptParser::
    // parse_blocking_prompt() directly) rather than hand-written text, so a
    // mismatch against the parser's own real-world expectations can't
    // silently pass here.
    foreach (explode("\n", rtrim($realTrustDialog, "\n"))  as $line) {
        TmuxService::tmux_run(['send-keys', '-t', $statusEntrySession, $line, 'Enter']);
    }
    usleep(300000);
    $trustDialogEntry = find_session($statusEntrySession);
    assert_equal(
        "Quick safety check: Is this a project you created or one you trust? (Like your own code, a well-known open source project, or work from your team). If not, take a moment to review what's in this folder first.",
        $trustDialogEntry['blocked_reason'],
        'build_session_entry: the trust dialog still comes from the live pane, with no status file at all'
    );
    assert_equal(true, $trustDialogEntry['prompt_is_folder_trust'] ?? null, 'build_session_entry: the trust dialog is flagged prompt_is_folder_trust');

    TmuxService::tmux_run(['kill-session', '-t', $statusEntrySession]);
    SidecarStore::delete_sidecar($statusEntrySession);
    SessionStatusStore::delete_status($statusEntrySession);
    $statusEntrySession = null;

    // --- send_message()/answer_prompt_with_text() concurrency isolation:
    // found live 2026-08-14 that both used tmux's single SHARED default
    // buffer (a bare `set-buffer`/`paste-buffer` with no -b) - this
    // host-agent handles every request as its OWN separate OS process
    // (systemd socket activation in production; tests/lib/socket_harness.php
    // mirrors that here), all sharing the same tmux server, so two
    // genuinely concurrent sends interleaving as (A sets, B sets - clobbers
    // A's staged text, A pastes, B pastes) would silently paste B's text
    // into A's pane. Reproduced live against two real panes with that exact
    // interleaving before the fix (A received "MESSAGE-FOR-B"); the fix
    // gives every call its own uniquely-named buffer instead.
    //
    // Real OS-level concurrency can't be driven deterministically from here
    // without either flaky timing or a test-only hook inside production
    // code (not doing that) - so this drives the exact worst-case
    // interleaving directly via TmuxService::tmux_run(), using the SAME
    // named-buffer + -d command shape the real fix uses, against two real
    // panes. This proves the TECHNIQUE the fix relies on is actually sound
    // (never flaky - no timing dependency at all) - it does NOT by itself
    // catch a future regression in send_message()/answer_prompt_with_text()
    // themselves, since it never calls them; the source-level check right
    // after this block is what actually guards that. ---
    $raceSessionA = 'cc-test-buffer-race-a-' . getmypid();
    $raceSessionB = 'cc-test-buffer-race-b-' . getmypid();
    TmuxService::tmux_run(['new-session', '-d', '-s', $raceSessionA, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    TmuxService::tmux_run(['new-session', '-d', '-s', $raceSessionB, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    usleep(300000);

    // A stages its own text into its OWN named buffer...
    TmuxService::tmux_run(['set-buffer', '-b', 'sessioneer-race-test-a', '--', 'MESSAGE-FOR-A']);
    // ...B's ENTIRE send (stage, paste, auto-delete via -d) completes fully
    // in between, on its OWN separate named buffer...
    TmuxService::tmux_run(['set-buffer', '-b', 'sessioneer-race-test-b', '--', 'MESSAGE-FOR-B']);
    TmuxService::tmux_run(['paste-buffer', '-d', '-b', 'sessioneer-race-test-b', '-t', $raceSessionB]);
    TmuxService::tmux_run(['send-keys', '-t', $raceSessionB, 'Enter']);
    // ...only THEN does A finally paste from its own still-untouched buffer.
    TmuxService::tmux_run(['paste-buffer', '-d', '-b', 'sessioneer-race-test-a', '-t', $raceSessionA]);
    TmuxService::tmux_run(['send-keys', '-t', $raceSessionA, 'Enter']);
    usleep(300000);

    assert_contains('MESSAGE-FOR-A', TmuxService::tmux_capture_pane($raceSessionA), 'buffer isolation: session A receives its own message even when B\'s entire send completes in between A\'s set and paste');
    assert_true(!str_contains(TmuxService::tmux_capture_pane($raceSessionA), 'MESSAGE-FOR-B'), 'buffer isolation: session A never receives B\'s message');
    assert_contains('MESSAGE-FOR-B', TmuxService::tmux_capture_pane($raceSessionB), 'buffer isolation: session B receives its own message');

    $leftoverBuffers = TmuxService::tmux_run(['list-buffers'])['stdout'];
    assert_equal('', trim($leftoverBuffers), 'buffer isolation: both named buffers were auto-deleted by paste-buffer -d, none leaked');

    TmuxService::tmux_run(['kill-session', '-t', $raceSessionA]);
    TmuxService::tmux_run(['kill-session', '-t', $raceSessionB]);
    $raceSessionA = null;
    $raceSessionB = null;

    // --- The actual regression guard for the fix above: confirms
    // send_message() and answer_prompt_with_text() themselves genuinely
    // use a named (-b) buffer at BOTH their set-buffer and paste-buffer
    // call sites, not tmux's shared default one - a plain source check
    // rather than another behavioral one, since truly forcing these two
    // specific call sites to interleave via real OS concurrency from a
    // test would be inherently flaky (no artificial-delay hook exists in
    // either function, deliberately not adding one for testability alone).
    // Deterministic and fails immediately if either call site is ever
    // reverted to the bare, shared-buffer form. ---
    $promptInteractionSource = (string)file_get_contents(dirname(__DIR__) . '/host-agent/lib/Services/PromptInteractionService.php');
    assert_equal(
        0,
        preg_match('/set-buffer\',\s*\'--\'/', $promptInteractionSource),
        'buffer isolation: PromptInteractionService.php never uses a bare set-buffer with no -b (the shared, unsafe default buffer)'
    );
    assert_equal(
        0,
        preg_match('/paste-buffer\',\s*\'-t\'/', $promptInteractionSource),
        'buffer isolation: PromptInteractionService.php never uses paste-buffer with no -b (would paste from the shared default buffer)'
    );

    // --- send_message()'s own regression guard (found live 2026-08-20):
    // sending Enter with no gap right after paste-buffer can be processed
    // before the pane has actually registered the pasted text, submitting
    // nothing - the message sits typed but unsent, no thinking indicator
    // ever appears, and the client's own optimistic bubble is stuck
    // "Sending…" forever since there's no real transcript entry to ever
    // match it against. answer_prompt_with_text() already had the fix
    // (TMUX_KEY_STEP_DELAY_USEC between its own paste-buffer and Enter -
    // see that constant's own doc comment for the original, already-
    // proven-live race this same gap fixes elsewhere); send_message() was
    // simply missing it. Same reasoning as the buffer-isolation checks
    // above for testing this as a source shape rather than a behavioral
    // race - real OS-level timing isn't something a test can force
    // deterministically without an artificial delay hook this app doesn't
    // have (and shouldn't add just for testability). ---
    $sendMessageBody = null;

    if (preg_match('/function send_message\(.*?\n    \}\n/s', $promptInteractionSource, $sendMessageMatch) === 1) {
        $sendMessageBody = $sendMessageMatch[0];
    }

    assert_true($sendMessageBody !== null, 'send_message(): found in PromptInteractionService.php source (sanity check for the assertion below)');
    assert_true(
        $sendMessageBody !== null && str_contains($sendMessageBody, 'usleep(self::TMUX_KEY_STEP_DELAY_USEC)'),
        'send_message(): waits TMUX_KEY_STEP_DELAY_USEC between paste-buffer and the confirming Enter, same as answer_prompt_with_text()'
    );

    // --- Full-feature parity for an adopted (non-cc-*) session: "tracked"
    // is now sidecar-existence, not the cc-* prefix (see TmuxService::
    // list_tracked_tmux_sessions()), so a session session_start.php adopted
    // - real tmux session, real sidecar, name never touched - must show up
    // in list_all_sessions()'s main sessions[] (not bare[]), report
    // spawned_by_app=false, and accept the exact same actions (send_message,
    // kill_agent_session) as an app-spawned cc-* one. Simulates the hook's own
    // sidecar write directly rather than re-running session_start.php here -
    // that hook's own behavior is covered separately in test_session_hook.php. ---
    $adoptedTestSession = 'user-manual-' . getmypid();
    $adoptedSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $adoptedTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $adoptedSetup['exit'], 'adopted session setup: created a live non-cc-* tmux session');
    SidecarStore::write_sidecar($adoptedTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time(), 'spawned_by_app' => false]);
    usleep(300000);

    $listed = SessionService::list_all_sessions();
    $adoptedEntry = null;

    foreach ($listed['sessions'] as $s) {
        if ($s['name'] === $adoptedTestSession) {
            $adoptedEntry = $s;
            break;
        }
    }

    assert_true($adoptedEntry !== null, 'list: an adopted (non-cc-*) session with a sidecar appears in sessions[], not just bare[]');
    assert_equal(false, $adoptedEntry['spawned_by_app'] ?? null, 'list: an adopted session reports spawned_by_app=false');
    assert_true(
        !in_array($adoptedTestSession, array_column($listed['bare'], 'tmux_session'), true),
        'list: an adopted, now-tracked session is not double-counted in bare[]'
    );

    $adoptedSend = PromptInteractionService::send_message($adoptedTestSession, 'Hello from an adopted session');
    assert_true($adoptedSend['ok'] ?? false, 'send_message: works against an adopted (non-cc-*) session, not just cc-* ones');
    usleep(300000);
    assert_contains('Hello from an adopted session', TmuxService::tmux_capture_pane($adoptedTestSession), 'send_message: the text actually landed in the adopted session\'s pane');

    $adoptedKill = SessionLifecycleService::kill_agent_session($adoptedTestSession);
    assert_true($adoptedKill['ok'] ?? false, 'kill_agent_session: works against an adopted (non-cc-*) session, not just cc-* ones');
    $hasAdoptedSession = TmuxService::tmux_run(['has-session', '-t', $adoptedTestSession]);
    assert_true($hasAdoptedSession['exit'] !== 0, 'kill_agent_session: the adopted session\'s tmux session is actually gone');
    assert_true(SidecarStore::read_sidecar($adoptedTestSession) === null, 'kill_agent_session: the adopted session\'s sidecar is removed too');
    $adoptedTestSession = null;

    // --- QuotaService::quota_from_statusline_state()/get_quota(): the ONLY
    // account-wide quota source now (a live tmux-pane-scraping fallback,
    // and the external claude-quota-binary scrape behind that, were both
    // deleted 2026-08-22 as confirmed dead code - see QuotaService's own
    // class docblock for why). The merge/protection logic the shell side
    // itself does is exercised end-to-end in test_statusline_marker.php;
    // this only checks QuotaService's read side and get_quota()'s "no data
    // at all yet" case. ---
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null before the statusline script has ever fired');

    $getQuotaNoData = QuotaService::get_quota();
    assert_equal(false, $getQuotaNoData['ok'] ?? null, 'get_quota(): ok=false with no quota data at all yet');
    assert_equal(null, $getQuotaNoData['quota'], 'get_quota(): no data yet -> quota is null');

    GlobalStateStore::write(Config::quota_live_state_key(), [
        'session' => ['pct' => 70, 'resets_at' => time() + 3600],
        'week_all' => ['pct' => 60, 'resets_at' => time() + 86400],
        'captured_at' => time(),
    ]);

    $fromStatusline = QuotaService::quota_from_statusline_state();
    assert_true($fromStatusline !== null, 'quota_from_statusline_state: reads a well-formed state file');
    assert_equal(70, $fromStatusline['quota']['session']['pct'] ?? null, 'quota_from_statusline_state: session pct read straight from the file, no tmux involved');
    assert_equal(60, $fromStatusline['quota']['week_all']['pct'] ?? null, 'quota_from_statusline_state: week_all pct read straight from the file');

    $getQuotaResult = QuotaService::get_quota();
    assert_equal(true, $getQuotaResult['ok'] ?? null, 'get_quota(): ok=true once the statusline state file has data');
    assert_equal(70, $getQuotaResult['quota']['session']['pct'] ?? null, 'get_quota(): reads straight from the statusline state file');
    assert_equal(false, $getQuotaResult['cached'] ?? null, 'get_quota(): a live statusline reading is never reported as cached');
    assert_true(!isset($getQuotaResult['quota']['context']), 'get_quota(): no context bucket when called without a session name');

    // --- QuotaService::live_context_pct()/get_quota($sessionName): the
    // per-session context-window overlay - genuinely distinct from the
    // account-wide session/week_all buckets above. Reads
    // StatuslineMarkerService's own JSON marker off a live pane, keyed to
    // one specific session on purpose - crafted via a raw `cat` pane like
    // the wrap-test above, since fake_claude never renders a real status
    // line. ---
    $quotaTestSession = 'cc-test-quota-' . getmypid();
    $quotaSetup = TmuxService::tmux_run(['new-session', '-d', '-s', $quotaTestSession, '-c', Config::www_root(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $quotaSetup['exit'], 'live_context_pct setup: created a live cc-* session');
    SidecarStore::write_sidecar($quotaTestSession, ['workdir' => Config::www_root(), 'spawned_at' => time()]);
    usleep(300000);

    assert_equal(null, QuotaService::live_context_pct($quotaTestSession), 'live_context_pct: null while the pane has no marker yet');

    TmuxService::tmux_run(['send-keys', '-t', $quotaTestSession, 'sessioneer-data:{"ctx_pct":4}', 'Enter']);
    usleep(300000);

    assert_equal(4, QuotaService::live_context_pct($quotaTestSession), 'live_context_pct: reads the ctx percentage from the requested session\'s own pane');
    assert_equal(null, QuotaService::live_context_pct('cc-not-a-real-session'), 'live_context_pct: null for a session that isn\'t live');

    $withContext = QuotaService::get_quota($quotaTestSession);
    assert_equal(4, $withContext['context']['pct'] ?? null, 'get_quota($session): returns context at top level when readable (NEW)');
    assert_equal(70, $withContext['quota']['session']['pct'] ?? null, 'get_quota($session): session/week_all buckets are unaffected by the context overlay');
    assert_true(!isset($withContext['context']['resets_at']), 'get_quota($session): context has no reset timer, unlike session/week_all');
    assert_true(isset($withContext['agents']), 'get_quota($session): now includes agents map (NEW)');

    $unknownSessionResult = QuotaService::get_quota('cc-not-a-real-session');
    assert_true(!isset($unknownSessionResult['context']), 'get_quota($session): no top-level context field when the given session isn\'t live');
    assert_equal(70, $unknownSessionResult['quota']['session']['pct'] ?? null, 'get_quota($session): account-wide buckets still come through for an unknown session name');

    GlobalStateStore::delete(Config::quota_live_state_key());
    assert_equal(null, QuotaService::quota_from_statusline_state(), 'quota_from_statusline_state: null once the state row is gone (fresh install, or before any statusline event)');

    $getQuotaAfterDelete = QuotaService::get_quota();
    assert_equal(false, $getQuotaAfterDelete['ok'] ?? null, 'get_quota(): ok=false again once the state file is gone - no fallback left to catch it');

    TmuxService::tmux_run(['kill-session', '-t', $quotaTestSession]);
    SidecarStore::delete_sidecar($quotaTestSession);
    $quotaTestSession = null;
} finally {
    // Defense in depth - tests/run.sh's `tmux kill-server` on the isolated
    // socket is the real backstop regardless of what happens here, but
    // clean up explicitly too in case this script is ever run standalone.
    @unlink($pushSqliteFixture);
    @unlink($pushSqliteFixture . '-wal');
    @unlink($pushSqliteFixture . '-shm');
    @unlink($opencodeDbFixtureLc);
    @rmdir(dirname($opencodeDbFixtureLc));
    @unlink($opencodeAuthFixtureLc);
    @rmdir(dirname($opencodeAuthFixtureLc));
    putenv('OPENCODE_DB_PATH');
    foreach ($createdSessions as $leftover) {
        SessionLifecycleService::kill_agent_session($leftover);
    }
    if ($adhocName !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $adhocName]);
    }
    if ($promptTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $promptTestSession]);
    }
    if ($folderTrustTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $folderTrustTestSession]);
    }
    if ($archivedBareAdhocName !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $archivedBareAdhocName]);
    }
    if ($resumeArgTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $resumeArgTestSession]);
    }
    if ($realpathMatchTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $realpathMatchTestSession]);
    }
    if ($daemonLabelTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $daemonLabelTestSession]);
    }
    if ($rosterBareProc !== null && is_resource($rosterBareProc)) {
        proc_terminate($rosterBareProc);
        proc_close($rosterBareProc);
    }
    if ($sendTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $sendTestSession]);
    }
    if ($raceSessionA !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $raceSessionA]);
    }
    if ($raceSessionB !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $raceSessionB]);
    }
    if ($adoptedTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $adoptedTestSession]);
    }
    if ($wrapTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $wrapTestSession]);
    }
    if ($quotaTestSession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $quotaTestSession]);
    }
    if ($statusEntrySession !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $statusEntrySession]);
        SessionStatusStore::delete_status($statusEntrySession);
    }
    if ($bareProc !== null && is_resource($bareProc)) {
        proc_terminate($bareProc);
        proc_close($bareProc);
    }
    if ($takeOverBareProc !== null && is_resource($takeOverBareProc)) {
        proc_terminate($takeOverBareProc);
        proc_close($takeOverBareProc);
    }
    if ($markerAdhocName !== null) {
        TmuxService::tmux_run(['kill-session', '-t', $markerAdhocName]);
    }
    foreach ($dualBareAdhocNames as $leftoverDualBare) {
        TmuxService::tmux_run(['kill-session', '-t', $leftoverDualBare]);
    }
    // All three take-over blocks above always restore HOME_ROOT before
    // falling through to later tests, but if anything in one of them
    // throws partway through (a strict-types TypeError, say), that
    // putenv() never runs - clear it here too, defense in depth, so a
    // mid-block failure can't leak a fake HOME_ROOT into whatever runs
    // next.
    putenv('HOME_ROOT');
}

test_exit();
