# Agent Compatibility

Sessioneer drives four coding agents, each through its own protocol. This page
lists how well each one is supported, the last agent version it was verified
against, and the main limitations. [features.md](features.md) has the full
capability matrix and parity gaps.

## Support tiers

- **Primary**: the agent Sessioneer is built around.
  Every feature lands here first, and the suite carries captured protocol
  fixtures for it.
- **Supported**: works end to end and is covered by the suite, with known gaps.
- **Experimental**: works for the main flows but leans on pane scraping or
  unofficial formats that change between releases.

| Agent | Tier | Last verified | Runtimes | Main limitations |
|-------|------|---------------|----------|------------------|
| **Claude Code** | Primary | 2.1.278 (headless stream-json captures); tmux pane fixtures from 2.1.269 | Headless (default) or tmux | Headless sessions show no context % or worktree; one pending permission prompt tracked at a time |
| **OpenCode** | Supported | 1.18.21 | `opencode serve` (default) or tmux fallback | 1.x only: OpenCode 2.x is not supported yet (see below). No full status-hook plugin or mode vocabulary; tmux fallback is less complete |
| **Codex** | Supported | 0.152.1 (app-server schema from 0.150.1) | Headless only | Approvals and input requests owned by Codex Remote are visible but can't be answered; no transcript search |
| **Antigravity** | Experimental | `agy` 1.2.1 | tmux only | Approval dialogs read from the pane; model changes are account-wide; no transcript search; hooks are a manual install |

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
- Context usage percentage on the dashboard for tmux sessions (headless sessions have no status line to read it from)
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
- **OpenCode 2.x (checked against 2.0.20) is not supported.** With a database created by 2.x, `opencode serve` answers Sessioneer's `/session`, `/permission` and `/question` routes with the web app's HTML, and the `/api/...` routes with 401 "Authentication required"; 2.x also keeps sessions in new tables (`session_v2`, `session_message`) instead of the `session`, `message` and `part` tables the transcript reader uses. A `serve` process still running 1.18.21 keeps working. Stay on 1.x for Sessioneer until 2.x support lands

### Codex
- Headless only — no tmux variant
- Uses thread UUIDs (not tmux pane names) for session identity
- Private bridge protocol for turn ownership and Sessioneer-specific state
- No pane scraping needed; all state comes from bridge

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
