# Antigravity CLI support — implementation plan

> **Historical design record:** this file preserves the research and phased
> implementation history. Its phase labels are not the current user-facing
> support status. See [`../README.md`](../README.md#antigravity) for required
> setup and [`features.md`](features.md#antigravity-implementation) for the
> current capability/caveat reference.

Status: **in progress**, started 2026-08-24. Supersedes the "long-term,
explicitly not near-term" sequencing note in `todo` — Andres asked to start
this directly, ahead of the Sessioneer-own-plugin-hooks item it was previously
queued behind.

## Status at a glance (read this first before resuming - Claude or `agy`)

**Done, committed, verified live:**
- Phase 0 (groundwork - `agent` column, `Config::antigravity_bin()`)
- Phase 1 (`AgentAdapter` interface + `ClaudeCodeAdapter`)
- Phase 2 (`AntigravityAdapter` spawn + identity + New Session UI picker)
- Phase 3 (hooks - working/idle tracking, reactive session-id binding)
- Phase 4 (transcript rendering - text/tool_use/tool_result, no View-layer
  changes needed)
- Phase 7 (quota polling - `agy -p "/usage"`, opt-in systemd timer, into
  `GlobalStateStore`)
- **Phase 5, partial** (2026-08-24, see commit `b17c056`): agent name shown
  above each assistant reply (`TranscriptView`/`session.js`/
  `archived-session.js`), and real per-agent quota display - a per-session
  quota footer AND a per-agent comparison table on the dashboard
  (`QuotaService::antigravity_quota_state()`/`get_quota()`'s new
  per-agent + dashboard routing, `quota-footer.js`'s
  `renderDashboardTable()`). **This half of Phase 5 was built by Andres's
  own live `agy` session** (a real, separate coding session against this
  same repo, not Claude) - it hit Antigravity's account-wide quota limit
  (165h reset) mid-task before it could commit, so Claude reviewed the
  full diff, fixed 2 real issues (an over-strict return type on
  `antigravity_quota_state()` that didn't match its own intentional data
  shape; a missing `SessioneerBootstrap.agent`/`agentLabel` type declaration -
  confirmed the ONLY genuinely new tsc error via a clean-stash diff), and
  committed it. Verified live in a real browser (dashboard quota table +
  session-page agent label, real data, zero console errors) before
  committing.

- **Live in-session model switch** (2026-08-24): a "Select model" dropdown
  on session.php for an antigravity session, driving the real `/model`
  picker (`AntigravitySelectableModel`, `PromptInteractionService::
  set_antigravity_model()`/`move_antigravity_picker_cursor()`,
  `render_antigravity_model_toggle_html()`). UNLIKE Claude Code's own
  model dropdown, this is NOT session-scoped - confirmed live (two
  disposable throwaway sessions, never Andres's real one) that
  Antigravity's picker has no session-only option at all, so this always
  overwrites the ACCOUNT-WIDE default model for every future `agy`
  session. Andres's own explicit decision after being shown this finding:
  ship it anyway, labeled honestly as a global-default switch. Also found
  and fixed live: an earlier version sent a fixed-count blind Up/Down key
  sequence (mirroring how Claude Code's own set_mode()/set_model() work)
  - reproduced that Antigravity's real picker silently drops some
  fraction of rapid keypresses, so a fixed count under/over-shoots
  unpredictably ("changes the model, then immediately reverts to the old
  one"). Fixed by verifying the actual cursor position against the live
  pane after every single press instead of trusting a count. Covered by
  `tests/test_antigravity_model_switch.php` against a fake deterministic
  picker fixture.
- **Turn-error surfacing** (2026-08-24): confirmed live (grepped a real
  transcript_full.jsonl three times in a row) that a turn which fails
  outright (e.g. "Individual quota reached") writes NOTHING to
  Antigravity's own transcript file - no PLANNER_RESPONSE, no error
  entry, nothing. Without any fix, that turn looks from the transcript
  alone exactly like the question was silently ignored. Fixed by having
  `stop.php` detect "no response after the most recent USER_INPUT" from
  the transcript tail, then capture the live pane's own "⚠ ..." banner
  text (with a bounded retry - the SAME kind of hook-fires-before-the-
  pane-finishes-rendering race the model-switch fix above ran into) into
  a new `last_turn_error` column (`SessionStatusStore`/`SqliteDb`), shown
  as its own card in session.php in the same spot the thinking indicator
  occupies (`render_turn_error_html()`). Covered by
  `tests/test_antigravity_hooks.php`.
- **Phase 6 (blocked-prompt detection + real interactive answering) -
  done for the one prompt shape confirmed live so far**: a tool-permission
  request (`Requesting permission for: <command>` / `Do you want to
  proceed?` / 4 numbered options). Antigravity has no PermissionRequest-
  equivalent hook at all (confirmed in Phase 3's own research), so
  detection is unconditional live-pane parsing for every antigravity
  session, every poll - `AntigravityPromptParser::parse_blocking_prompt()`,
  wired into `SessionService::build_session_entry()` (antigravity branches
  BEFORE the hook-status checks, not after) and `PromptInteractionService::
  answer_prompt()` (routes to this parser instead of Claude's by session
  agent). Returns the SAME canonical shape Claude Code's own
  `PromptParser::parse_blocking_prompt()` does, so `BlockedPromptView`/
  session.js's rendering and the whole answer-button flow needed ZERO
  changes - this is the same UI Claude Code's own blocked prompts already
  use. Found and fixed live: a long option label (the command name is
  embedded in it) wraps across two printed lines - the parser now joins a
  non-numbered continuation line onto the option being built instead of
  treating it as the end of the list. Verified end to end against a real
  reproduction (a real `agy models`/`echo` permission prompt, approved
  both via a direct PromptInteractionService call AND via a real browser
  screenshot of session.php's rendered Approve/Deny buttons, zero console
  errors). Covered by `tests/test_antigravity_prompt_parser.php` against a
  real captured pane fixture. Updated 2026-09-11 for agy 1.2.1+: Antigravity
  switched from a single fixed "Do you want to proceed?" with 4 options to
  action-specific questions ("Run this command?", "Allow creation of this file?",
  "Accept this file edit?", "Allow access to this URL?", etc.) with 2 options
  ("Yes, run command" / "No, cancel"). AntigravityPromptParser now dynamically
  matches recognized approval questions, parses option lists of any length, and
  returns the actual question text.
  **Scope**: tool-permission dialogs (both 1.1.x and 1.2.x+); the initial per-folder
  trust dialog and any AskUserQuestion-equivalent Antigravity might have are
  NOT covered (never seen live yet, so nothing to build against without guessing).
- **"regular text messages from agy are not showing" - CONFIRMED FIXED,
  verified live 2026-08-24** (was previously flagged "status unverified"
  in this doc): checked a real session's `session_history()` output after
  a real successful multi-paragraph reply with a tool call - rendered
  correctly, Phase 4's transcript pipeline already covered this. The
  earlier report was actually the turn-error gap above (a FAILED turn
  looks like a missing message) plus the Phase 6 gap above (a session
  stuck on an unanswerable blocked prompt also looks like "no reply") -
  both now fixed.

**Not done yet:**
- Phase 5's OTHER half - permission-MODE display (agent-aware mode
  control on the dashboard/session page, not just agent-name/quota) is
  still unbuilt.
- Phase 6's remaining prompt shapes: the initial per-folder trust dialog,
  and anything AskUserQuestion-equivalent Antigravity might have (not yet
  seen live).
- One more queued-but-unprocessed ask from Andres to his own `agy`
  session, not yet acted on by anyone: "read the global ~/.claude/ setup
  to try to match it, in particular global memory and requirements, and
  maybe skills or something analogous" - i.e. give Antigravity sessions
  something like this app's own CLAUDE.md-awareness.

## Coordination note (2026-08-24, Andres's own instruction)

Andres runs a live `agy` session against this SAME repo/branch
independently of Claude sessions, sometimes on overlapping work (this
Phase 5 partial is a real example - both are aware of it, but built it
"blind" to the other's parallel progress until Claude reviewed &
committed it after `agy` hit its quota wall). He's confirmed he won't run
Claude and `agy` on this repo AT THE SAME TIME going forward, but expects
this doc to stay accurate about exactly what's committed vs. not, so
whichever picks it up next doesn't duplicate or fight the other's work.
**Whoever resumes this**: re-read "Status at a glance" above, run
`git log --oneline -10` and `git status` to confirm the repo matches what
this doc claims, THEN start work - don't trust this doc blindly if the
repo state looks different.

Goal: get a second agent (Google's Antigravity CLI, binary `agy`) working
through this same tmux/web-UI/SQLite pipeline, via a real `AgentAdapter`
abstraction with Claude Code as its first implementation — not bolted on top
of the current Claude-Code-only code. MVP means: spawn, list, see status,
read the transcript. Non-required features (statusline/quota, real
interactive permission-prompt answering) can stay broken/deferred, per
Andres's explicit tolerance for that at kickoff.

## Research findings (live-verified 2026-08-24)

Both confirmed against a real `agy 1.1.19` installation already
authenticated on this machine, not just the public docs (which are close to
accurate but incomplete/occasionally wrong on file locations).

### Transcript format — good news, no protobuf needed

Every hook payload carries `transcriptPath`, which points at:

```
<conversationDir>/.system_generated/logs/transcript_full.jsonl
```

(`conversationDir` = `~/.gemini/antigravity-cli/brain/<conversationId>/`,
confirmed via `artifactDirectoryPath`). This is plain, readable JSONL, one
object per line:

```json
{"step_index":0,"source":"USER_EXPLICIT","type":"USER_INPUT","status":"DONE","created_at":"2026-08-24T20:18:50Z","content":"<USER_REQUEST>\n...\n</USER_REQUEST>..."}
{"step_index":1,"source":"SYSTEM","type":"CHECKPOINT","status":"DONE","created_at":"...","content":"{{ CHECKPOINT 0 }}\n...context-truncation summary..."}
{"step_index":2,"source":"MODEL","type":"PLANNER_RESPONSE","status":"DONE","created_at":"...","thinking":"...optional reasoning trace...","tool_calls":[{"name":"run_command","args":{"CommandLine":"...","Cwd":"..."}}],"content":"..."}
```

Observed `source`/`type` pairs so far: `USER_EXPLICIT`/`USER_INPUT`,
`SYSTEM`/`CHECKPOINT` (a context-truncation summary marker — no Claude Code
equivalent, needs its own render treatment or a skip), `MODEL`/
`PLANNER_RESPONSE` (optionally carrying `thinking` and/or `tool_calls`).
**Not yet observed**: a tool-RESULT-shaped entry (every live tool call this
research made got denied before executing — see below). Confirming that
shape is the first thing to do once Phase 3 can actually run a tool.

There is *also* a per-conversation SQLite database
(`~/.gemini/antigravity-cli/conversations/<id>.db`, tables `steps` /
`gen_metadata` / etc., content in protobuf-encoded `blob` columns — the
binary embeds full `genai.*` proto schema strings, e.g. `TextContent`,
`ToolCallContent`, `ToolResultContent`). This is irrelevant to us — it looks
like an internal fast-access cache/index sitting alongside the real JSONL
log, not something we need to touch. **Do not build a protobuf parser** —
the plain JSONL file already has everything.

### Hooks — real config format (differs from a literal reading of the docs)

Global config file: `~/.gemini/config/hooks.json` (confirmed via the
binary's own embedded changelog: `/hooks` command used to write to the wrong
path, `~/.gemini/antigravity-cli/hooks.json`, and was fixed to write here
instead). Per-workspace override also exists: `<workspace>/.agents/hooks.json`
— not needed for MVP (global is enough to start).

Real schema (confirmed by extracting the CLI's own embedded doc text, then
verified live):

```json
{
  "<hook-name>": {
    "enabled": true,
    "PreToolUse": [
      { "matcher": ".*", "hooks": [ { "type": "command", "command": "..." } ] }
    ],
    "PostToolUse": [
      { "matcher": ".*", "hooks": [ { "type": "command", "command": "..." } ] }
    ],
    "PreInvocation": [ { "type": "command", "command": "..." } ],
    "PostInvocation": [ { "type": "command", "command": "..." } ],
    "Stop": [ { "type": "command", "command": "..." } ]
  }
}
```

`PreToolUse`/`PostToolUse` are **grouped** (need a `matcher` regex against
the tool name + a `hooks` array); `PreInvocation`/`PostInvocation`/`Stop`
are **flat** (a plain list of `{type, command}` handler objects, no
matcher). This is genuinely close to Claude Code's own
`hooks[event][] = {matcher, hooks:[{type,command}]}` shape — just with an
extra named-hook-group wrapper key, and the three non-tool events skipping
the matcher layer entirely.

Common fields on every hook's stdin payload (confirmed live):
`conversationId`, `workspacePaths`, `transcriptPath`, `artifactDirectoryPath`,
`modelName`.

Per-event fields (confirmed live except `PostToolUse`, which never fired
during this research — see open question #1):

| Event | Extra input fields (confirmed) | Expected stdout |
|---|---|---|
| `PreToolUse` | `toolCall: {name, args}`, `stepIdx` | `{"decision": "allow"\|"deny"\|"ask"\|"force_ask", "reason"?, "permissionOverrides"?, "overwrite"?}` |
| `PostToolUse` | `stepIdx`, `error?` (documented, not live-confirmed) | `{}` |
| `PreInvocation` | `invocationNum`, `initialNumSteps` | `{"injectSteps"?: [...]}` |
| `PostInvocation` | `invocationNum`, `initialNumSteps` | `{"injectSteps"?, "terminationBehavior"?: "force_continue"\|"terminate"}` |
| `Stop` | `executionNum`, `terminationReason`, `error?`, `fullyIdle` | `{"decision": "continue"\|anything-else, "reason"?}` |

**Architecturally different from Claude Code**: `PreToolUse`'s `decision`
field actually *gates* whether the tool runs — Claude Code's hooks are
observe-only by design (never approve/deny anything; the real approval UI
is separate, driven by pane-scraping). Antigravity's hook contract folds
the two together. Good news for later (permission answering can route
through the hook's own response instead of reinventing pane-scraping), but
it means the hook script itself has to actually decide something, which is
new territory for this codebase.

**Docs also say**: hooks run synchronously and block the agent loop; only
`type: "command"` is supported (no HTTP/prompt hooks yet).

### CLI flags relevant to spawning/resuming

From `agy --help` (binary v1.1.19):

- Interactive TUI: plain `agy` (optionally `--agent`, `--model`, `--effort`,
  `--mode accept-edits|plan`, `--project`, `--new-project`, `--add-dir`).
  **No `--session-id`/`--conversation-id` equivalent for starting a NEW
  interactive session** — unlike `claude --session-id <uuid>`, there is no
  way to pre-assign a conversation id at spawn time. `--conversation <id>`
  only *resumes* an existing one; `-c`/`--continue` resumes the most recent.
- Headless/print mode: `-p`/`--print`, `--output-format text|json|stream-json`,
  `--input-format text|stream-json`, `--json-schema`, `--print-timeout`
  (default 5m). Each invocation's JSON envelope includes `conversation_id`.
- `--dangerously-skip-permissions`, `--sandbox` also exist.

**Consequence for Sessioneer**: session identity can't be bound at spawn time the
way `SessionLifecycleService::create_agent_session()` does today (generate a
UUID, pass `--session-id`, done). It has to be bound *reactively*, off the
`conversationId` in whichever hook fires first after spawn (almost
certainly `PreInvocation`, since that's the earliest hook after a prompt is
submitted) — closer to how `session_start.php` already rebinds a sidecar on
`/clear`/`/compact`/`--resume` for Claude Code, just needed on *every*
session's first turn instead of only on a rotation.

### Free tier / models

Confirmed real (not a trial): Free tier includes Gemini 3.x models, plus
Claude Sonnet 4.6/Opus 4.6/GPT-OSS-120b on Free+Pro. Model selection via
`--model`.

### Quota (`/usage`, aliased `/quota`) - live-verified 2026-08-24

Not in the transcript or any hook payload. Is a real slash command,
confirmed genuinely free (`duration_seconds:0`, all-zero token usage,
empty `conversation_id`, writes nothing to the transcript/brain
directory) and fully structured via headless mode:

```
agy -p "/usage" --output-format json
```

```json
{"command":{"name":"usage","data":{"groups":[
  {"name":"Gemini Models","buckets":[{"id":"gemini-weekly","window":"weekly","remaining_fraction":0.955,"reset_time":"2026-08-31T20:07:27Z"}]},
  {"name":"Claude and GPT models","buckets":[{"id":"3p-weekly","window":"weekly","remaining_fraction":1,"reset_time":"2026-08-31T21:21:33Z"}]}
]}}}
```

`remaining_fraction` (0-1, how much is LEFT) is the opposite orientation
from Claude Code's own `used_percentage` convention - converted at write
time (see Phase 7) so nothing downstream has to handle both.

## Open questions — RESOLVED 2026-08-24, live, against a real interactive
tmux-attached `agy` session (not headless mode - see below for why that
distinction mattered)

1. **Does `PostToolUse` fire on a denied/approved tool call?** Yes, confirmed
   live for an APPROVED call - fires with `toolCall` echoed back plus
   `error: ""` on success (a real failure presumably populates `error`,
   not directly observed). Denial specifically wasn't tested (see finding
   below on why "denial" isn't really the right frame for MVP).
2. ~~Conversation-id binding timing~~ — resolved earlier: no pre-assignment
   possible, bind reactively off the first hook firing. Confirmed live:
   `PreInvocation` fires with a real `conversationId` before any tool call,
   exactly as planned.

**Major finding, changes Phase 3/6's scope**: a `PreToolUse` hook returning
`{"decision":"allow"}` does **NOT** suppress Antigravity's own interactive
approval UI. Live test: hooks.json configured with `PreToolUse` always
returning `allow`, a real interactive `agy` session asked to run `echo
sessioneer-live-test-marker` - `PreToolUse` fired (confirmed via the logged
payload) and returned `allow`, and the pane **still** showed a real
"Do you want to proceed?" prompt with 4 numbered options (`1. Yes`, `2. Yes,
and always allow in this conversation for commands that start with 'echo'`,
`3. ...(Persist to settings.json)`, `4. No`), blocking until answered by
hand. `PostToolUse` only fired after that manual approval.

Unlike Claude Code (where `PermissionRequest` is a SEPARATE hook that only
fires when a decision is genuinely needed - a pre-allowlisted command gets
`PreToolUse` but no `PermissionRequest` at all), Antigravity has no such
second hook. `PreToolUse` fires for every tool call regardless of whether
it ends up needing approval, and its `decision` field does not appear to
override the interactive confirmation UI (at least not as tested here -
`agy 1.1.19`, default settings). **Consequence**: telling "genuinely
blocked, waiting on a human" apart from "running normally" for an
Antigravity session cannot be done from hooks alone - it needs pane-text
detection (parsing the literal "Do you want to proceed?" prompt + its
numbered options), the same category of work `PromptParser` already does
for Claude Code's `AskUserQuestion`/trust-dialog cases. This is real,
not-yet-built work, folded into Phase 6 below (renamed to cover detection,
not just answering) rather than Phase 3 - Phase 3 ships working/idle
tracking correctly; "blocked" status for Antigravity sessions stays
unimplemented (always reports not-blocked) until Phase 6.

**Bonus finding for Phase 4**: confirmed the tool-result entry shape the
original research never observed - `{"type":"GENERIC","source":"MODEL",
"content":"Created At: ...\nCompleted At: ...\n\nThe command exited with
code 0.\nOutput:\n<stdout>"}`, a single formatted plain-text block, not
structured JSON. Sits between the `PLANNER_RESPONSE` that issued
`tool_calls` and whatever `PLANNER_RESPONSE` comes next. A `PLANNER_RESPONSE`
that only carries `tool_calls` (no text yet) has `content: null`.

## Adjustted plan vs. the original chat draft

- Confirmed the `cc-` tmux-session-name prefix is Sessioneer's own convention
  (used in exactly 2 places, `SessionLifecycleService.php:83,258`), not
  Claude Code's, and session *tracking* is sidecar-existence-based, not a
  `cc-*` glob (`SessionService.php`'s own comment: "must include every real
  tmux session on the box, not just cc-* ones" — bare/adopted sessions
  already work this way). Low risk either way; going with a distinct `ag-`
  prefix for Antigravity-spawned sessions, reusing the existing adoption
  path rather than inventing a new one.
- `HookService::app_hooks_status()` is already a clean, data-driven
  `list<{event, command, present}>` design — the Claude/Antigravity split
  mostly just needs a different *settings-file shape* (flat top-level
  `hooks[event]` vs. a named/grouped `hooks.json`), not a different
  algorithm. Confirmed while reading the real code before starting.
- Headless-mode testing turned out to be a poor proxy for the
  permission-prompt question — noted above, deferred to Phase 3 with the
  right (interactive) test setup instead of chasing it further in headless
  mode.

## Phases

**Phase 0 — groundwork — DONE**
- Added an `agent` column to the `sidecars` table (`SqliteDb::sessions_schema()`),
  plus `SqliteDb::add_column_if_missing()` so an existing (tmpfs, but not
  guaranteed-fresh-since-last-reboot) `sessions.sqlite` gets it retroactively
  rather than breaking every write until the next reboot. Every real
  `write_sidecar()` call site now passes `agent` explicitly (either the
  resolved adapter's id, or `$existingSidecar['agent'] ?? 'claude'` when
  preserving across a partial rebind - same established pattern already
  used for `workdir`/`spawned_at`).
- Added `Config::antigravity_bin()` (`ANTIGRAVITY_BIN` env var), mirroring
  `claude_bin()` exactly. **Deviation from the original plan text above**:
  skipped the generalized `Config::agent_bin(string $agent)` dispatcher -
  each adapter already owns calling its own binary getter directly
  (`ClaudeCodeAdapter` → `Config::claude_bin()`, `AntigravityAdapter` →
  `Config::antigravity_bin()`), so a `Config`-level dispatcher would've
  been a redundant extra indirection layer with nothing left to decide.

**Phase 1 — `AgentAdapter` interface, Claude-only refactor — DONE (see the
earlier commit this plan doc already described)**

**Phase 2 — Antigravity adapter: spawn + identity — DONE**
- `AntigravityAdapter::build_spawn_argv()` for the interactive TUI -
  `--model`/`--effort`/`--mode` when given, `assigned_id` always null (see
  the resolved open question above). `check_hooks()`/`install_hooks()` are
  deliberate honest stubs (`installed: false`, refuses) rather than fake
  success - Phase 3's job, not this one's.
- `SessionLifecycleService::create_agent_session()` gained a 4th `?string
  $agentId = null` parameter, whitelisted against `AgentRegistry::
  known_agent_ids()` (unrecognized/omitted falls back to Claude Code,
  byte-identical to every pre-Phase-2 caller). Wired through `Sessions.php`'s
  `dispatch_action()` case `'create'` (`request['agent']`) and
  `DashboardController::handleAction()`'s `case 'new'` (`$_POST['agent']`).
- Agent picker added to the New Session form (`PageView::AGENT_OPTIONS`,
  a plain view-layer constant mirroring `AgentRegistry`'s known ids rather
  than reaching into `HostAgent\` classes from container-side view code -
  see that constant's own docblock for why), defaulting to Claude Code.
  Verified live in a real browser (screenshot via the existing CDP test
  helper) - renders correctly, both options present, zero console errors.
- Reactive sidecar binding off the first hook's `conversationId` is NOT
  built yet - it's a Phase 3 concern (needs a real hook script to react
  from). A freshly-spawned Antigravity session today gets a sidecar with
  `claude_session_id: null` and sits there until Phase 3's first hook
  script binds it - confirmed via a real end-to-end test against a new
  `tests/fixtures/fake_agy` stand-in (mirrors `fake_claude`).

**Phase 3 — hooks — DONE, scope adjusted per the live findings above**
- `AntigravityHookService` (`host-agent/lib/Services/AntigravityHookService.php`)
  writes `~/.gemini/config/hooks.json` under a `sessioneer`
  named group, in the confirmed nested schema (grouped-with-matcher for
  PreToolUse/PostToolUse, flat for PreInvocation/Stop) - same data-driven,
  never-touch-other-hook-groups discipline as `HookService`. Wired into
  `AntigravityAdapter::check_hooks()`/`install_hooks()`, replacing the
  Phase 2 stubs.
- New scripts under `host-agent/hooks/antigravity/`: `pre_tool_use.php`
  (records `PendingToolStore`, **always returns `{"decision":"ask"}`, not
  `"allow"`** - deliberate: confirmed live `"allow"` does NOT suppress the
  real approval UI in this version, and this hook is registered GLOBALLY
  (fires for every `agy` invocation on the machine, not just Sessioneer-spawned
  ones) - `"ask"` matches today's real no-hook-installed behavior exactly,
  so it's a genuine no-op rather than a dormant global auto-approve
  waiting to activate itself the moment a future Antigravity version fixes
  that bug), `pre_invocation.php` (~`UserPromptSubmit` + first-hook
  reactive session-id bind, marks working - only writes when the id
  actually needs to change, not on every turn), `post_tool_use.php`
  (clears `PendingToolStore`, returns `{}`), `stop.php` (marks idle,
  `last_message` from tailing `transcript_full.jsonl`'s last
  `PLANNER_RESPONSE` entry via the same bounded-tail-read pattern
  `TranscriptService::find_latest_ai_title()` already uses - `Stop`'s own
  payload carries no message text, unlike Claude Code's - returns
  `{"decision":"allow_stop"}`, a fixed non-"continue" sentinel).
- **Confirmed live, end-to-end, against Andres's real account**: installed
  the real hooks, spawned a real `ag-*` session through
  `SessionLifecycleService::create_agent_session()` (the actual code path the
  dashboard uses), sent it a real prompt via `PromptInteractionService::
  send_message()`, watched `SessionStatusStore` flip to `idle` with
  `last_message` correctly showing the real reply, confirmed the sidecar's
  `claude_session_id` got bound to the real `conversationId`, confirmed
  the session renders correctly in the real dashboard HTML, killed it
  cleanly via the normal kill action. Full loop works.
- **Deployment gotcha found live**: `ANTIGRAVITY_BIN` (like `CLAUDE_BIN`)
  only reaches `Config` when host-agent runs as the real systemd service
  (`EnvironmentFile=` loads `host-agent/.env`) - a bare `php -r`/manual CLI
  invocation never sees it, since nothing auto-loads `.env` outside that
  service definition. Not a bug, just worth remembering when testing
  host-agent code directly instead of through the real service.
- Ships working/idle tracking correctly. Does **not** ship "blocked" status
  for Antigravity sessions - confirmed live a hook's `"ask"`/`"allow"`
  decision doesn't suppress the interactive approval prompt either way,
  and Antigravity has no `PermissionRequest`-equivalent second hook to
  distinguish "needs a decision" from "every tool call" the way Claude
  Code's setup does. An Antigravity session sitting on a real "Do you want
  to proceed?" prompt
  will report status=working until Phase 6 adds pane-text detection.

**Phase 4 — transcript rendering — DONE**
- `AntigravityTranscriptService` (`host-agent/lib/Services/AntigravityTranscriptService.php`)
  tails `transcript_full.jsonl` the same append-only-polling way
  `TranscriptService` already tails Claude Code's file (`read_transcript_page()`/
  `read_transcript_page_since()`, identical contracts) - different, simpler
  field mapping, same underlying mechanism, own small class rather than
  teaching `TranscriptService` a second JSONL schema inline. `CHECKPOINT`
  entries are skipped entirely for v1 (not rendered as a divider - simpler,
  and nothing has asked for the divider treatment yet).
- `TranscriptRouter` (`host-agent/lib/Services/TranscriptRouter.php`) is the
  new dispatch seam - routes to `TranscriptService` or
  `AntigravityTranscriptService` by PATH SHAPE (`/antigravity-cli/brain/`
  vs `/.claude/projects/`), not a passed-in agent id, so the ~6 real call
  sites (`SessionDetailService`'s live-session paths, `SessionService::
  session_title()`/`session_last_message()`) needed only a one-line
  swap each (`TranscriptService::` → `TranscriptRouter::`), no new
  parameter threading. Archived-session paths were deliberately left
  Claude-Code-only for now (session archival isn't extended to Antigravity
  yet - out of scope).
- **The View layer needed zero changes.** Both parsers produce the exact
  same canonical `{type, role, timestamp, blocks:[{kind, text, ...}]}`
  shape `TranscriptView`/`src/partials/transcript/*` already render, and
  `TranscriptView::entry_color_kind()`'s existing "colored by block kind,
  not literal role" logic plus `render_transcript_entries_html()`'s
  existing PURELY POSITIONAL tool_use/tool_result pairing (next entry,
  not a `tool_use_id` correlation) both turned out to already generalize
  perfectly - confirmed by reading them before writing any Antigravity
  parsing code, not by trial and error.
- Tool-call summaries prefer Antigravity's own `toolSummary`/`toolAction`
  fields (human-written one-liners present on nearly every real call)
  over hand-formatting each tool name's own args the way `TranscriptService`
  does for Claude Code's fixed vocabulary - Antigravity's own tool set
  hasn't been fully enumerated yet. `run_command` specifically also maps
  to `tool_name: 'Bash'` + the real command, so it renders as "Ran
  `<command>`" - identical to Claude Code's own Bash summary, confirmed
  live.
- **Confirmed live, end-to-end**: spawned a real session, sent a prompt
  that triggered a real (manually-approved) `run_command` tool call,
  screenshotted the real rendered `session.php` - the tool call/result
  paired and collapsed correctly ("Ran echo transcript-render-test"),
  expanding it showed the full real formatted output, the assistant's
  own follow-up text rendered as a free-flowing bubble, zero console
  errors. Full loop, real render layer, no shortcuts.
- Tool-result entry shape confirmed live (see "Open questions" above):
  `{"type":"GENERIC","source":"MODEL","content":"<formatted text>"}`.
- Not done: attachments (no mechanism observed yet in Antigravity's own
  tool calls/results - `read_attachment()` is an honest stub), ai-title/
  todo-list/task-list equivalents (Claude-Code-specific features with
  nothing to port to yet - harmlessly find nothing rather than crashing).

**Phase 5 — permission mode + display**
- Small map: confirmed `--mode` values are `accept-edits`/`plan` (plus the
  interactive default with no explicit flag) → Sessioneer's canonical vocabulary.
- Dashboard/session-page mode control becomes agent-aware (don't show
  Claude-only modes for an Antigravity session or vice versa).

**Phase 6 — blocked-prompt detection + real interactive answering (defer
past MVP, scope expanded per the live findings above)**
- Two layers, both missing today: (a) DETECTING that an Antigravity
  session is genuinely blocked on a real approval prompt at all - confirmed
  live a hook's `allow` decision doesn't suppress that UI, and there's no
  `PermissionRequest`-equivalent hook to signal "this one actually needs a
  decision" the way Claude Code's setup does, so this needs pane-text
  detection (parsing "Do you want to proceed?" + its numbered options),
  the same category of work `PromptParser` already does for Claude Code's
  `AskUserQuestion`/trust-dialog cases; (b) ANSWERING it from the web UI -
  `PreToolUse`'s `decision` gates execution, and per the docs, hooks run
  **synchronously and block the agent loop**, so answering "allow/deny"
  from a browser click means the hook process itself has to wait on that
  click before it can exit - a genuinely new request/response mechanism
  (nothing like it exists today; Claude Code's hooks are never
  blocking-decision-capable in the first place) - OR, simpler and possibly
  sufficient, just keep answering via tmux send-keys against the real pane
  (same mechanism Claude Code's answer_prompt() already uses) once (a)
  above can detect the prompt is showing, sidestepping the hook-blocking
  problem entirely by never routing the answer through the hook at all.
  Worth deciding between these two approaches when this phase starts, not
  assuming the harder one is required.

**Phase 7 — quota polling — DONE (built ahead of Phase 5/6, Andres's own
call - "transcript first, then quota")**
- Real research finding that unlocked this (see "Open questions" for the
  full detail): quota is NOT part of the transcript or any hook payload,
  but Antigravity's `/usage` slash command (aliased `/quota`) IS - and
  confirmed live it's genuinely free (`duration_seconds:0`, all-zero token
  usage, empty `conversation_id`, no transcript entry written) and gives
  fully structured JSON via `agy -p "/usage" --output-format json`:
  `command.data.groups[].buckets[]`, each with `id`/`remaining_fraction`
  (0-1)/`reset_time` - structurally a twin of Claude Code's own
  `rate_limits.five_hour/seven_day`, just grouped by model family (Gemini
  vs Claude/GPT) instead of session/week windows.
- `host-agent/antigravity_quota_poll.php` (new, standalone, mirrors
  `quota_live_state_write.php`'s role) runs that command via the existing
  `ProcessRunner` primitive, converts each bucket's `remaining_fraction`
  to a USED percentage (`(1 - remaining_fraction) * 100`) - Antigravity
  reports the opposite orientation from Claude Code's own `pct` convention,
  converted here so any future shared display code only ever handles one
  convention - and writes straight to `GlobalStateStore` under
  `Config::antigravity_quota_live_state_key()`
  (`antigravity_quota_live_state`, its own key, separate from Claude
  Code's `quota_live_state`). No merge-against-previous logic needed here
  (unlike the Claude Code side) - this script is the ONLY writer, so every
  successful poll is already the full current truth, a plain overwrite is
  correct.
- New `sessioneer-antigravity-quota-check.service`/`.timer` systemd unit pair
  (`host-agent/systemd/`), `OnUnitActiveSec=60s` (Andres's own ask - free
  call, no cost pressure to poll less often). Wired into `install.sh`
  exactly like the existing push-check timer - units installed but **not
  auto-enabled** (opt-in, same reasoning: a no-op until `ANTIGRAVITY_BIN`
  is set anyway, and starting a new recurring background service
  shouldn't happen silently on every install.sh run).
- `tests/fixtures/fake_agy` extended to distinguish `-p`/`--print` (a
  canned `/usage` JSON response, matching the real shape) from the
  existing interactive-TUI-spawn shape (still blocks like `cat`) - the two
  are genuinely different invocation modes this app now uses for real.
- **Confirmed live against Andres's real account**: ran the real script
  with his real `ANTIGRAVITY_BIN`, captured real numbers into his real
  `push.sqlite` (`gemini-weekly` pct=8, `3p-weekly` pct=0, real
  `resets_at` epochs, real group names).
- **Not done**: actually DISPLAYING this anywhere in the UI (a quota
  footer entry, dashboard widget, etc.) - only asked to build the
  capture/storage side this pass. The data is sitting in `GlobalStateStore`
  ready for whenever that's wanted.

## Testing conventions

Mirror the existing test suite's isolation discipline: fixture paths for
`hooks.json`/transcript files via env-var overrides, a saved real
`transcript_full.jsonl` sample from this research as a first fixture, no
live `agy` process in the automated suite — same "never touch the real
thing" principle already used for `claude`/tmux throughout `tests/`.

## Recommended execution order

Phase 1 first regardless (pure refactor, immediately testable by the
existing suite, unblocks everything else) → Phase 2 → Phase 3 (resolving
open question #1 as its first step) → Phase 4, each shippable/testable on
its own before starting the next. Phases 5–7 are smaller and can slot in
once 2–4 are solid.
