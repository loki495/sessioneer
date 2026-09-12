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
| Claude Code | `cc-*` | tmux | Claude JSONL | Five Claude hooks; narrow pane fallbacks | tmux key input |
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
| tmux attach command | ✓ | ✓ | ◐ TUI runtime only | — |
| Discover / take over a bare process | ✓ | ✗ | ✗ | ✗ |
| Working / idle state | ✓ hooks | ✓ hooks | ✓ serve/DB | ✓ bridge + hooks |
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
| Usage / quota display | ✓ | ✓ optional timer | ✓ | ✓ app-server rate limits |
| File upload / attachment send | ✓ | ✓ | ✓ | ✓ |
| Web Push on blocked / finished state | ✓ | ✓ | ✓ | ✓, including observe-only Remote blocks |

## Claude Code implementation

`ClaudeCodeAdapter` supports only `RuntimeType::TMUX`. New sessions receive an
explicit UUID through `claude --session-id`; the `SessionStart` hook repairs
the sidecar if Claude rotates that ID after `/clear`, `/compact`, resume, or a
fork.

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
for sessions started outside Sessioneer. Model and permission-mode changes
drive Claude's own pickers. Quota comes from the status-line JSON marker and
will be unavailable until Claude renders that status line at least once.

Implementation entry points:

- `host-agent/lib/Agents/ClaudeCodeAdapter.php`
- `host-agent/lib/Services/HookService.php`
- `host-agent/hooks/*.php`
- `host-agent/lib/Services/PromptInteractionService.php`
- `host-agent/lib/Services/TranscriptService.php`

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
