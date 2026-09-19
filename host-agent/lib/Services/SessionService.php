<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Agents\AgentRegistry;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionListCacheStore;
use HostAgent\Stores\SessionStatusStore;

/**
 * Session listing (list_all_sessions, build_session_entry) and the shared
 * title cascade - the foundation layer everything else depends on. Split
 * out of what was originally one 1940-line, 43-method class (2026-08-24
 * readability audit - see the plan this followed): sending input to a live
 * session's pane (messages, prompt answers, mode/model switches, escape)
 * now lives in PromptInteractionService, the sidebar's plan/handoff-file
 * glance now lives in PlanFileService, create/resume/kill/cleanup of
 * managed cc-* tmux sessions (app-spawned ones, and adopted ones - see
 * TmuxService::list_tracked_tmux_sessions()) now lives in
 * SessionLifecycleService, archived/dormant session listing plus
 * transcript search now lives in ArchivedSessionService,
 * detail/history/attachment paging for both live and archived sessions
 * now lives in SessionDetailService, and untracked ("bare") claude process
 * discovery/take-over now lives in BareProcessService. This class has zero
 * dependency on any of the six - it's what's left after removing them.
 */
class SessionService
{
    /**
     * The shared fallback cascade behind every session title this app
     * shows, live or dormant: the transcript's own ai-title first (see
     * TranscriptService::find_latest_ai_title()), since it needs no live
     * pane at all and works the same for a dormant session as a live one -
     * the unify-claude-sessions plan's "minimize tmux reliance" goal made
     * concrete. Falls back, in order, to a live-pane-title scrape (only
     * ever non-null for a currently-live session - see session_title()
     * below), the working directory's basename, and finally the raw
     * session name/id - the one source that's always available. Mirrors
     * NotificationContentBuilder::push_notification_title()'s own cascade
     * for the same reason: a title should never come back blank.
     */
    public static function title_cascade(?string $aiTitle, ?string $livePaneTitle, ?string $workdir, string $fallbackName): string
    {
        if ($aiTitle !== null && $aiTitle !== '') {
            return $aiTitle;
        }

        if ($livePaneTitle !== null && $livePaneTitle !== '') {
            return $livePaneTitle;
        }

        if ($workdir !== null && $workdir !== '') {
            return basename($workdir);
        }

        return $fallbackName;
    }

    /**
     * build_session_entry()'s title field: resolves $agentSessionId to its
     * transcript's ai-title (if any), then applies title_cascade() with
     * today's live-pane-title scrape as the next fallback - the one part of
     * the cascade that's only ever available for a currently-live session.
     */
    public static function session_title(?string $agentSessionId, ?string $livePaneTitle, ?string $workdir, string $name, ?string $profile = null): string
    {
        $transcriptPath = $agentSessionId !== null ? TranscriptRouter::find_transcript_path($agentSessionId, $profile) : null;
        // find_latest_ai_title() is Claude-Code-specific (Antigravity and
        // OpenCode have no ai-title-equivalent transcript entry) - harmlessly
        // finds nothing for those paths, falling through the cascade below to
        // livePaneTitle/workdir/name, same as a Claude Code session with no
        // ai-title yet. For OpenCode, the session row's own title
        // (session.title) is the closest equivalent and is read instead.
        $aiTitle = null;

        if ($transcriptPath !== null && !TranscriptRouter::is_antigravity_path($transcriptPath) && !TranscriptRouter::is_opencode_path($transcriptPath)) {
            $aiTitle = TranscriptService::find_latest_ai_title($transcriptPath);
        } elseif ($transcriptPath !== null && TranscriptRouter::is_opencode_path($transcriptPath)) {
            $aiTitle = OpenCodeTranscriptService::find_session_title($agentSessionId);
        }

        return self::title_cascade($aiTitle, $livePaneTitle, $workdir, $name);
    }

