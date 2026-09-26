# Pane-scraping fragility: inventory + mitigation

Prompted by a real bug (2026-08-29): Claude Code's `AskUserQuestion` tab UI
started rendering the current tab's question with a leading `│ ` quote-marker
(`│ How do you want to handle adminer, redis-ui, and git...`). Nothing in
this app's own code changed - Claude Code's CLI rendering did - and it broke
`PromptInteractionService::answer_multi_question()`'s live-pane pre-flight
check (`$paneScraped['question'] === $firstQuestionText`), rejecting a
perfectly live, unanswered prompt as "already moved on". Fixed in
`PromptParser::parse_blocking_prompt()` by stripping a leading box-drawing
glyph from each context line, but the class of bug - this app's own code
silently breaking because an *upstream CLI's terminal rendering changed*,
with zero signal until a human hits it live - is worth tracking as its own
standing risk, not just this one instance.

This app's whole reason to exist (see CLAUDE.md's architecture section) is
driving/observing real interactive CLI sessions it doesn't control the
rendering of. Some of that is unavoidable. This doc is the inventory of
where it happens, how brittle each spot is, and what's actually worth doing
about it.

## Inventory

The Claude Code rows below apply to **tmux-runtime** sessions only. A headless Claude session (see `docs/features.md`, "Headless runtime") has no pane and none of these sites is on its path: its state, prompts and session ids come from its process's own structured events, so a Claude Code rendering change cannot break it. Choosing the headless runtime is the structural mitigation for the Claude sites; the sites stay in the code for the tmux runtime and for hand-started sessions.

Every place that parses text out of a live `tmux capture-pane` (as opposed
to a documented, versioned hook/API payload this app doesn't have to guess
the shape of).

| Site | Reads for | Match style | Breaks if... |
|---|---|---|---|
| `PromptParser::parse_blocking_prompt()` | Claude Code: blocked-prompt question/context/options, multi-question tab-bar detection, folder-trust detection | Structural (❯ cursor line, "●" tool marker, blank-line-terminated option list) + now one leading-glyph strip | Claude Code changes cursor glyph, marker conventions, list terminator, or (this bug) adds new decorative framing to a line Sessioneer treats as content |
| `PromptInteractionService::answer_multi_question()`'s pane pre-flight (line ~347) | Claude Code: confirming a multi-question prompt hasn't moved on before sending the whole keystroke sequence | **Exact string equality** between hook-fed `questions[0]['question']` and freshly pane-scraped `question` | The **exact** bug just fixed - any rendering delta at all (not just this one) fails closed |
| `PromptParser::build_multi_question_key_sequence()` | Claude Code: the confirmed digit/Right/text/Enter sequence to drive the tab bar | Not pane-derived at all (drives blind from hook `questions[]`), but the *sequence itself* (free-text slot = option-count+1, multiSelect toggles vs. auto-advance, final Review tab = option 1) is empirically reverse-engineered from one live capture, 2026-08-22 | Claude Code changes tab-bar keybindings, free-text slot position, or Review-tab shape |
| `PermissionMode::parse_current_mode()` | Claude Code: current manual/accept-edits/plan/auto mode, for `set_mode()`'s pre-flight and `SessionLifecycleService::create_agent_session()` | Substring match against 4 fixed status-line phrases (`"accept edits on"`, etc.) | Claude Code reworks its bottom status-line phrasing (already inconsistent once - "accept edits on" has no "mode" - so it's clearly not a stable contract on Anthropic's end either) |
| `StatuslineMarkerService::parse_marker_from_pane()` | Claude Code: self-healing `agent_session_id`, context-used %, git worktree | Regex for **this app's own** injected `sessioneer-data:{...}` JSON marker | Low risk - this is Sessioneer's own output format inside the statusline script, not Claude Code's UI. Real risk is narrower: Claude Code changing the statusLine JSON schema it feeds the script, or dropping/renaming `TMUX_PANE_HEIGHT` behavior |
| `AntigravityPromptParser::parse_blocking_prompt()` | Antigravity: the *only* way to detect a blocked prompt at all - no `PermissionRequest`-equivalent hook exists | Scoped narrowly to one confirmed live shape ("Requesting permission for: ... Do you want to proceed?") - returns `null` (safe) for anything else, deliberately not generalized | Any other Antigravity tool-confirmation shape, or the confirmed shape's own wording, changes - and since there's no hook fallback, an unrecognized shape means Sessioneer shows nothing rather than a stale prompt |
| `AntigravitySelectableModel::parse_current_model()` / `move_antigravity_picker_cursor()` | Antigravity: which model is active, driving `/model` picker | Substring match on cursor + label (`"> {$targetLabel}"`) | Antigravity changes its picker's cursor glyph from `> ` or reorders/renames the fixed 7-row `PICKER_OPTIONS` vocabulary (already hardcoded, dated, verified-live) |
| `OpenCodePromptParser` | OpenCode: legacy tmux-TUI fallback path (headless `serve` API is preferred now per CLAUDE.md's own convention note; this is what's left for old tmux-spawned OpenCode sessions) | Two-stage structural (bottom-anchored footer marker, then region above it) - deliberately avoids whole-pane keyword scanning after a real false-positive (a pasted git diff read as a nonsense question) | OpenCode changes its footer hint text (`"enter confirm"` etc.) or modal anchoring. Lower ongoing risk than the Claude Code/Antigravity paths since new work goes through the headless API instead |
| `SessionService::build_session_entry()` (pane read at line ~136, feeds several of the above) | Dispatches pane content to whichever agent's parser applies, plus reads `AntigravitySelectableModel`/`StatuslineMarkerService` directly | N/A - orchestration, not parsing itself | Inherits every risk above; also the one place a NEW agent's pane-scraping would get wired in |

