# Agent Compatibility

Sessioneer drives four coding agents plus local Ollama models, each through its
own protocol. This page lists how well each one is supported, the last agent
version it was verified against, and the main limitations. [features.md](features.md)
has the full capability matrix and parity gaps.

## Support tiers

- **Primary**: the agent Sessioneer is built around.
  Every feature lands here first, and the suite carries captured protocol
  fixtures for it.
- **Supported**: works end to end and is covered by the suite, with known gaps.
- **Experimental**: works for the main flows but leans on pane scraping or
  unofficial formats that change between releases.

| Agent | Tier | Last verified | Runtimes | Main limitations |
|-------|------|---------------|----------|------------------|
| **Claude Code** | Primary | 2.1.278 and 2.1.302 (headless stream-json captures); tmux pane fixtures from 2.1.269 | Headless (default) or tmux | Headless context % requires a measured window; no statusline-derived worktree; concurrent permission prompts queue by request id |
| **OpenCode** | Supported | 2.2.0 | `opencode serve` (default) or tmux fallback | v2 only: no todo list (v2 dropped the endpoint). No full status-hook plugin or mode vocabulary; tmux fallback is less complete. CLI launch is serve-only, with no profile, effort, mode or tool flags |
| **Codex** | Supported | 0.152.1 (app-server schema from 0.150.1) | Headless only | Approvals and input requests are answerable from Sessioneer and Codex Remote alike while the bridge is attached to the managed daemon; with only the private fallback, ones owned by Codex Remote are visible but can't be answered; no transcript search |
| **Antigravity** | Experimental | `agy` 1.2.1 | tmux only | Approval dialogs read from the pane; model changes are account-wide; no transcript search; hooks are a manual install |
| **Ollama** | Supported | 0.9.6 (`/api/tags`, `/api/show`, streaming `/api/chat`) | Headless only | Chat only: no tools, attachments, permission prompts, modes or effort. One turn per session at a time. No quota row, and a killed session leaves its transcript on disk with nothing in the dashboard to reopen it from |

"Last verified" is the newest version the integration was checked against live
or captured in test fixtures, not a minimum. Older versions may work; newer ones
usually do, but a changed protocol or screen layout can break detection until
Sessioneer catches up.

## Compatibility policy (0.x)

Sessioneer is pre-1.0. Until 1.0:

- Any minor release (0.x) can change behaviour, configuration or the support
  tier of an agent. Release notes call out such changes.
- Only the "Last verified" versions above are known to work. When an agent
  ships a release that breaks Sessioneer, the fix targets the new agent version
  and the table is updated; older agent versions aren't kept working on purpose.
- The Claude Code health box warns when the installed `claude` is older than
  the verified version. After upgrading to a newer one, run
  `bash tests/run.sh --live` to check it against the real CLI.

## Per-Agent Details

### Claude Code
- Two runtimes: headless (the default for new sessions) and tmux. Both are fully supported and a session can be switched between them.
- tmux: hooks are the primary integration point (`SessionStart`, `PreToolUse`, `PermissionRequest`, etc.); transcript rotation on `/clear`, `--resume`, `--fork-session` is tracked via `SessionStart`
- Headless: a persistent manager runs one `claude -p` stream-json process per active session on your own claude.ai login (never an API key) and reports state, prompts and session-id changes from that process's own events; no hooks or pane are involved. See [features](features.md#headless-runtime)
- Tool approval dialogs and blocked-on-input detection fully supported in both
- Context usage percentage on session pages in both runtimes when a snapshot and window are reported; dashboard rows also show available Claude readings. Tmux uses its statusLine marker; headless uses the latest main-thread assistant input snapshot and that model's reported window. Unknown data is shown as unavailable.
- Headless reasoning effort is selectable beside model and permission mode. Changes are session-only and persist for lazy resume; polling reads the applied level after model/environment limits. The control protocol was checked against 2.1.306. Unsupported CLIs/models and tmux show a disabled effort control.
- The dashboard health box compares the installed `claude --version` with the version the headless handling was verified against and flags an older one; after a newer one, run `bash tests/run.sh --live`

### Antigravity
- Tmux-backed; full session management and approval workflow
- Version 1.2.1+ added new approval-dialog and tool-call shapes — Sessioneer detects and adapts
- Earlier versions (pre-1.2.1) still work but may have minor UI edge cases