    /**
     * Parses the orchestrator-worker skill's session-tagging convention
     * (~/dotfiles/ai/skills/orchestrator-worker/SKILL.md, "Worker Session
     * Tagging") from a session's raw title. A cross-tool worker's prompt is
     * required to start with a literal
     * "[WORKER session=<orchestration-id>/<task-id> parent=<parent-session-id>]"
     * line, which (for tools with no explicit title-setting flag - codex,
     * agy) is the only mechanism available for it to end up in whatever
     * name/preview text that tool records. Best-effort text parsing, not a
     * guaranteed signal - matched leniently (tolerant of the closing `]`
     * being missing, e.g. a truncated preview) rather than requiring an
     * exact match, since a worker is still worth flagging even if only part
     * of the tag survived.
     *
     * @return array{is_worker:bool, clean_title:string, parent_session_id:?string}
     */
    public static function parse_worker_tag(?string $title): array
    {
        $title ??= '';

        if (!preg_match('/^\[WORKER\b/u', $title)) {
            return ['is_worker' => false, 'clean_title' => $title, 'parent_session_id' => null];
        }

        $parentId = null;

        if (preg_match('/\bparent=([^\s\]]+)/u', $title, $m) && $m[1] !== 'unknown') {
            $parentId = $m[1];
        }

        // If the closing "]" never arrived (a truncated preview - codex/agy
        // have no explicit title-setting flag, see the skill's own honesty
        // note on this), there's no real task text left to show either way -
        // preg_match (not preg_replace) means a no-match here correctly
        // yields an empty $rest, falling to the placeholder below, rather
        // than leaking the raw, cut-off tag syntax into the UI.
        $rest = preg_match('/^\[WORKER\b[^\]]*\]\s*(.*)$/su', $title, $m2) ? trim($m2[1]) : '';
        $cleanTitle = $rest !== '' ? $rest : '(worker session)';

        return ['is_worker' => true, 'clean_title' => $cleanTitle, 'parent_session_id' => $parentId];
    }

