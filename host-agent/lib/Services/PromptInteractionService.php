<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Sending input to a live session's pane: prompt answers (plain,
 * free-text, and multi-question), mode/model switches, escape, and plain
 * messages. Split out of SessionService.php (2026-08-24 readability
 * audit - see the plan this followed) - these 7 methods had zero
 * `self::` cross-references into any other cluster, the single safest,
 * most isolated piece to extract first. Methods/bodies moved verbatim,
 * no behavior changes.
 */
class PromptInteractionService
{
    /**
     * A 300ms gap between rapid, related keypresses sent to a live Claude
     * Code pane - not cosmetic, verified live twice over: (1) 3 BTab presses
     * with no gap between them landed 2 steps short (a key got dropped), 300ms
     * between each was reliable every time; (2) selecting an AskUserQuestion
     * option by digit moves the on-screen cursor but doesn't confirm it - a
     * same-instant follow-up Enter can still be processed against the *old*
     * cursor position, confirming the wrong option, unless there's a real
     * gap first. Used by set_mode() (between BTab presses) and answer_prompt()
     * (between the digit and the Enter that confirms it).
     */
    public const TMUX_KEY_STEP_DELAY_USEC = 300000;

    /**
     * Answers a session's pending interactive prompt by sending the chosen
     * option's number followed by Enter - exactly what a human attached over
     * tmux would type. Re-validates immediately before sending, against a
     * fresh capture-pane, that the session is still live and that $option is
     * still actually one of the options currently on screen - not just "some
     * session with this name exists" - so a stale page (the prompt was
     * already answered, the session was killed, or a *different* prompt is
     * now showing) can't fire a keystroke at nobody. Never called
     * automatically anywhere in this app - only in direct response to a
     * human tapping a button that showed them this exact option's label.
     *
     * @return array{ok:bool, message:string}
     */
    public static function answer_prompt(string $name, int $option, ?string $expectedLabel = null): array
    {
        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        // Antigravity's own numbered-option prompt shape (see
        // AntigravityPromptParser's own docblock) already returns the same
        // canonical {question, context, options, multi_question,
        // is_folder_trust} shape Claude Code's own parser does, and its
        // multi_question is always false - so everything below (the
        // separate-Enter path, PendingToolStore/SessionStatusStore cleanup)
        // already applies correctly to it unchanged, no further branching
        // needed past which parser reads the pane.
        $agent = SidecarStore::read_sidecar($name)['agent'] ?? 'claude';

        if ($agent === 'opencode') {
            $sidecarOc = SidecarStore::read_sidecar($name);
            $ocSessionId = is_string($sidecarOc['agent_session_id'] ?? null) ? $sidecarOc['agent_session_id'] : null;

            // Prefer the serve API (authoritative, orphan-safe: GET /question
            // returns the live request or [] when nothing/orphaned). Fall back
            // to the pane if the serve is unreachable.
            $ocPending = $ocSessionId !== null ? OpenCodeQuestionService::pending_question($ocSessionId) : null;

            if ($ocPending !== null) {
                $prompt = OpenCodeQuestionService::to_prompt($ocPending);
            } else {
                // Fallback: pane parse for opencode (TUI does show the question when blocked)
                $prompt = OpenCodePromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

                if ($prompt === null || ($prompt['tool_name'] ?? null) === 'permission') {
                    return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
                }
            }
        } else {
            $prompt = $agent === 'antigravity'
                ? AntigravityPromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name))
                : PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

