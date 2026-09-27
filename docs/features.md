# Feature reference

This is Sessioneer's current four-agent capability and implementation
reference. The README is the installation guide; this document answers which
agent supports a feature, what mechanism provides it, and where parity is
intentionally incomplete.

Legend: **✓** supported · **◐** supported with a caveat · **✗** unavailable ·
**—** not applicable.

## Runtime and integration map

| Agent | Session prefix | Runtime | Conversation source | Activity / blocked source | Write path |
|---|---:|---|---|---|---|
| Claude Code | `claude-headless-*` headless (default) / `cc-*` tmux | headless (`claude -p` owned by the headless manager) by default, or tmux | Claude JSONL | tmux: five Claude hooks, narrow pane fallbacks; headless: the process's own event stream | tmux key input, or the manager's stdin |
| Antigravity | `ag-*` | tmux | `transcript_full.jsonl` | Four Antigravity hooks plus the pane for approval visibility | tmux key input |
| OpenCode | `ses_*` headless / `oc-*` tmux | `opencode serve` by default, tmux fallback | `opencode.db` | Serve API, SQLite, permissions plugin, and TUI pane where necessary | serve API or tmux key input |
| Codex | native thread UUID | headless only | Codex rollout JSONL and app-server | Private bridge for Sessioneer-owned turns; Codex hooks for Remote-owned activity | Persistent queue after materialization; private bridge for a new thread's first turn and owned prompt replies |

All host-only work goes through `host-agent/`; the web container never controls
tmux, agent daemons, process tables, or user configuration directly.

## Capability matrix

| Capability | Claude Code | Antigravity | OpenCode | Codex |
|---|---:|---:|---:|---:|
| List / open / kill | ✓ | ✓ | ✓ | ✓ (kill archives the thread) |
| Create in selected workdir | ✓ | ✓ | ✓ | ✓ |
| Resume archived session | ✓ | ✓ | ✓ | ✓ |
| tmux attach command | ◐ tmux runtime only | ✓ | ◐ TUI runtime only | — |
| Runs without a terminal (headless) | ✓ default, chosen per session | ✗ | ✓ default | ✓ always |
| Discover / take over a bare process | ✓ | ✗ | ✗ | ✗ |
| Working / idle state | ✓ hooks (tmux) / process events (headless) | ✓ hooks | ✓ serve/DB | ✓ bridge + hooks |
| Detect a pending permission | ✓ | ✓ pane | ✓ | ◐ owned prompts are answerable; Remote prompts are observe-only |
| Display exact tool details | ✓ hook | ◐ hook metadata + pane | ◐ depends on plugin/API shape | ◐ hook context for Remote; full bridge payload when owned |
| Approve / deny in Sessioneer | ✓ | ✓ | ✓ | ◐ Sessioneer-owned prompts only |
| Answer free-text / structured question | ✓ | ◐ agent UI dependent | ✓ | ◐ Sessioneer-owned prompts only |
| Multi-question form | ✓ | — | ◐ OpenCode question shape | ◐ Sessioneer-owned `request_user_input` only |
| Send a normal message | ✓ | ✓ | ✓ | ✓ queued across owners after the thread is materialized |
| Interrupt active turn | ✓ | ✓ | ✓ | ◐ private-bridge-owned turn only |
| Select model when creating | ✓ | ✗ UI uses default | ✓ | ✓ |
| Switch model in a live session | ✓ | ✓ account-wide setting | ✓ | ✓ |
| Choose starting mode at creation | ✓ | ◐ `accept edits` / `plan` | ✗ | ✗ |
| Switch interaction mode in a live session | ✓ | ✗ | ✗ | ✗ |
| Switch reasoning effort in the UI | ✗ | ✗ | ✗ | ✓ |
| Live transcript and forward polling | ✓ | ✓ | ✓ | ✓ |
| Archived transcript / cwd / title | ✓ | ✓ | ✓ | ✓ |
| Dashboard-wide content search | ✓ | ✗ | ✓ | ✗ |
| Per-session content search | ✓ | ✗ | ✓ | ✗ |
| Usage / quota display | ✓ per configured account | ✓ optional timer | ✓ | ✓ app-server rate limits |
| Context-window % and git worktree on the session page | ◐ tmux runtime only | ✗ | ✗ | ✗ |
| Multiple accounts (profiles) | ✓ | ✗ | ✗ | ✗ |
| File upload / attachment send | ✓ | ✓ | ✓ | ✓ |
| Web Push on blocked / finished state | ✓ | ✓ | ✓ | ✓, including observe-only Remote blocks |