    /**
     * Builds one tracked session's (cc-* or adopted) list-row/detail data
     * from already-fetched process state - shared by list_all_sessions()
     * (called once per tmux session found) and SessionDetailService::
     * session_detail() (called for exactly one, by name).
     *
     * @param array{name:string, activity:int, attached:bool} $tmuxSession
     * @param array<int, array{pid:int, cwd:?string, started_at:?int}> $claudeProcs
     * @param array<int, int> $ppidMap
     * @return array{name:string, activity:int, attached:bool, pid:?int, workdir:?string, spawned_by_app:bool, kind:string, parent_session_id:?string, agent:string, agent_label:string, title:string, working:bool, blocked_reason:?string, resume_hint:?string, prompt_context:?string, prompt_options:array<int, array{number:int, label:string}>, prompt_multi_question:bool, prompt_is_folder_trust:bool, prompt_tool_name:?string, prompt_tool_input:?array, prompt_questions:?array, current_mode:?string, current_model:?string, current_antigravity_model:?string, last_turn_error:?string, agent_session_id:?string, last_message:?array, profile:?string, context_used_percentage:?float, git_worktree:?string}
     */
    public static function build_session_entry(array $tmuxSession, array $claudeProcs, array $ppidMap): array
    {
        $panes = TmuxService::tmux_session_panes($tmuxSession['name']);
        $matchedPid = null;

        foreach ($claudeProcs as $proc) {
            foreach ($panes['pids'] as $panePid) {
                if (ProcessInspector::is_descendant($proc['pid'], $panePid, $ppidMap)) {
                    $matchedPid = $proc['pid'];
                    break 2;
                }
            }
        }

        $sidecar = SidecarStore::read_sidecar($tmuxSession['name']);
        $agentId = is_string($sidecar['agent'] ?? null) ? $sidecar['agent'] : 'claude';
        // Which Claude Code account this session was spawned under (see
        // Dibs plan #230) - null for every non-Claude agent and every
        // sidecar written before profiles existed, both of which mean
        // "the default account", same fallback Config's own profile
        // helpers already use.
        $profile = is_string($sidecar['profile'] ?? null) ? $sidecar['profile'] : null;

        // Opencode creates no DB row at spawn time, only after the first
        // prompt (reactive binding, like Antigravity's pre_invocation.php
        // — see .ai/QUESTIONS.md Q1.1). Self-heal the sidecar's
        // agent_session_id on the next poll so transcript reads start
        // working once the ses_* row appears. Best-effort, no extra tmux
        // capture needed — just a DB lookup by workdir+spawn time.
        if ($agentId === 'opencode' && empty($sidecar['agent_session_id'] ?? null) && is_string($sidecar['workdir'] ?? null) && $sidecar['workdir'] !== '' && isset($sidecar['spawned_at']) && is_int($sidecar['spawned_at'])) {
            $healedId = OpenCodeTranscriptService::find_session_for_workdir($sidecar['workdir'], $sidecar['spawned_at']);

            if ($healedId !== null && !SessionLifecycleService::agent_session_id_already_live($healedId, $tmuxSession['name'])) {
                SidecarStore::write_sidecar($tmuxSession['name'], [
                    'workdir' => $sidecar['workdir'],
                    'spawned_at' => $sidecar['spawned_at'],
                    'agent_session_id' => $healedId,
                    'spawned_by_app' => $sidecar['spawned_by_app'] ?? true,
                    'agent' => $agentId,
                    'profile' => $sidecar['profile'] ?? null,
                ]);
                $sidecar['agent_session_id'] = $healedId;
            }
        }

        $paneContent = TmuxService::tmux_capture_pane($tmuxSession['name']);
        $hookStatus = SessionStatusStore::read_status($tmuxSession['name']);
        $hookStatusValue = is_string($hookStatus['status'] ?? null) ? $hookStatus['status'] : null;
        $hookBlocked = is_array($hookStatus['blocked'] ?? null) ? $hookStatus['blocked'] : null;
        $hookBlockedToolName = is_string($hookBlocked['tool_name'] ?? null) ? $hookBlocked['tool_name'] : null;

        // OpenCode: check opencode.db for a running `question` tool before
        // falling through to hook/pane paths — capture-pane is blank when
        // idle but does show the question when blocked (verified live
        // 2026-08-25 on ses_fc8124: question tool status=running, pane shows
        // "↑↓ select  enter submit  esc dismiss" with numbered options).
        // This is the opencode equivalent of AskUserQuestion, but via DB
        // polling rather than a SessionStatusStore hook (plugin will
        // eventually provide the hook-fed path, like Claude's
        // PermissionRequest — this DB poll is the interim that makes the
        // blocked card appear without the plugin installed).
        if ($agentId === 'opencode') {
            $agentSessionIdForOc = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : null;

            // Pending PERMISSION. The PANE is the only trustworthy "is a
            // permission actually on screen" signal in opencode 1.18.21: the
            // pane always renders the true dialog (first-stage Allow/Reject or
            // second-stage Confirm/Cancel), whereas PermissionStore's record is
            // fed by permission.asked events that opencode doesn't reliably
            // pair with a permission.replied to clear - so a store record can
            // go STALE (outlive the dialog it described). Therefore a block is
            // surfaced ONLY when the pane shows a permission dialog; PermissionStore
            // is used just to corroborate the "permission" classification (and,
            // when the pane shows the dialog but the store is empty/already-cleared,
            // we still surface it - the pane is the more current source).
            $ocPanePrompt = OpenCodePromptParser::parse_blocking_prompt($paneContent);
            $paneIsPermission = $ocPanePrompt !== null && ($ocPanePrompt['tool_name'] ?? null) === 'permission';

            if ($paneIsPermission) {
                $prompt = $ocPanePrompt;
            } else {
                // QUESTION (opencode). Prefer the serve HTTP API - it's the
                // authoritative, orphan-safe source (GET /question returns a
                // live QuestionRequest, [] when nothing is pending or the modal
                // is orphaned). Fall back to the DB question-tool poll, then the
                // pane - each only if the stronger source found nothing.
                $ocPending = $agentSessionIdForOc !== null
                    ? OpenCodeQuestionService::pending_question($agentSessionIdForOc)
                    : null;

                if ($ocPending !== null) {
                    $prompt = OpenCodeQuestionService::to_prompt($ocPending);
                } elseif ($ocPanePrompt !== null && ($ocPanePrompt['tool_name'] ?? null) === 'question') {
                    // Pane shows a live question dialog - use it (DB poll is the
                    // less-trusted fallback; the pane reflects what's on screen).
                    $prompt = $ocPanePrompt;
                } else {
                    $ocQuestion = $agentSessionIdForOc !== null ? OpenCodeTranscriptService::find_pending_question($agentSessionIdForOc) : null;

                    if ($ocQuestion !== null) {
                        $prompt = [
                            'question' => $ocQuestion['question'] !== '' ? $ocQuestion['question'] : ($ocQuestion['header'] ?? 'Waiting on input'),
                            'context' => $ocQuestion['header'] ?? '',
                            'options' => $ocQuestion['options'],
                            'multi_question' => false,
                            'tool_name' => 'question',
                        ];
                    } elseif ($hookStatusValue === 'blocked') {
                        $prompt = PromptParser::build_prompt_from_hook_status($hookBlocked);
                    } else {
                        $prompt = null;
                    }
                }
            }
        } elseif ($agentId === 'antigravity') {
            $prompt = AntigravityPromptParser::parse_blocking_prompt($paneContent);

            if ($prompt !== null) {
                $prompt = PromptParser::augment_prompt_with_pending_tool($prompt, PendingToolStore::read_pending_tool($tmuxSession['name']));
            }
        } elseif ($hookStatusValue === 'blocked' && $hookBlockedToolName === 'AskUserQuestion') {
            // AskUserQuestion renders as a tab bar Claude Code itself
            // navigates with the Left/Right arrow keys - a single
            // PermissionRequest fire (at the start of the whole multi-
            // question call) can never say which tab is CURRENTLY showing,
            // only the live pane can (see PromptParser::
            // augment_prompt_with_pending_tool()'s own docblock). This is a
            // structural limitation of the prompt shape, not something more
            // hook coverage could ever fix.
            $prompt = PromptParser::parse_blocking_prompt($paneContent);

            if ($prompt !== null) {
                $prompt = PromptParser::augment_prompt_with_pending_tool($prompt, PendingToolStore::read_pending_tool($tmuxSession['name']));
            }
        } elseif ($hookStatusValue === 'blocked') {
            $prompt = PromptParser::build_prompt_from_hook_status($hookBlocked);

            // PermissionRequest tells us the tool details but its suggestions
            // do not reliably describe the menu Claude actually rendered.
            // Keep the hook-fed context, but use the live pane's option list
            // whenever the matching permission prompt is visible.
            $panePrompt = PromptParser::parse_blocking_prompt($paneContent);

            if (
                $prompt !== null
                && $panePrompt !== null
                && !($panePrompt['is_folder_trust'] ?? false)
                && count($panePrompt['options'] ?? []) >= 2
                && PromptParser::classify_permission_option_intent((string)($panePrompt['options'][0]['label'] ?? '')) === 'yes_once'
                && PromptParser::classify_permission_option_intent((string)($panePrompt['options'][count($panePrompt['options']) - 1]['label'] ?? '')) === 'no'
            ) {
                $prompt['options'] = $panePrompt['options'];
            }
        } else {
            // The only prompt shape that can still be showing here is the
            // initial per-folder trust dialog - confirmed live to fire NONE
            // of this app's hooks at all (a separate, pre-hook-system
            // startup safety check). Pane-scraping is scoped to exactly that
            // case now, never trusted as a stand-in for a PermissionRequest
            // that should have fired but didn't (e.g. the hooks aren't
            // installed yet) - see CONTRIBUTING.md.
            $paneScraped = PromptParser::parse_blocking_prompt($paneContent);
            $prompt = ($paneScraped !== null && $paneScraped['is_folder_trust']) ? $paneScraped : null;
        }

        // The full question set for a MULTI-question AskUserQuestion call,
        // straight from the hook payload - lets the frontend render (and
        // answer) every question at once via SessionService::
        // answer_multi_question(), instead of only whichever tab the pane
        // currently has up (see that method's own docblock). Never set for
        // a lone SINGLE-select question - no tab-bar ambiguity exists there,
        // so it keeps using the existing pane-scraped $prompt above via
        // answer_prompt()/answer_prompt_with_text() unchanged. A lone
        // MULTISELECT question IS included here too, though - found live
        // 2026-09-12 that shape gets a tab bar identical in every mechanical
        // respect to a real 2+-question call (see PromptParser::
        // build_multi_question_key_sequence()'s own docblock), so it was
        // wrongly falling through to the plain one-button-submits-
        // immediately options.php rendering, with no way to check several
        // boxes then confirm at all.
        $promptQuestions = null;

        if ($hookStatusValue === 'blocked' && $hookBlockedToolName === 'AskUserQuestion') {
            $rawQuestions = is_array($hookBlocked['tool_input']['questions'] ?? null) ? $hookBlocked['tool_input']['questions'] : null;
            $isLoneSingleSelect = $rawQuestions !== null && count($rawQuestions) === 1 && ($rawQuestions[0]['multiSelect'] ?? false) !== true;
            $promptQuestions = ($rawQuestions !== null && $rawQuestions !== [] && !$isLoneSingleSelect) ? $rawQuestions : null;
        }

        $agentSessionId = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : null;
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;
        $liveMarker = StatuslineMarkerService::parse_marker_from_pane($paneContent);
        $agentSessionId = self::self_heal_agent_session_id($tmuxSession['name'], $sidecar, $agentSessionId, $liveMarker['session_id']);

        // Same "hooks fully own this, no pane-scraping fallback" reasoning
        // as $prompt above - working/current_mode are simply unknown
        // (false/null) for a session with no status file at all yet
        // (hooks not installed, or genuinely hasn't had one fire since
        // spawning).
        $working = $hookStatusValue === 'working';
        $currentMode = is_string($hookStatus['mode'] ?? null) ? $hookStatus['mode'] : null;

        // Read from the transcript, not any live-pane signal - see
        // TranscriptService::find_latest_model()'s own docblock for why
        // that's the only source portable to every user of this app (not
        // just Andres's own personal statusline customization). Never
        // resolves to "default" - see SelectableModel::family_from_raw_model()
        // for why that's inherently undetectable from a raw model ID alone.
        //
        // $hookStatus['model'] (SessionStatusStore's own optimistic
        // override - see its docblock) takes priority when present: right
        // after PromptInteractionService::set_model() switches the picker,
        // the transcript's latest message still carries the OLD model until
        // the NEXT real turn completes, so a transcript-only read here
        // would show session.php's dropdown snapping straight back to the
        // model the user just switched AWAY from - confirmed to be exactly
        // this, the bug reported live 2026-08-30 ("changing the model on
        // the session page doesn't work"). host-agent/hooks/stop.php clears
        // the override once that next turn finishes, so this can never
        // permanently shadow the real transcript-derived value.
        $transcriptPathForModel = $agentSessionId !== null ? TranscriptService::find_transcript_path($agentSessionId, $profile) : null;
        $rawModel = $transcriptPathForModel !== null ? TranscriptService::find_latest_model($transcriptPathForModel) : null;
        $modelOverride = is_string($hookStatus['model'] ?? null) ? $hookStatus['model'] : null;
        $currentModel = $modelOverride ?? ($rawModel !== null ? SelectableModel::family_from_raw_model($rawModel) : null);

        // Antigravity has no transcript-derived model signal (see
        // AntigravitySelectableModel::parse_current_model()'s own
        // docblock) - only ever readable from the live pane's own footer,
        // reusing the $paneContent already captured above.
        $currentAntigravityModel = AntigravitySelectableModel::parse_current_model($paneContent);

        // See host-agent/hooks/antigravity/stop.php's own docblock for why
        // this exists: Antigravity writes NOTHING to its own transcript
        // file for a turn that fails (e.g. quota exhausted) - only the
        // Stop hook's live-pane read ever captures the actual error text,
        // so it's carried here rather than derivable from the transcript
        // the way current_model/current_mode above are.
        $lastTurnError = is_string($hookStatus['last_turn_error'] ?? null) ? $hookStatus['last_turn_error'] : null;

        try {
            $agentLabel = AgentRegistry::get($agentId)->label();
        } catch (\Throwable) {
            $agentLabel = 'Claude Code';
        }

        return [
            'name' => $tmuxSession['name'],
            'activity' => $tmuxSession['activity'],
            'attached' => $tmuxSession['attached'],
            'pid' => $matchedPid,
            'workdir' => $workdir,
            'spawned_by_app' => $sidecar['spawned_by_app'] ?? false,
            // Fixed 'user'/null here, not run through parse_worker_tag() -
            // a tmux (cc-*/oc-*) session is always either something a human
            // started, or something Sessioneer's own UI spawned; no code path today
            // can put the [WORKER ...] tag on one (only the headless
            // opencode/codex auto-adopt sync, see sessioneer_headless_sessions() in
            // Sessions.php, since only bare cross-tool CLI launches carry it).
            'kind' => 'user',
            'parent_session_id' => null,
            'agent' => $agentId,
            'agent_label' => $agentLabel,
            'profile' => $profile,
            'title' => self::session_title($agentSessionId, $panes['title'], $workdir, $tmuxSession['name'], $profile),
            'working' => $working,
            'blocked_reason' => $prompt['question'] ?? null,
            'resume_hint' => $prompt !== null ? TmuxService::tmux_attach_hint($tmuxSession['name']) : null,
            'prompt_context' => $prompt['context'] ?? null,
            'prompt_options' => $prompt['options'] ?? [],
            'prompt_multi_question' => $prompt['multi_question'] ?? false,
            'prompt_is_folder_trust' => $prompt['is_folder_trust'] ?? false,
            'prompt_tool_name' => $prompt['tool_name'] ?? null,
            'prompt_tool_input' => $prompt['tool_input'] ?? null,
            'prompt_questions' => $promptQuestions,
            'current_mode' => $currentMode,
            'current_model' => $currentModel,
            'current_antigravity_model' => $currentAntigravityModel,
            'last_turn_error' => $lastTurnError,
            'agent_session_id' => $agentSessionId,
            'last_message' => self::session_last_message($agentSessionId, $profile),
            // Both sourced from StatuslineMarkerService's live-pane marker,
            // same mechanism as the self-heal cross-check above - null
            // whenever the marker isn't installed yet, the pane hasn't
            // rendered a statusline update since Claude Code had context-
            // window data to report, or the session isn't in a worktree.
            'context_used_percentage' => $liveMarker['context_used_percentage'],
            'git_worktree' => $liveMarker['git_worktree'],
        ];
    }