### OpenCode
- Headless server mode (`opencode serve`, localhost) is primary; served via serve API
- Tmux fallback (`oc-*` sessions) available but secondary
- Permissions plugin integration for tool approvals
- Both modes can coexist; Sessioneer auto-detects which is available per session
- `bin/sessioneer --agent opencode` launches a new serve session, reuses a tracked one, resumes a `ses_*` id (creating it in the serve if needed), lists, kills, and supports `--send`/`--peers`/`--wait` like the other launchers. A session's name is its `ses_*` id; `--model` is `provider/model` (applied with the session-scoped model endpoint). `--profile`, `--effort`, `--mode`, `--no-tools` and `--task-tools` are refused: a serve session has no account profile, reasoning-level control, permission-mode vocabulary or tool-set flags. `--kill` interrupts, then deletes the serve session; the conversation stays in `opencode.db` and the id can be resumed again.
- **OpenCode 2.x** moved questions to interactive forms and put the API under `/api`, behind HTTP Basic auth (user `opencode`, password from `~/.config/opencode/service.json`): `GET /api/event` streams `form.created/replied/cancelled`, and an answer is `POST /api/session/{id}/form/{formID}/reply` with `{"answer":{"q0":"<value>"}}`. Session lifecycle, status (`/api/session/active`), permissions (`/api/permission/request`, `POST /api/session/{id}/permission/{requestID}/reply`), prompts (`POST /api/session/{id}/prompt`) and models (`/api/model`, `/api/model/default`) are all on that surface. The permission and form lists are directory-scoped (`?location[directory]=…`), so Sessioneer queries them per live session workdir; the global permissions plugin registers `ctx.permission.hook("evaluate")` and `ctx.event.subscribe(...)` and mirrors the same request into a local fallback store. v2 dropped the todo endpoint, so opencode sessions have no todo list. 2.x also adds `session_v2` and `session_message` alongside the legacy `session`, `message` and `part` tables: sessions created after the upgrade are written only to the new pair, while older ones stay in the legacy tables. The transcript reader (`OpenCodeTranscriptService`) reads both generations, so a 2.x-only session still renders in the session page, the dashboard's last-message preview, the archived list and search. A turn that ends in a provider error renders as an `Error: <message>` line instead of disappearing, and a tool's result comes from `state.output` (legacy) or its `state.content` text chunks (2.x).

### Codex
- Headless only — no tmux variant
- Uses thread UUIDs (not tmux pane names) for session identity
- Private bridge protocol for turn ownership and Sessioneer-specific state
- No pane scraping needed; all state comes from bridge
- Session-page context usage uses the latest `tokenUsage.last` snapshot and reported `modelContextWindow`, separate from cumulative token totals; unavailable until both are reported.
- The `sessioneer` CLI talks to the host agent over a UNIX socket, and Codex's Linux sandbox denies `connect()` for every socket family (AF_UNIX included) while the thread's network sandbox policy is restricted. A `workspace-write` thread therefore needs `[sandbox_workspace_write] network_access = true` in the Codex config, or `sessioneer --peers/--send/--usage` fails with "Cannot reach host agent (Operation not permitted)". A `read-only` thread (`--mode plan`) has no network setting at all, so it can never reach the host agent: the orchestrating session has to run the peer and quota checks for it.

## Known Compatibility Notes

- **Pre-Antigravity 1.2.1**: Tool-call and approval shapes may not render correctly. Upgrade recommended.
- **Claude Code with manual attachment**: Sessions started outside Sessioneer (attached directly to tmux) have no `SESSIONEER_SESSION_NAME` env var and won't participate in hook callbacks, so they report unknown/idle with no prompt rather than being read from the pane. Use **Take over** to adopt one into a tracked session.
- **OpenCode tmux fallback**: Less feature-complete than headless serve mode; use headless when possible.

## Testing / Reporting Issues

Test new agent versions against the Sessioneer test suite before assuming compatibility:

```bash
bash tests/run.sh          # Run the whole (hermetic) suite
bash tests/run.sh --live   # Also exercise the real installed CLIs (opt-in, spends a little plan usage)
php tests/test_<area>.php  # Run one test file directly (load tests/.env.testing first, as run.sh does)
```

Report any version-specific issues with exact version numbers and reproduction steps to [CONTRIBUTING.md](../CONTRIBUTING.md).