## Claude Code implementation

`ClaudeCodeAdapter` supports `RuntimeType::TMUX` and `RuntimeType::HEADLESS`
(see "Headless runtime" below). New sessions receive an explicit UUID through
`claude --session-id`; in tmux the `SessionStart` hook repairs the sidecar if
Claude rotates that ID after `/clear`, resume, or a fork (a headless session
follows the id from its process's own `init` events instead).

Sessioneer installs these entries in `~/.claude/settings.json`:

- `SessionStart`: bind or repair transcript identity.
- `UserPromptSubmit`: mark the session working and record mode.
- `PreToolUse`: retain full tool name/input, including `AskUserQuestion`.
- `PermissionRequest`: mark the session blocked with authoritative tool
  context.
- `Stop`: mark idle and retain last-response/error data.

The hooks are scoped at runtime by `SESSIONEER_SESSION_NAME`; a manually
started Claude process does not accidentally become a tracked session.
Sessioneer answers through the tmux TUI. Initial folder trust and the visible
tab of `AskUserQuestion` are read from the pane because Claude's hook payload
cannot represent those states completely. Multi-question calls retain the
full hook payload and are answered as a single validated key sequence.

Claude is the only integration with process-table discovery and **Take over**
for sessions started outside Sessioneer. Discovery covers not just a plain
`claude` process started by hand, but also a worker dispatched through the
CLI's own background daemon (a `bg-spare`/`bg-pty-host` warm-pool pair used
to start new/resumed sessions instantly) - identified with certainty via
either an explicit `--resume`/`--session-id` in the process's own argv, or
the daemon's own `~/.claude/daemon/roster.json` worker registry, never a
guess. The dashboard shows each identified bare row's real title
automatically (no click needed) and collapses a daemon worker's two real
processes (the pty-host wrapper, whose own cwd never leaves the daemon's
internal pool path, plus its REPL child, whose cwd is the real project
folder) into one row rather than showing both separately. **Take over** on
a daemon-managed worker kills both halves of the pair (not just the one
clicked) and resumes into the real project cwd. A headless session's own
process is not a bare one: it is left out of the discovery list, and Kill and
Take over refuse it (stop it, or switch it to a terminal, from its own row).
Take over waits for the stopped process to actually exit (up to
`TAKE_OVER_EXIT_WAIT_SECONDS`, default 15) before resuming its conversation,
since a real Claude can take seconds to shut down; if it is still running at
the deadline the conversation is left for the Archived list and the message
says so. A row this can't identify
with certainty still falls back to the pre-existing closest-start-time
heuristic, clearly labeled as a guess. Model and permission-mode changes
drive Claude's own pickers. The model dropdowns (session page and New Session
form) list Claude's families and label each with the newest full name Sessioneer
has read for it ("Opus 5.5"), parsed from the raw model ids in transcripts and
headless status; a family not seen yet keeps its plain name. Quota comes from the status-line JSON marker of
tmux sessions and from the rate-limit events of headless sessions (a headless
process renders no status line); both write the same per-account state through
one merge rule, and it is unavailable until either has run once.

Claude Code alone also supports multiple accounts ("profiles") - a session can
be spawned under a different `CLAUDE_CONFIG_DIR` (e.g. a separate work
account) instead of always using this process's own default, named in
`host-agent/config/agents.php`. A profile is picked from the New Session
form's Account selector when more than one is configured, persists on the
session's sidecar, and is threaded through spawn/resume, transcript lookup,
archived-session listing, and hook install/health checks so a work-profile
session behaves identically to the default account everywhere. A small
"Personal"/"Work" badge shows which account a session belongs to on every
session row, the session header, and the archived-session viewer, and the
quota footer shows one row per configured account instead of a single global
figure - each account's statusline write is tagged by its own
`CLAUDE_CONFIG_DIR` so two accounts' quota readings never overwrite each
other.

The push-check timer lists headless Claude sessions through the same code path
as the dashboard, so a headless session waiting on a prompt notifies with the
prompt itself.

### Headless runtime