    /**
     * Cross-checks the sidecar's agent_session_id against
     * StatuslineMarkerService's live-pane signal and self-heals a
     * stale/wrong one - $liveSessionId is whatever build_session_entry()
     * already parsed out of the pane content it captured for prompt
     * parsing, no extra tmux capture-pane call here. Only ever overwrites
     * when (a) a sidecar actually exists (nothing to preserve workdir/
     * spawned_at from otherwise - an untracked/bare session gets no
     * sidecar from this) and (b) the live id resolves to a real transcript
     * file, the same "don't trust an id nothing backs" rule
     * session_start.php's SessionStart hook itself now enforces (see its
     * own docblock, added 2026-08-08) - applied here as a second,
     * independent layer that self-corrects even if a bad write already
     * slipped past the hook, rather than depending on catching the right
     * hook fire in the first place.
     *
     * @param array<string, mixed>|null $sidecar
     */
    public static function self_heal_agent_session_id(string $sessionName, ?array $sidecar, ?string $agentSessionId, ?string $liveId): ?string
    {
        if ($sidecar === null) {
            return $agentSessionId;
        }

        if ($liveId === null || $liveId === $agentSessionId) {
            return $agentSessionId;
        }

        if (TranscriptService::find_transcript_path($liveId, is_string($sidecar['profile'] ?? null) ? $sidecar['profile'] : null) === null) {
            return $agentSessionId;
        }

        SidecarStore::write_sidecar($sessionName, [
            'workdir' => $sidecar['workdir'] ?? null,
            'spawned_at' => $sidecar['spawned_at'] ?? time(),
            'agent_session_id' => $liveId,
            'spawned_by_app' => $sidecar['spawned_by_app'] ?? false,
            'agent' => $sidecar['agent'] ?? 'claude',
            'profile' => $sidecar['profile'] ?? null,
        ]);

        return $liveId;
    }