Not in scope here: `CodexTranscriptService`/Codex's app-server JSON-RPC
protocol and OpenCode's v2 `/api/session` REST surface are both real,
versioned APIs, not terminal scraping - they carry the normal "a vendor
changed their API" risk, not this doc's risk.

## Why this category of bug is dangerous specifically

- **Silent until a human hits it.** Nothing in this app's own test suite or
  linting can detect "Claude Code changed its rendering" - the fixture data
  the tests assert against is a snapshot of *some* past live capture, and
  there's no automated channel back to the real CLI's current output. The
  gap here was real: `tests/test_sessions_lifecycle.php`'s
  `answer_multi_question()` happy-path fixture (`$promptTestSession`'s
  canned pane content) simply predates this rendering change, so it never
  exercised the exact string that broke.
- **Fails toward "reject the user's action," not toward silent corruption.**
  Every site above is written to return `null`/reject rather than send wrong
  keystrokes when parsing doesn't match expectations (see
  `AntigravityPromptParser`'s explicit "don't guess" scoping, and this bug's
  own "already moved on" rejection rather than sending a stale answer). That
  containment is good and should stay the design default for any new
  parsing site - but it still means a real feature silently stops working
  with no error surfaced anywhere except the one rejected user action.
- **No CLI version pinning/probing exists for the tmux-runtime Claude Code parsing**, unlike Codex
  (`docs/headless-runtime-plan.md`'s "pinning a tested minimum CLI version
  and probing capabilities at startup") or the dated, "verified live
  YYYY-MM-DD against version X" comments scattered through this file. There
  is no single place that records "this pane-scraping code was last
  verified against Claude Code CLI version N," and no code path that
  detects a version bump and flags stale assumptions. The headless
  runtime does have one: its health-box section compares the installed
  `claude --version` with `ClaudeHeadlessHealthService::TESTED_CLAUDE_VERSION`
  (the version its protocol handling was captured and live-verified on) and
  `bash tests/run.sh --live` re-checks it against the installed CLI.

## Mitigation options (not yet decided/implemented - for discussion)

1. **Loosen exact-equality pane pre-flight checks to structural/substring
   matching**, the same style `PermissionMode::parse_current_mode()` and
   `move_antigravity_picker_cursor()` already use, instead of strict string
   equality. For `answer_multi_question()`'s specific check, something like
   "pane is still multi_question AND still shows the same option COUNT AND
   the pane's question, with known decorative prefixes stripped, matches" -
   already partly true after this fix, but a `str_contains($paneScraped,
   $firstQuestionText)` fallback (tolerant of prefix/suffix noise) would
   survive a wider class of future rendering deltas than exact equality
   ever will, at the cost of very slightly weaker "did this genuinely move
   on" detection. Lowest-effort, addresses the specific failure mode that
   just bit you.
2. **Freshen and expand the pane fixture library** used by
   `tests/test_sessions_lifecycle.php` (and any other file with hardcoded
   canned pane text) so it's an explicit, reviewable "known-good captures"
   set - add this bug's own `│ `-prefixed capture as a permanent regression
   fixture, and periodically (or opportunistically, whenever a real pane
   capture is taken live for debugging, like this session did) diff new
   real captures against the stored fixtures to catch drift before it's
   user-visible. This is process, not code - "when you're live-debugging a
   prompt shape, save the raw capture into the fixture set" - and is the
   most direct fix for "the test suite didn't catch this."
3. **Record a "last verified against Claude Code CLI version N" note** next
   to each parsing site (mirroring the Codex/dated-comment convention
   already used elsewhere in this file), and have the health checkup
   surface the currently-installed `claude --version` alongside those notes
   so a version bump is at least a visible signal to go re-verify, even
   without automated detection of *what* changed.
4. **Do nothing beyond the fix already shipped**, and keep relying on "a
   human hits it live, reports it, gets fixed same-day" - which is
   basically what happened here and is genuinely proportionate for a
   personal single-user tool with no SLA. Worth stating explicitly as the
   baseline these options are weighed against, not just assumed.

None of these need deciding right now - flagging as a real, recurring
risk class worth a deliberate choice, not a to-be-fixed-immediately bug
list.