            if ($prompt === null) {
                return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
            }
        }

        if ($agent === 'claude' && $expectedLabel !== null && $expectedLabel !== '' && ($prompt['question'] ?? null) === 'Do you want to proceed?') {
            $expectedIntent = PromptParser::classify_permission_option_intent($expectedLabel);

            foreach ($prompt['options'] as $realOpt) {
                if (PromptParser::classify_permission_option_intent((string)$realOpt['label']) === $expectedIntent) {
                    $option = (int)$realOpt['number'];
                    break;
                }
            }
        }

        if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
            return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
        }

        // Claude Code's own permission-suggestion menu is the one prompt
        // shape where the browser's option NUMBERS (built by
        // PromptParser::build_options_from_permission_suggestions() from
        // the PermissionRequest hook payload - see build_session_entry())
        // and the real on-screen menu (validated above only by number
        // against $prompt['options'], a completely separate pane-scrape
        // via PromptParser::parse_blocking_prompt()) come from two
        // independent guesses at the same thing. Every other prompt shape
        // in this method (OpenCode question/permission, AskUserQuestion,
        // folder trust) sources its options from a SINGLE place for both
        // display and send, so there's nothing to reconcile there.
        //
        // Found live 2026-09-02 (Andres): clicked a button labeled "Allow
        // always" for a Bash tool's addRules suggestion; the guessed menu
        // put that at position 2, but Claude Code's real on-screen menu for
        // that combo didn't have a third option at all (iOS/the TUI itself
        // sometimes offers a plain Allow/Deny for this exact shape) - so
        // sending digit 2 landed on the pane's real "No" and the tool use
        // came back denied. Fix: don't trust the guessed NUMBER at all here
        // - resolve the user's actual INTENT (once/always/no) from the
        // guessed label at $option, then find whichever number the FRESH
        // pane-scrape really has that same intent at (self-healing if the
        // real menu just reordered), and send that number instead. Only
        // refuse if the real menu doesn't offer that intent at all - e.g.
        // "always" was picked but the real menu has no always-allow option
        // this time - rather than silently downgrading to a different
        // intent (once, say) the user never asked for.
        if ($agent === 'claude') {
            $hookStatusForCheck = SessionStatusStore::read_status($name);
            $hookBlockedForCheck = is_array($hookStatusForCheck['blocked'] ?? null) ? $hookStatusForCheck['blocked'] : null;
            $hookToolName = is_string($hookBlockedForCheck['tool_name'] ?? null) ? $hookBlockedForCheck['tool_name'] : null;

            // Gated on the HOOK's own tool_name, not $prompt['tool_name'] -
            // the plain pane-scrape parse_blocking_prompt() call above never
            // sets that key at all (only build_prompt_from_hook_status()
            // and the AskUserQuestion pending-tool augmentation do), so
            // checking $prompt['tool_name'] here would silently never fire.
            //
            // Also requires the REAL pane to currently be asking Claude
            // Code's own fixed "Do you want to proceed?" question - the
            // literal text build_prompt_from_hook_status() always uses for
            // this exact prompt shape (see its own docblock). Without this,
            // a stale SessionStatusStore 'blocked' row left over from an
            // EARLIER answered-but-not-yet-hook-cleared permission prompt
            // would wrongly apply this whole cross-check to whatever
            // DIFFERENT prompt (a multi-question AskUserQuestion tab, a
            // folder-trust dialog) is actually on screen right now - caught
            // by this method's own test suite, not just theorized: a later,
            // unrelated multi-question answer_prompt() call broke once this
            // guard was missing.
            if (
                ($hookStatusForCheck['status'] ?? null) === 'blocked'
                && $hookBlockedForCheck !== null
                && $hookToolName !== null
                && $hookToolName !== 'AskUserQuestion'
                && ($prompt['question'] ?? null) === 'Do you want to proceed?'
            ) {
                if ($expectedLabel === null || $expectedLabel === '') {
                    $expectedOptions = PromptParser::build_options_from_permission_suggestions(
                        is_array($hookBlockedForCheck['permission_suggestions'] ?? null) ? $hookBlockedForCheck['permission_suggestions'] : []
                    );
                    $expectedLabel = $expectedOptions[$option - 1]['label'] ?? null;
                }
                $realLabel = $prompt['options'][$option - 1]['label'] ?? null;

                if ($expectedLabel !== null && $realLabel !== null) {
                    $wantedIntent = PromptParser::classify_permission_option_intent($expectedLabel);
                    $realIntentAtSameNumber = PromptParser::classify_permission_option_intent($realLabel);

                    if ($wantedIntent !== $realIntentAtSameNumber) {
                        $correctedOption = null;

                        foreach ($prompt['options'] as $realOpt) {
                            if (PromptParser::classify_permission_option_intent((string)$realOpt['label']) === $wantedIntent) {
                                $correctedOption = (int)$realOpt['number'];
                                break;
                            }
                        }

                        if ($correctedOption === null) {
                            TuiLayoutMismatchService::record($name, $hookToolName, $expectedLabel, $realLabel);

                            return [
                                'ok' => false,
                                'message' => "Refusing: this page showed \"{$expectedLabel}\" as option {$option}, but the real on-screen menu doesn't currently offer an equivalent choice at all (it shows \"{$realLabel}\" there instead) - Claude Code's prompt layout may have changed. Nothing was sent. Reload the page, or answer by hand: `tmux attach -t {$name}`.",
                            ];
                        }

                        $option = $correctedOption;
                    }
                }
            }
        }

        // OpenCode QUESTION prompt - answer via the serve API (POST
        // /question/{requestID}/reply with the chosen label). The pane modal is
        // orphan-prone and its nav shape (↑↓ list vs ⇆ tab) varies, so mapping
        // the chosen option number to its label and answering over HTTP is the
        // reliable path - mirrors how opencode/web answers.
        if (($prompt['tool_name'] ?? null) === 'question') {
            $sidecarQ = SidecarStore::read_sidecar($name);
            $ocQid = is_string($sidecarQ['agent_session_id'] ?? null) ? $sidecarQ['agent_session_id'] : null;

            if ($ocQid === null) {
                return ['ok' => false, 'message' => 'Rejected: no OpenCode session id for this question'];
            }

            $chosenLabel = $prompt['options'][$option - 1]['label'] ?? '';

            if ($chosenLabel === '') {
                return ['ok' => false, 'message' => 'Rejected: could not resolve the selected option'];
            }

            $answerResult = OpenCodeQuestionService::answer($ocQid, [$chosenLabel]);

            if (!($answerResult['ok'] ?? false)) {
                return ['ok' => false, 'message' => (string)($answerResult['message'] ?? 'Failed to answer question')];
            }

            PendingToolStore::delete_pending_tool($name);
            SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);

            return ['ok' => true, 'message' => "Answered '{$chosenLabel}' for {$name}"];
        }

        // OpenCode permission prompt: answer it in the pane via the tab bar
        // (Left/Right to move the highlight, Enter to confirm). The permission
        // dialog is owned by the `opencode serve` process's in-memory state
        // (see PermissionStore's docblock - no queryable state, and the plugin
        // permission.ask HOOK is dormant in opencode 1.18.21, so an intent-
        // based answer can't be auto-applied). The pane reliably renders the
        // dialog and accepts arrow+Enter, so that's the answer path here. The
        // option numbers mirror tab order (1=Allow once, 2=Allow always,
        // 3=Reject / Confirm, Cancel).
        if (($prompt['tool_name'] ?? null) === 'permission') {
            for ($i = 1; $i < $option; $i++) {
                usleep(self::TMUX_KEY_STEP_DELAY_USEC);
                $arrow = TmuxService::tmux_run(['send-keys', '-t', $name, 'Right']);

                if ($arrow['exit'] !== 0) {
                    return ['ok' => false, 'message' => "Failed to select the {$prompt['options'][$option - 1]['label']} option: " . trim($arrow['stderr'])];
                }
            }

            usleep(self::TMUX_KEY_STEP_DELAY_USEC);
            $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

            if ($enterResult['exit'] !== 0) {
                return ['ok' => false, 'message' => "Failed to confirm: " . trim($enterResult['stderr'])];
            }

            PendingToolStore::delete_pending_tool($name);
            SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);

            return ['ok' => true, 'message' => "Confirmed '{$prompt['options'][$option - 1]['label']}' for {$name}"];
        }

        // The initial per-folder trust dialog: answered via Up/Down + Enter,
        // same reasoning as the OpenCode permission block above - found live
        // 2026-09-11 (Andres: couldn't start a session in a new folder) that
        // Claude Code v2.1.269 dropped this dialog's option numbers entirely
        // ("❯ No, exit" / "  Yes, I trust this folder" - see PromptParser's
        // own docblock), so typing the digit does nothing at all here; the
        // Enter that used to just confirm the typed digit's selection now
        // fires alone, confirming whatever's DEFAULT-highlighted instead -
        // which is "No, exit", so a "confirm" click was silently killing the
        // whole session (the tmux pane's own process exits) rather than
        // trusting the folder. Re-captures the pane fresh (not the $prompt
        // already parsed above) purely to find which option the ❯ cursor is
        // CURRENTLY on - $prompt['options'] itself no longer carries that,
        // since every label had its own leading ❯ stripped when building it.
        if (!empty($prompt['is_folder_trust'])) {
            $currentIndex = null;

            foreach (explode("\n", TmuxService::tmux_capture_pane($name)) as $line) {
                if (preg_match('/^\s*❯\s*(.+?)\s*$/u', $line, $m) !== 1) {
                    continue;
                }

                foreach ($prompt['options'] as $idx => $opt) {
                    if ($opt['label'] === $m[1]) {
                        $currentIndex = $idx;
                        break 2;
                    }
                }
            }

            if ($currentIndex === null) {
                return ['ok' => false, 'message' => 'Rejected: could not tell which option the trust dialog currently has selected'];
            }

            $targetIndex = $option - 1;
            $steps = $targetIndex - $currentIndex;
            $direction = $steps >= 0 ? 'Down' : 'Up';

            for ($i = 0; $i < abs($steps); $i++) {
                usleep(self::TMUX_KEY_STEP_DELAY_USEC);
                $arrow = TmuxService::tmux_run(['send-keys', '-t', $name, $direction]);

                if ($arrow['exit'] !== 0) {
                    return ['ok' => false, 'message' => "Failed to select the {$prompt['options'][$targetIndex]['label']} option: " . trim($arrow['stderr'])];
                }
            }

            usleep(self::TMUX_KEY_STEP_DELAY_USEC);
            $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

            if ($enterResult['exit'] !== 0) {
                return ['ok' => false, 'message' => "Failed to confirm: " . trim($enterResult['stderr'])];
            }

            PendingToolStore::delete_pending_tool($name);
            SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);

            return ['ok' => true, 'message' => "Confirmed '{$prompt['options'][$targetIndex]['label']}' for {$name}"];
        }

        $digitResult = TmuxService::tmux_run(['send-keys', '-t', $name, (string)$option]);

        if ($digitResult['exit'] !== 0) {
            return ['ok' => false, 'message' => "Failed to send response: " . trim($digitResult['stderr'])];
        }

        // A single-question prompt needs a separate Enter - verified live
        // that the digit there only moves the on-screen cursor, it doesn't
        // auto-confirm, so an Enter sent in the same instant can race ahead
        // and confirm whatever was *previously* highlighted instead.
        //
        // A multi-question AskUserQuestion tab is the opposite: verified
        // live 2026-08-09 (Andres reported answering a question "skipped"
        // the next one) against a real, disposable test session - the
        // digit there ALREADY selects, confirms, AND auto-advances to the
        // next tab (or submits, on the final Submit/Cancel tab) entirely on
        // its own. Sending an Enter afterward regardless (this app's old
        // behavior) lands on whatever's now showing NEXT and confirms
        // ITS currently-highlighted default option too - silently
        // answering the following question with its default instead of
        // whatever the human actually meant to pick for it, then
        // advancing an extra tab on top. Skipping the trailing Enter here
        // is what actually matches this shape's real interaction model.
        if (empty($prompt['multi_question'])) {
            usleep(self::TMUX_KEY_STEP_DELAY_USEC);

            $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

            if ($enterResult['exit'] !== 0) {
                return ['ok' => false, 'message' => "Failed to send response: " . trim($enterResult['stderr'])];
            }

            // Same staleness problem as PendingToolStore below, for the new
            // hook-fed status file (see SessionStatusStore): nothing else
            // clears its blocked state until the next Stop hook fires, which
            // could be a while into whatever this answer just kicked off.
            // Only safe to do here for a single-shot prompt that's now fully
            // resolved - a multi-question AskUserQuestion tab set may still
            // have more questions waiting after this one, and re-derives its
            // own still-blocked state from the pane on the next poll anyway
            // (see build_session_entry() - AskUserQuestion never reads this
            // file's `blocked` content in the first place).
            SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);
        }

        // The pending-tool file (see PendingToolStore::read_pending_tool()) only ever describes
        // whatever's currently blocking - once this app itself has just
        // submitted the answer, it's guaranteed stale for any future prompt.
        PendingToolStore::delete_pending_tool($name);

        return ['ok' => true, 'message' => "Sent option {$option} to {$name}"];
    }

    /**
     * Answers a prompt's free-text option (Claude Code's AskUserQuestion
     * always offers one labeled "Type something.") with custom typed text,
     * instead of just the bare numbered choice. Verified live: selecting
     * that option by digit (without Enter) turns it into an inline text
     * field right there in the option list - typing replaces its label live,
     * and Enter submits whatever was typed. Declining to type anything
     * before pressing Enter is treated as skipping the question entirely,
     * which is why $text is required here and rejected empty, unlike
     * answer_prompt()'s plain numbered choice.
     *
     * @return array{ok:bool, message:string}
     */
    public static function answer_prompt_with_text(string $name, int $option, string $text): array
    {
        if (trim($text) === '') {
            return ['ok' => false, 'message' => 'Reply cannot be empty'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

        if ($prompt === null) {
            return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a prompt'];
        }

        if (!in_array($option, array_column($prompt['options'], 'number'), true)) {
            return ['ok' => false, 'message' => 'Rejected: that option is not currently offered by this prompt'];
        }

        $digitResult = TmuxService::tmux_run(['send-keys', '-t', $name, (string)$option]);

        if ($digitResult['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to select the free-text option: ' . trim($digitResult['stderr'])];
        }

        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        // A uniquely-named buffer, not tmux's shared default one (a bare
        // set-buffer/paste-buffer with no -b) - found live 2026-08-14: this
        // host-agent handles every request as its OWN separate OS process
        // (systemd socket activation), all sharing the SAME tmux server, so
        // two genuinely concurrent replies (two devices/tabs, or this and
        // send_message() racing) landing their set-buffer calls back to
        // back before either's paste-buffer runs would silently paste
        // WHICHEVER text set-buffer wrote last into BOTH panes - reproduced
        // live against two real fixture panes. -d deletes the named buffer
        // the instant paste-buffer consumes it, so nothing here needs its
        // own separate cleanup step on the success path.
        $bufferName = 'sessioneer-' . bin2hex(random_bytes(8));
        $set = TmuxService::tmux_run(['set-buffer', '-b', $bufferName, '--', $text]);

        if ($set['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to stage reply: ' . trim($set['stderr'])];
        }

        $paste = TmuxService::tmux_run(['paste-buffer', '-d', '-b', $bufferName, '-t', $name]);

        if ($paste['exit'] !== 0) {
            TmuxService::tmux_run(['delete-buffer', '-b', $bufferName]); // best-effort - paste-buffer's own -d never ran, so the named buffer would otherwise leak
            return ['ok' => false, 'message' => 'Failed to send reply: ' . trim($paste['stderr'])];
        }

        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        $enterResult = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

        if ($enterResult['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Reply sent but failed to submit: ' . trim($enterResult['stderr'])];
        }

        PendingToolStore::delete_pending_tool($name);
        SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);

        return ['ok' => true, 'message' => "Sent free-text reply to {$name}"];
    }

    /**
     * Answers every question of a multi-question AskUserQuestion prompt in
     * one shot, collected all at once in the app from the hook-fed
     * SessionStatusStore data (see PromptParser::build_multi_question_key_sequence()'s
     * own docblock for the confirmed tab-bar mechanics this drives) rather
     * than one tab at a time (the OLD design - SessionService::
     * navigate_prompt(), Left/Right arrow-key navigation - removed
     * 2026-08-22, once this method made it unreachable: a multi-question
     * prompt now always renders this form instead) - the
     * decoupled design Andres asked for 2026-08-22, so the app never has to
     * show "only whichever tab the pane currently has up".
     *
     * $questions is deliberately NOT read from the request - always
     * re-derived here from SessionStatusStore, the same "never trust
     * anything from the caller for a state-changing action" discipline
     * SessionLifecycleService::kill_agent_session()/answer_prompt() already
     * follow. $answers is the only thing the caller actually supplies (their
     * own picks); PromptParser::build_multi_question_key_sequence() still
     * validates every one of them against the real $questions before
     * anything is sent.
     *
     * One live pane check up front, not a per-keystroke one like
     * answer_prompt()'s re-validation: this method is meant to run the
     * WHOLE sequence in a single atomic action immediately after the app
     * first showed the question form, so the only thing worth guarding
     * against is the prompt having already moved on somehow (answered
     * elsewhere - attached directly over tmux, a second browser tab) between
     * then and now, not re-confirming every individual tab transition this
     * same call is about to cause itself.
     *
     * @param array<int, mixed> $answers see PromptParser::build_multi_question_key_sequence()'s own docblock
     * @return array{ok:bool, message:string}
     */
    public static function answer_multi_question(string $name, array $answers): array
    {
        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $hookStatus = SessionStatusStore::read_status($name);
        $hookBlocked = is_array($hookStatus['blocked'] ?? null) ? $hookStatus['blocked'] : null;
        $questions = is_array($hookBlocked['tool_input']['questions'] ?? null) ? $hookBlocked['tool_input']['questions'] : null;

        if (
            ($hookStatus['status'] ?? null) !== 'blocked'
            || ($hookBlocked['tool_name'] ?? null) !== 'AskUserQuestion'
            || $questions === null
            || count($questions) < 2
        ) {
            return ['ok' => false, 'message' => 'Rejected: this session is not currently showing a multi-question prompt'];
        }

        $firstQuestionText = is_string($questions[0]['question'] ?? null) ? $questions[0]['question'] : null;
        $paneScraped = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($name));

        if ($paneScraped === null || empty($paneScraped['multi_question']) || $paneScraped['question'] !== $firstQuestionText) {
            return ['ok' => false, 'message' => 'Rejected: this prompt has already moved on (answered elsewhere, or no longer showing the first question)'];
        }

        $sequence = PromptParser::build_multi_question_key_sequence($questions, $answers);

        if ($sequence === null) {
            return ['ok' => false, 'message' => 'Rejected: answers do not match this prompt\'s questions'];
        }

        foreach ($sequence as $step) {
            $result = match ($step['type']) {
                'digit' => TmuxService::tmux_run(['send-keys', '-t', $name, $step['value']]),
                'right' => TmuxService::tmux_run(['send-keys', '-t', $name, 'Right']),
                'text' => TmuxService::tmux_run(['send-keys', '-t', $name, '-l', $step['value']]),
                'enter' => TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']),
            };

            if ($result['exit'] !== 0) {
                return ['ok' => false, 'message' => 'Failed partway through sending answers: ' . trim($result['stderr'])];
            }

            usleep(self::TMUX_KEY_STEP_DELAY_USEC);
        }

        SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);
        PendingToolStore::delete_pending_tool($name);

        return ['ok' => true, 'message' => "Sent all answers to {$name}"];
    }

    /**
     * Interrupts whatever Claude is currently doing (mid-generation or
     * mid-tool-call), same as pressing Escape while attached - the "stop"
     * button. No pane-content check first (unlike set_mode(), which
     * validates against a specific expected state): Escape
     * is a safe no-op if nothing is actually running, so there's nothing to
     * reject up front beyond "is this a real managed session at all".
     *
     * Found live 2026-08-30 (Andres: "a session interrupted mid-turn still
     * shows working/thinking in Sessioneer even though the real Claude Code app
     * confirms it's no longer thinking"): Escape is a true interrupt (not a
     * natural turn completion), so the Stop hook - which fires ONLY on
     * natural completion (https://code.claude.com/docs/en/hooks, confirmed
     * live) - never fires. SessionStatusStore's cached `status`/`working`
     * fields are ONLY refreshed by the Stop/UserPromptSubmit/PermissionRequest
     * hooks - without the update_status() call at the end here (matching
     * every sibling action method in this file - set_mode()/set_model()/
     * answer_prompt()/send_message() all call it after their own tmux
     * mutation), a session interrupted mid-turn has no mechanism to ever
     * clear its stale `working: true` status. This is the same bug class
     * already fixed for set_mode() (see that method's own docblock,
     * 2026-08-23).
     */
    public static function send_escape(string $name): array
    {
        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $result = TmuxService::tmux_run(['send-keys', '-t', $name, 'Escape']);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to send Escape: ' . trim($result['stderr'])];
        }

        SessionStatusStore::update_status($name, ['status' => 'idle', 'blocked' => null]);

        return ['ok' => true, 'message' => "Sent Escape to {$name}"];
    }

    /**
     * Found live 2026-08-23 (Andres: "changing modes is broken in session
     * pages"): the Shift+Tab keystrokes below genuinely DO change the live
     * pane's mode, but session_detail()'s own current_mode comes
     * exclusively from SessionStatusStore's cached `mode` field (refreshed
     * only by the Stop/UserPromptSubmit/PermissionRequest hooks, none of
     * which fire from a bare mode switch) - without the update_status()
     * call at the end here (matching every sibling action method in this
     * file - send_message()/answer_prompt()/answer_multi_question() all
     * call it after their own tmux mutation), the next poll's stale cached
     * value snapped the dropdown right back, making a real change look
     * like it silently did nothing.
     */
    public static function set_mode(string $name, string $targetMode): array
    {
        if (!array_key_exists($targetMode, PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES)) {
            return ['ok' => false, 'message' => 'Rejected: not a recognized mode'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $currentMode = PermissionMode::parse_current_mode(TmuxService::tmux_capture_pane($name));

        if ($currentMode === null) {
            return ['ok' => false, 'message' => 'Rejected: current mode is not readable right now (a prompt may be covering the status line)'];
        }

        $modes = array_keys(PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES);
        $steps = (array_search($targetMode, $modes, true) - array_search($currentMode, $modes, true) + count($modes)) % count($modes);

        for ($i = 0; $i < $steps; $i++) {
            if ($i > 0) {
                usleep(self::TMUX_KEY_STEP_DELAY_USEC);
            }

            $result = TmuxService::tmux_run(['send-keys', '-t', $name, 'BTab']);

            if ($result['exit'] !== 0) {
                return ['ok' => false, 'message' => 'Failed to set mode: ' . trim($result['stderr'])];
            }
        }

        SessionStatusStore::update_status($name, ['mode' => $targetMode]);

        return ['ok' => true, 'message' => "Set mode for {$name} to {$targetMode}"];
    }

    /**
     * Switches a live session's model for the CURRENT session only - never
     * saved as the account's new default. Andres's own explicit ask,
     * 2026-08-24: typing `/model <name>` directly saves it as the default
     * for every FUTURE session too (confirmed live and in the docs -
     * https://code.claude.com/docs/en/model-config#setting-your-model),
     * which isn't what a per-session dropdown should do. Session-only
     * switching only exists through the real interactive picker's own 's'
     * key (confirmed live 2026-08-24 against a real running session, same
     * discipline PromptParser's own key-sequence docblocks already use),
     * so this drives that picker instead of typing the target name
     * directly: '/model' + Enter opens it with the cursor on whichever row
     * happens to be currently selected, Up (count(SelectableModel::
     * PICKER_OPTIONS)) times reliably lands on row 1 regardless of where
     * that was (verified live: the cursor never wraps past the first row),
     * then Down (target row - 1) times reaches the target row
     * deterministically - no need to read the CURRENT model first the way
     * set_mode()'s relative Shift+Tab cycling does. Finally 's' confirms
     * "this session only" (verified live: shows "Set model to <name> for
     * this session only", not the "set as your default" wording Enter
     * would show instead).
     *
     * Rejects while the session is blocked on a prompt, same reasoning as
     * set_mode()'s own "current mode not readable" guard - the pane isn't
     * showing its normal input line to type '/model' into.
     *
     * @return array{ok:bool, message:string}
     */
    public static function set_model(string $name, string $targetModel): array
    {
        if (!array_key_exists($targetModel, SelectableModel::PICKER_OPTIONS)) {
            return ['ok' => false, 'message' => 'Rejected: not a recognized model'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        if ((SessionStatusStore::read_status($name)['status'] ?? null) === 'blocked') {
            return ['ok' => false, 'message' => 'Rejected: this session is currently waiting on a prompt'];
        }

        $rows = array_keys(SelectableModel::PICKER_OPTIONS);
        $targetRow = array_search($targetModel, $rows, true) + 1;

        $sequence = [['type' => 'text', 'value' => '/model'], ['type' => 'enter']];

        for ($i = 0; $i < count($rows); $i++) {
            $sequence[] = ['type' => 'up'];
        }

        for ($i = 0; $i < $targetRow - 1; $i++) {
            $sequence[] = ['type' => 'down'];
        }

        $sequence[] = ['type' => 'text', 'value' => 's'];

        foreach ($sequence as $i => $step) {
            if ($i > 0) {
                usleep(self::TMUX_KEY_STEP_DELAY_USEC);
            }

            $result = match ($step['type']) {
                'text' => TmuxService::tmux_run(['send-keys', '-t', $name, '-l', $step['value']]),
                'enter' => TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']),
                'up' => TmuxService::tmux_run(['send-keys', '-t', $name, 'Up']),
                'down' => TmuxService::tmux_run(['send-keys', '-t', $name, 'Down']),
            };

            if ($result['exit'] !== 0) {
                return ['ok' => false, 'message' => 'Failed to set model: ' . trim($result['stderr'])];
            }
        }

        return ['ok' => true, 'message' => "Set model for {$name} to {$targetModel} (this session only)"];
    }

    /**
     * Antigravity equivalent of set_model() above, driving the real
     * `/model` picker: '/model' + Enter opens it, then Up/Down presses walk
     * the cursor to the target row, then Enter confirms it.
     *
     * UNLIKE set_model() above, there is no final "session only" key to
     * send - Antigravity's picker has no such option. Confirmed live: this
     * always overwrites the ACCOUNT-WIDE default model, applying to every
     * future `agy` session (see AntigravitySelectableModel's own docblock
     * for the disposable-session test that proved this). Andres's own
     * explicit decision 2026-08-24, after being shown this finding, was to
     * ship it anyway rather than wait for a session-scoped mechanism that
     * doesn't exist - callers must label this as a global default switch,
     * not a per-session one.
     *
     * Found live 2026-08-24 (Andres: "when I change the model on an agy
     * session, it immediately reverts to the old one"): an EARLIER version
     * of this method fired a fixed-count Up-then-Down key sequence blind
     * (same shape as set_mode()'s own Shift+Tab cycling), trusting that
     * count(PICKER_OPTIONS) Up presses always lands on row 1 regardless of
     * starting position. Reproduced directly: from row 2 (Gemini 3.6 Flash
     * current), 7 Up presses 300ms apart landed on ROW 2 STILL - zero
     * movement - and the very next single Up press then moved it. Antigravity's
     * own TUI silently drops arrow keys sent in a rapid burst to this
     * specific picker screen (a different, apparently WORSE debounce than
     * Claude Code's own picker/BTab cycling, where a fixed count has always
     * been reliable - see set_model()'s own docblock) - a fixed count key
     * sequence is therefore NOT safe here, unlike set_mode()/set_model().
     * Fixed by verifying the actual cursor position against the live pane
     * after each press and only stopping once it's confirmed on the right
     * row - see move_antigravity_picker_cursor() below.
     *
     * Rejects while the session is busy (status 'working') rather than
     * 'blocked' the way set_mode()/set_model() do - Antigravity has no
     * blocked-prompt detection built yet (see docs/antigravity-adapter-plan.md
     * Phase 6, still open), so 'blocked' never actually occurs for an
     * antigravity-agent session; a slash command typed while genuinely busy
     * gets silently QUEUED behind other input instead of failing (confirmed
     * live), which would desync this method's own key sequence from
     * whatever screen is actually showing when it's finally processed.
     *
     * @return array{ok:bool, message:string}
     */
    public static function set_antigravity_model(string $name, string $targetModel): array
    {
        if (!array_key_exists($targetModel, AntigravitySelectableModel::PICKER_OPTIONS)) {
            return ['ok' => false, 'message' => 'Rejected: not a recognized model'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $sidecar = SidecarStore::read_sidecar($name);

        if (($sidecar['agent'] ?? 'claude') !== 'antigravity') {
            return ['ok' => false, 'message' => 'Rejected: not an Antigravity session'];
        }

        if ((SessionStatusStore::read_status($name)['status'] ?? null) === 'working') {
            return ['ok' => false, 'message' => 'Rejected: this session is currently busy'];
        }

        $targetLabel = AntigravitySelectableModel::PICKER_OPTIONS[$targetModel];

        TmuxService::tmux_run(['send-keys', '-t', $name, '-l', '/model']);
        usleep(self::TMUX_KEY_STEP_DELAY_USEC);
        TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);
        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        // Walk toward row 1 first (Up never wraps past it - still true, only
        // the "one fixed-count burst" assumption was wrong), THEN toward the
        // actual target - two monotonic passes, never needing to know which
        // row the cursor started on relative to the target.
        if (!self::move_antigravity_picker_cursor($name, AntigravitySelectableModel::PICKER_OPTIONS[array_key_first(AntigravitySelectableModel::PICKER_OPTIONS)], 'Up', count(AntigravitySelectableModel::PICKER_OPTIONS))) {
            return ['ok' => false, 'message' => 'Failed to set model: could not confirm the picker cursor reached the top row'];
        }

        if (!self::move_antigravity_picker_cursor($name, $targetLabel, 'Down', count(AntigravitySelectableModel::PICKER_OPTIONS))) {
            return ['ok' => false, 'message' => 'Failed to set model: could not confirm the picker cursor reached the target row'];
        }

        TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

        return ['ok' => true, 'message' => "Set default model to {$targetModel} (applies to all future Antigravity sessions)"];
    }

    /**
     * Presses $direction ('Up' or 'Down') against the /model picker up to
     * $maxPresses times, re-capturing the live pane after EACH press (not
     * just at the end) and stopping the moment it shows the cursor
     * ("> ") directly in front of $targetLabel - see set_antigravity_model()'s
     * own docblock for why a blind fixed-count burst isn't safe here
     * (Antigravity's TUI silently drops some fraction of rapid arrow-key
     * presses to this specific screen). $maxPresses is deliberately the
     * full row count, not just the theoretical minimum distance - if presses
     * ARE being dropped, more attempts than the theoretical minimum may be
     * needed to actually cover that distance.
     */
    private static function move_antigravity_picker_cursor(string $name, string $targetLabel, string $direction, int $maxPresses): bool
    {
        for ($attempt = 0; $attempt < $maxPresses; $attempt++) {
            if (str_contains(TmuxService::tmux_capture_pane($name), "> {$targetLabel}")) {
                return true;
            }

            TmuxService::tmux_run(['send-keys', '-t', $name, $direction]);
            usleep(self::TMUX_KEY_STEP_DELAY_USEC);
        }

        return str_contains(TmuxService::tmux_capture_pane($name), "> {$targetLabel}");
    }

    /**
     * Sends a free-text message to a session, exactly as if a human had
     * typed it while attached, then pressed Enter to submit - the actual,
     * intended point of this whole app (remote-controlling a session, same
     * as attaching from the iOS app). Uses a tmux paste-buffer, not
     * send-keys with the raw text as a "key": send-keys treats embedded
     * newlines in a multi-line message as individual Enter keypresses, each
     * prematurely submitting whatever's been typed so far, where a real
     * terminal paste delivers the whole block as one unit (verified live)
     * and only the explicit trailing Enter submits it.
     *
     * $attachmentPaths (compose-bar file uploads still pending when Send is
     * pressed) each become their own "[Attached: <path>]" line appended
     * after $text - added here, not client-side, so the user's own draft
     * never shows that bookkeeping text while they're still typing (see
     * session.js's compose-attachments preview, which shows the files as
     * their own removable chips instead). $text may be empty as long as at
     * least one attachment is present - an attachment-only send is valid.
     *
     * @param string[] $attachmentPaths
     * @return array{ok:bool, message:string}
     */
    public static function send_message(string $name, string $text, array $attachmentPaths = []): array
    {
        $attachmentLines = array_map(static fn(string $path): string => '[Attached: ' . $path . ']', $attachmentPaths);
        $text = $attachmentLines === [] ? $text : trim(rtrim($text) . "\n" . implode("\n", $attachmentLines));

        if (trim($text) === '') {
            return ['ok' => false, 'message' => 'Message cannot be empty'];
        }

        if (!in_array($name, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        // See answer_prompt_with_text()'s own comment on this same pattern -
        // a uniquely-named buffer, not tmux's shared default one, since
        // every request here is its own OS process and two genuinely
        // concurrent sends can otherwise clobber each other's staged text
        // before either's paste-buffer runs.
        $bufferName = 'sessioneer-' . bin2hex(random_bytes(8));
        $set = TmuxService::tmux_run(['set-buffer', '-b', $bufferName, '--', $text]);

        if ($set['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to stage message: ' . trim($set['stderr'])];
        }

        $paste = TmuxService::tmux_run(['paste-buffer', '-d', '-b', $bufferName, '-t', $name]);

        if ($paste['exit'] !== 0) {
            TmuxService::tmux_run(['delete-buffer', '-b', $bufferName]);
            return ['ok' => false, 'message' => 'Failed to send message: ' . trim($paste['stderr'])];
        }

        // Found live 2026-08-20 (Andres: a sent message sometimes sat stuck
        // showing "Sending…" client-side, with no thinking indicator ever
        // appearing - the message never actually got submitted, just typed
        // into the pane) - this was missing the same TMUX_KEY_STEP_DELAY_USEC
        // gap answer_prompt_with_text() already has between ITS own
        // paste-buffer and confirming Enter (see that constant's own doc
        // comment), for the identical reason: an Enter sent with no gap
        // right after a paste can be processed before the pane has actually
        // registered the pasted text, submitting nothing.
        usleep(self::TMUX_KEY_STEP_DELAY_USEC);

        $enter = TmuxService::tmux_run(['send-keys', '-t', $name, 'Enter']);

        if ($enter['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Message sent but failed to submit: ' . trim($enter['stderr'])];
        }

        return ['ok' => true, 'message' => "Sent message to {$name}"];
    }
}