    /**
     * The single most recent transcript entry - used for the dashboard's
     * per-row preview, and to give a blocked prompt's card the message that
     * led up to it. That's worth doing specifically for the blocked case
     * because the pending tool_use itself usually ISN'T in the transcript
     * yet (Claude Code only writes it once it's approved and actually runs -
     * see prompt_context in PromptParser::parse_blocking_prompt() for the live-pane
     * alternative), but the assistant's own preceding explanation almost
     * always already is, written as its own separate line just before.
     *
     * @return array{role:?string, timestamp:?string, blocks:array<int, array{kind:string, text:string}>}|null
     */
    public static function session_last_message(?string $agentSessionId, ?string $profile = null): ?array
    {
        if ($agentSessionId === null) {
            return null;
        }

        $path = TranscriptRouter::find_transcript_path($agentSessionId, $profile);

        if ($path === null) {
            return null;
        }

        $page = TranscriptRouter::read_transcript_page($path, null, 1);

        if (!($page['ok'] ?? false) || empty($page['entries'])) {
            return null;
        }

        return $page['entries'][0];
    }

    /**
     * Coalescing wrapper only - see SessionListCacheStore for why. Callers
     * that must see post-mutation state (kill/send/answer-prompt) validate
     * against TmuxService::list_tracked_tmux_sessions() directly rather
     * than going through here, so they're unaffected by the cache.
     *
     * @return array{sessions: array<int, array>, bare: array<int, array>}
     */
    public static function list_all_sessions(): array
    {
        $cached = SessionListCacheStore::read();

        if ($cached !== null) {
            return $cached;
        }

        $result = self::list_all_sessions_uncached();
        SessionListCacheStore::write($result);

        return $result;
    }

