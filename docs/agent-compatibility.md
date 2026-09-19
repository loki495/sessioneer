# Agent Compatibility

Sessioneer supports four coding agents, each with its own protocol and UI integration. This page documents tested versions and known quirks per agent.

| Agent | Tested Versions | Status | Notes |
|-------|-----------------|--------|-------|
| **Claude Code** | 1.0–1.16 | Stable | Primary agent; full feature support including hooks, tool approvals, context usage |
| **Antigravity** | 1.0–1.2.1+ | Stable | Full tmux integration; approval dialog and tool-call shape updates in 1.2.1+ |
| **OpenCode** | Recent versions | Stable | Headless by default (`opencode serve`); tmux fallback (`oc-*`) also supported |
| **Codex** | Recent versions | Stable | Headless only (thread UUIDs); no tmux variant; private bridge for turn ownership |

## Per-Agent Details

### Claude Code
- Hooks system is primary integration point (`SessionStart`, `PreToolUse`, `PermissionRequest`, etc.)
- Session rotation on `/clear`, `/compact`, `--resume`, `--fork-session` all tracked via hooks
- Tool approval dialogs and blocked-on-input detection fully supported
- Context usage percentage live-updated on dashboard

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
- **Claude Code with manual attachment**: Sessions started outside Sessioneer (attached directly to tmux) have no `SESSIONEER_SESSION_NAME` env var and won't participate in hook callbacks; state detection falls back to pane scraping (less reliable).
- **OpenCode tmux fallback**: Less feature-complete than headless serve mode; use headless when possible.

## Testing / Reporting Issues

Test new agent versions against the Sessioneer test suite before assuming compatibility:

```bash
npm test                    # Run the full suite
npm test -- agent:codex    # Test a specific agent
```

Report any version-specific issues with exact version numbers and reproduction steps to [CONTRIBUTING.md](../CONTRIBUTING.md).
