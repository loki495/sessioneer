# Agent Compatibility

Sessioneer supports four coding agents, each with its own protocol and UI integration. This page documents tested versions and known quirks per agent.

| Agent | Tested Versions | Status | Notes |
|-------|-----------------|--------|-------|
| **Claude Code** | 2.1.x (headless protocol captured and live-verified on 2.1.278) | Stable | Primary agent; runs in tmux (default) or headless; full feature support including hooks, tool approvals, context usage |
| **Antigravity** | 1.0–1.2.1+ | Stable | Full tmux integration; approval dialog and tool-call shape updates in 1.2.1+ |
| **OpenCode** | Recent versions | Stable | Headless by default (`opencode serve`); tmux fallback (`oc-*`) also supported |
| **Codex** | Recent versions | Stable | Headless only (thread UUIDs); no tmux variant; private bridge for turn ownership |

## Per-Agent Details

### Claude Code
- Two runtimes: tmux (the default) and headless. Both are fully supported and a session can be switched between them.
- tmux: hooks are the primary integration point (`SessionStart`, `PreToolUse`, `PermissionRequest`, etc.); transcript rotation on `/clear`, `--resume`, `--fork-session` is tracked via `SessionStart`
- Headless: a persistent manager runs one `claude -p` stream-json process per active session on your own claude.ai login (never an API key) and reports state, prompts and session-id changes from that process's own events; no hooks or pane are involved. See [features](features.md#headless-runtime)
- Tool approval dialogs and blocked-on-input detection fully supported in both
- Context usage percentage live-updated on dashboard
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