A Claude session can also run without a terminal. Sessioneer's headless runtime
drives the installed `claude` binary as a child process (`claude -p` with
stream-json input and output) on your own logged-in claude.ai account: usage
counts against the plan's own windows (the five-hour and seven-day limits the
quota footer shows) and no API key is involved. That is a different thing from
the Claude Agent SDK library, which authenticates with an API key or a Claude
Platform account and bills pay-as-you-go. Sessioneer enforces the difference: it
removes `ANTHROPIC_API_KEY`/`ANTHROPIC_AUTH_TOKEN` from the process, never passes
`--bare` (which ignores the login), kills a process whose `init` event reports an
`apiKeySource` other than `none` and refuses further starts until that is fixed,
and refuses new starts while Claude reports pay-as-you-go overage in use; the
health box shows both conditions. Headless is the default runtime for new
Claude sessions; tmux remains a fully supported runtime, chosen per session.

This is for one person running their own installed CLI on their own login. It
is not meant to be hosted for other people: Anthropic's terms do not let a third
party offer claude.ai login or its rate limits in their own product (README,
"Intended use").

One persistent host-native service, `sessioneer-claude-headless-manager.service`
(installed by `host-agent/install.sh` once `CLAUDE_BIN` is set), owns one process
per active session, and the socket-activated host agent talks to it over a UNIX
socket. The process is a cache and the session's sidecar row is the truth: a
session with no process ("dormant") is resumed with `--resume` by its next
message, a process idle for `CLAUDE_HEADLESS_IDLE_SECONDS` (default 1800) is
stopped, and at most `CLAUDE_HEADLESS_MAX_CHILDREN` (default 8) run at once. The
service loads its code once, so restart it after editing it; restarting it stops
every process, sessions resume on their next message, and any open prompt is
lost.

The New Session form's "Runs in" list preselects Headless, with Terminal (tmux)
as the alternative; a new session started while the manager is down fails with a
"Cannot reach Claude headless manager" message rather than quietly opening a
terminal. Existing conversations move with the **Headless** button on an
archived Claude row or the per-row switch between runtimes (the old process is
stopped completely first, and a busy session or one with no transcript yet is
refused); plain Resume and Take over still open a terminal session. Permission approvals, `AskUserQuestion`
(single and multi-question), plan approval, interrupt, queued messages, image
attachments and model and permission-mode changes all work through the process's
own structured events, and the transcript view still reads the same transcript
file. A permission mode that Claude does not apply (`auto` was silently ignored on
2.1.278) is flagged on the session instead of trusted. There is no terminal to
attach to; to continue a conversation in one, switch the session to the terminal
runtime. A headless session's own process never appears under "other Claude
processes", and Kill and Take over refuse it.

Slash commands are ordinary messages that start with `/` (checked against Claude
Code 2.1.278):

- Commands that run locally (`/context`, `/usage`, `/model <name>`, `/mcp`) answer
  without a model call, so they use no plan quota; the model does see their
  output on later turns. Prefer the model and mode pickers over `/model`, which
  report their result in a structured way.
- Terminal-only commands (`/theme`, `/add-dir`) reply "isn't available in this
  environment" and change nothing. In particular `/add-dir` cannot grant access to
  another directory mid-session; extra directories must be given when the process
  starts (`--add-dir`), which Sessioneer does not offer yet.
- A `/name` that Claude Code does not recognise is not rejected: it is sent to the
  model as an ordinary message and spends a turn, so a typo costs plan quota.
- A command sent while a turn is running is queued like any message.

The health box's "Claude Code headless" section reports the manager, the credential
in use, the CLI version against the one this handling was verified on, capacity and
whether the quota footer is current.

### Claude Code implementation entry points