    /**
     * @return array{sessions: array<int, array>, bare: array<int, array>}
     */
    private static function list_all_sessions_uncached(): array
    {
        $tmuxSessions = TmuxService::list_tracked_tmux_sessions();
        $claudeProcs = ProcessInspector::find_claude_processes();
        $ppidMap = ProcessInspector::build_ppid_map();

        // Must include every real tmux session on the box, not just cc-*
        // ones - an adopted (non-cc-*) sidecar would otherwise get pruned as
        // an "orphan" on the very next dashboard load, undoing session_start
        // hook's work within moments. all_tmux_panes() already enumerates
        // every session/pane regardless of name, so reuse the one call below
        // rather than issuing a second tmux query.
        $allPanes = TmuxService::all_tmux_panes($tmuxListingOk);
        $liveSessionNames = array_values(array_unique(array_column($allPanes, 'session')));

        // Skip the prune entirely when the tmux query itself failed - an empty
        // listing then means "could not ask", not "nothing is running", and
        // pruning on it deletes every tracked session's row. A genuinely empty
        // box still prunes, which is the case that needs to keep working.
        if ($tmuxListingOk) {
            SidecarStore::prune_orphaned_sidecars($liveSessionNames);
        }

        $trackedPids = [];
        $sessions = [];

        foreach ($tmuxSessions as $session) {
            $entry = self::build_session_entry($session, $claudeProcs, $ppidMap);

            if ($entry['pid'] !== null) {
                $trackedPids[$entry['pid']] = true;
            }

            $sessions[] = $entry;
        }

        usort($sessions, fn(array $a, array $b) => $b['activity'] <=> $a['activity']);

        $bare = [];

        foreach ($claudeProcs as $proc) {
            if (isset($trackedPids[$proc['pid']])) {
                continue;
            }

            $owningPane = ProcessInspector::find_owning_pane($proc['pid'], $allPanes, $ppidMap);

            $bare[] = $proc + [
                'tmux_session' => $owningPane['session'] ?? null,
                'title' => $owningPane['title'] ?? null,
            ];
        }

        usort($bare, fn(array $a, array $b) => ($b['started_at'] ?? 0) <=> ($a['started_at'] ?? 0));

        return ['sessions' => $sessions, 'bare' => $bare];
    }