- `host-agent/lib/Agents/ClaudeCodeAdapter.php`
- `host-agent/lib/Services/HookService.php`
- `host-agent/hooks/*.php`
- `host-agent/lib/Services/PromptInteractionService.php`
- `host-agent/lib/Services/TranscriptService.php`
- `host-agent/config/agents.php` (profile definitions)
- `host-agent/claude_headless_manager.php`, `host-agent/lib/Runtimes/ClaudeHeadlessManager.php` and `ClaudeHeadlessChild.php` (the persistent manager and its processes)
- `host-agent/lib/Runtimes/ClaudeHeadlessRuntime.php`, `ClaudeHeadlessManagerClient.php`, `ClaudeHeadlessPromptProtocol.php` (the runtime the host agent uses)
- `host-agent/lib/Services/SessionRuntimeSwitchService.php` (switching a session between runtimes)
- `host-agent/lib/Services/QuotaService.php` (per-account quota)
- `host-agent/lib/Services/QuotaLiveStateWriter.php` (merges both quota writers' readings)
- `host-agent/lib/Services/ClaudeHeadlessHealthService.php` (headless health-box checks)
- `src/lib/Views/SessionRowView.php` (account badge)

## Antigravity implementation

`AntigravityAdapter` is tmux-only. A new `agy` process cannot be assigned a
conversation ID up front, so `PreInvocation` learns `conversationId` from the
first model turn and binds it to the `ag-*` sidecar.

Four hooks are stored under the `sessioneer` group in
`~/.gemini/config/hooks.json`:

- `PreInvocation`: bind identity and mark working.
- `PreToolUse`: save tool metadata while returning Antigravity's neutral/safe
  `ask` decision.
- `PostToolUse`: clear completed pending-tool metadata.
- `Stop`: mark idle and preserve the last response or pane-only turn error.

Antigravity's tested hook API does not reliably signal that its interactive
approval dialog is currently on screen. `AntigravityPromptParser` therefore
recognizes the live pane structurally and the normal tmux answer path drives
the actual dialog. This is a deliberate exception to hook-based lifecycle
tracking, not a general text-based working/idle heuristic.

The hooks are global to every `agy` process, but their scripts only persist
state when `SESSIONEER_SESSION_NAME` exists. Install them explicitly as shown
in the README. The separate quota timer periodically invokes `agy -p "/usage"`
and is opt-in. Live model switching changes Antigravity's account-wide default
because the CLI exposes no session-local equivalent.

Implementation entry points:

- `host-agent/lib/Agents/AntigravityAdapter.php`
- `host-agent/lib/Services/AntigravityHookService.php`
- `host-agent/hooks/antigravity/*.php`
- `host-agent/lib/Services/AntigravityPromptParser.php`
- `host-agent/lib/Services/AntigravityTranscriptService.php`

## OpenCode implementation

`OpenCodeAdapter` offers `RuntimeType::HEADLESS` first and tmux second. The
installer enables `opencode-serve.service` and `sessioneer-opencode-events.service`; the headless runtime uses
OpenCode's HTTP API for lifecycle, messages, questions, and prompt replies.
The TUI fallback is driven through tmux.

OpenCode assigns its `ses_*` identifier only after the first prompt creates a
database row. Sessioneer finds that row by workdir and spawn time and repairs
the sidecar reactively. Transcripts, token/cost data, and archive metadata come
from OpenCode's local SQLite database.

The global `sessioneer-permissions.js` plugin subscribes to
`permission.asked` and records pending permission details because the tested
OpenCode API does not persist a reliable equivalent. For TUI sessions, the
pane is the final authority that the permission dialog is still visible; this
prevents a stale plugin record from showing answer controls after the dialog
has gone away. Headless questions arrive over directory-scoped SSE and retain
their server request IDs in the status store, protected from polling resets.
Replies validate selections/custom text and use HTTP, including the scoped
legacy reply endpoint when the v2 route is unavailable. Transcript question
cards share the existing AJAX answer controls; SQLite remains the history source.

The health box verifies both `opencode-serve.service` and the installed plugin
file. Restart the service and existing TUIs after updating the plugin. OpenCode
supports model selection but has no equivalent to Sessioneer's Claude-style
mode enum. Dashboard-wide and per-session search use the OpenCode-specific
SQLite search implementation.

Implementation entry points:

- `host-agent/lib/Agents/OpenCodeAdapter.php`
- `host-agent/lib/Runtimes/HeadlessRuntime.php`
- `host-agent/lib/Runtimes/OpenCodeServeClient.php`
- `host-agent/opencode-plugins/sessioneer-permissions.js`
- `host-agent/lib/Services/OpenCodeTranscriptService.php`
- `host-agent/lib/Services/OpenCodeQuestionService.php`

## Codex implementation

`CodexAdapter` supports only `RuntimeType::HEADLESS`; there is no Codex tmux
path. Two separate transports serve different purposes:

1. `sessioneer-codex-bridge.service` owns a long-lived private
   `codex app-server --stdio` connection. It creates threads, handles the first
   turn before a rollout exists, reads/archives threads, updates model/effort,
   and owns the request IDs for prompts raised by its turns.
2. `codex queue --thread <id> --message <text>` appends normal user messages
   through Codex's persistent thread queue once a rollout exists. This path is
   not tied to the private connection, so it works for a materialized thread
   started or currently loaded by Codex Remote. Queued turns are FIFO and wait
   behind an active turn.

This is bidirectional at the normal-message level, not at the pending-prompt
protocol level. Approval and `request_user_input` response IDs are scoped to
the app-server connection that created them. A prompt raised while Sessioneer's
private bridge owns the turn is answerable in Sessioneer. A prompt raised by
Codex Remote or the shared queue/managed transport is recorded by hooks as an
external block and shown without fake answer controls; the user must open Codex
Remote to answer it. Queueing another message neither answers that prompt nor
transfers ownership.

Sessioneer installs one neutral observer command for eight Codex hook events:
`SessionStart`, `UserPromptSubmit`, `PreToolUse` matched to
`request_user_input`, `PermissionRequest`, wildcard `PostToolUse`, `Stop`,
`Interrupt`, and `SessionEnd`. The observer only updates the local status
store. In particular, `PostToolUse` clears an external block after an approved
tool finishes. It always returns `{}` and never approves, denies, answers, or
blocks Codex itself.

Codex requires non-managed hooks to be trusted through `/hooks`. New sessions
load the trusted configuration; sessions already open when it changes should
be reopened or resumed. The managed daemon used by Codex Remote is bootstrapped
separately with `codex app-server daemon bootstrap --remote-control`. Its
restart preserves persisted threads but may interrupt an active turn. The
private Sessioneer bridge has its own systemd lifecycle and clears prompts
whose connection-scoped response IDs became stale after a restart.

Implementation entry points:

- `host-agent/lib/Agents/CodexAdapter.php`
- `host-agent/lib/Runtimes/CodexHeadlessRuntime.php`
- `host-agent/codex_bridge.php`
- `host-agent/lib/Services/CodexHookService.php`
- `host-agent/hooks/codex/status.php`
- `host-agent/lib/Services/CodexTranscriptService.php`

## Shared UI and platform features

- Dashboard and session-detail polling pause while the browser tab is hidden;
  manual and mobile pull-to-refresh remain available.
- Transcript blocks support Markdown, collapsing, copying, attachments,
  tool-call grouping, subagent/worker lineage, thinking state, and turn errors
  where the source agent records them.
- Worker sessions are tagged with parent lineage and hidden by default behind
  **Show worker sessions**.
- Archived sessions are read-only until resumed. Transcript routing, cwd/title
  resolution, paging, and resume routing cover all four agents.
- Plan/handoff/todo files are read-only views; todo Markdown is rendered in the
  sidebar.
- Web Push can notify on blocked and sufficiently long working-to-idle
  transitions. For Codex Remote this includes an observe-only blocked notice
  that directs the user back to the owning app.
- The health panel is split into Global, Claude Code, OpenCode, and Codex
  checks. Antigravity hook installation and its optional quota timer currently
  use the manual commands in the README.
- Every session row, the session header, and the archived-session viewer show
  which account a session belongs to (currently meaningful for Claude Code
  only - see its implementation section above); the sidebar's own "This
  session" block shows the current session's id and working directory, each
  with a copy button.

## Known parity gaps

1. Bare-process discovery and takeover are Claude Code only.
2. Transcript content search covers Claude Code and OpenCode, not Antigravity
   or Codex.
3. Antigravity must use its pane to confirm/answer approval UI because its hook
   signal is insufficient; model changes are account-wide.
4. OpenCode has no full status-hook plugin or Claude-style mode vocabulary.
5. Codex Remote-owned approvals and `request_user_input` prompts are visible
   but cannot be answered from Sessioneer because the response IDs are owned by
   Remote's app-server connection. Normal messages remain cross-owner through
   the persistent queue.
6. The dashboard **Install hooks** action covers Claude Code and Codex.
   Antigravity hooks remain an explicit manual install, and OpenCode's plugin is
   installed by `host-agent/install.sh`.
7. Claude headless sessions show no context-window percentage and no git
   worktree: both come from the statusLine output a terminal renders and a
   headless process never does.
8. Claude headless sessions have no session-page control to switch runtime and
   no copyable `claude --resume <id>` command to continue in a terminal; the
   switch is on the dashboard row and archived row only. The New Session form
   still offers Headless when the manager is down and fails with a message
   rather than disabling the choice.
9. Plain Resume and Take over always open a terminal session, whatever the
   default runtime is, and Sessioneer cannot pass `--add-dir` to a headless
   process at start, so `/add-dir` has no way to grant another directory.