    /**
     * Lists the immediate subdirectories of $path (hidden ones included), for
     * the New Session folder browser - lets a session start anywhere under the
     * home directory, not just under Config::www_root(). $path (after resolving symlinks)
     * must be Config::home_root() itself or a descendant of it; anything else is
     * rejected rather than letting the browser wander into the rest of the
     * filesystem. An empty $path defaults to Config::www_root(), the common case,
     * rather than Config::home_root() itself - the browser can still walk up to
     * Config::home_root() from there via the returned `parent`.
     *
     * @return array{ok:bool, path?:string, parent?:?string, dirs?:string[], message?:string}
     */
    public static function browse_dir(string $path): array
    {
        $root = Config::home_root();
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return ['ok' => false, 'message' => 'Home directory is not configured correctly on the host'];
        }

        $real = realpath($path !== '' ? $path : Config::www_root());

        if ($real === false || !is_dir($real) || ($real !== $realRoot && !str_starts_with($real . '/', $realRoot . '/'))) {
            return ['ok' => false, 'message' => 'Path is outside the home directory'];
        }

        $dirs = [];

        foreach (scandir($real) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($real . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }

        sort($dirs, SORT_STRING | SORT_FLAG_CASE);

        return [
            'ok' => true,
            'path' => $real,
            'parent' => $real === $realRoot ? null : dirname($real),
            'dirs' => $dirs,
        ];
    }

    /**
     * Creates a new subdirectory named $name inside $parentPath, for the
     * "New folder" button on the same New Session folder browser browse_dir()
     * serves - $parentPath goes through the identical home_root() boundary
     * check as browse_dir() (same reasoning: this must never be able to
     * reach outside the home directory), and $name is restricted to a bare
     * basename (no `/`, no `.`/`..`) so it can't escape $parentPath either.
     * On success, returns browse_dir() of the newly created folder itself -
     * same response shape browse_dir() already returns, so the caller can
     * feed it straight back into whatever renders a browse_dir() result
     * rather than needing a second shape to handle.
     *
     * @return array{ok:bool, path?:string, parent?:?string, dirs?:string[], message?:string}
     */
    public static function create_dir(string $parentPath, string $name): array
    {
        $root = Config::home_root();
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return ['ok' => false, 'message' => 'Home directory is not configured correctly on the host'];
        }

        $realParent = realpath($parentPath);

        if ($realParent === false || !is_dir($realParent) || ($realParent !== $realRoot && !str_starts_with($realParent . '/', $realRoot . '/'))) {
            return ['ok' => false, 'message' => 'Path is outside the home directory'];
        }

        $name = trim($name);

        if ($name === '' || $name !== basename($name) || $name === '.' || $name === '..') {
            return ['ok' => false, 'message' => 'Invalid folder name'];
        }

        $target = $realParent . '/' . $name;

        if (file_exists($target)) {
            return ['ok' => false, 'message' => 'A file or folder with that name already exists'];
        }

        if (!mkdir($target, 0755)) {
            return ['ok' => false, 'message' => 'Could not create the folder'];
        }

        return self::browse_dir($target);
    }
}
