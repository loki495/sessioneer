# Hooks: Structured Session State

Claude Code's hook system is how Sessioneer learns authoritative session state without relying purely on pane scraping. This document explains each hook and why it's necessary.

## SessionStart Hook

Claude Code rotates to a brand-new session-id transcript file (a new UUID under `~/.claude/projects/<cwd>/`) on `/clear`, `/compact` (auto or manual), `--resume`, or `--fork-session` — all while staying in the same tmux pane/process.

Sessioneer's sidecar (one JSON file per tracked session, under `SIDECAR_DIR`) records that session-id exactly once, at spawn, and has no other way to learn it changed. Without the hook, any of those events leaves the sidecar pointing at an abandoned, no-longer-growing transcript file forever after.

**The fix:** `host-agent/hooks/session_start.php`, registered as Claude Code's `SessionStart` hook (fires on every session start, matcher `*` so it covers `startup`/`resume`/`clear`/`compact`/`fork`), rebinds the sidecar's `claude_session_id` live every time it fires.

`create_agent_session()` passes `SESSIONEER_SESSION_NAME=<session name>` as a tmux pane environment variable (`tmux new-session -e ...`) specifically so the hook — inherited into that pane's `claude` process and anything it spawns — can tell which sidecar (if any) belongs to it. A plain `claude` session started by hand outside this app has no `SESSIONEER_SESSION_NAME` and the hook is a no-op for it.

**Note:** This only takes effect going forward. A session that already rotated before the hook was installed needs a one-time manual sidecar rebind (or its next natural `/clear`/`/compact`) to catch up.

## PreToolUse Hook

A blocked permission prompt's "preview" (the command being run, the file being written) is normally scraped straight from `tmux capture-pane` — just whatever's currently rendered in the pane. That has two independent size limits stacked on top of each other:

1. The pane's own height/width (a headless tmux session has no attached client to inherit a real terminal size from, so it defaults to 80x24 unless `TMUX_PANE_WIDTH`/`TMUX_PANE_HEIGHT` are configured larger — nowhere near enough for a large `Write` or a multi-line script to render in full)
2. `parse_blocking_prompt()`'s own context-window scan on top of whatever *did* render

Both are best-effort reconstructions of something that was never meant to be machine-read in the first place.

**The fix:** `host-agent/hooks/pre_tool_use.php`, registered as Claude Code's `PreToolUse` hook (fires immediately before every tool call, including ones that never end up needing approval — before any permission prompt is shown), sidesteps both limits by recording the tool call's `tool_name` and full, untruncated `tool_input` JSON straight from the hook's own stdin, no terminal rendering involved.

`build_session_entry()` prefers this recorded data over the pane-scraped context whenever a blocking prompt is currently detected *and* the recorded tool name matches the pane's own "● ToolName(...)" marker line (a cheap sanity check against showing a stale or mismatched previous tool call's data). The hook writes nothing to stdout and always exits `0`, which Claude Code treats as "no opinion" — it never approves, denies, or otherwise affects the real permission decision, only observes it.

Same `SESSIONEER_SESSION_NAME` mechanism as above: a plain `claude` session started by hand outside this app is a no-op.

The recorded pending-tool file is cleared once Sessioneer itself submits an answer to the prompt or the session is killed; it's otherwise just overwritten by the next tool call, so a stale leftover from answering outside this app only ever lingers until the *next* tool call fires the hook again.

## PermissionRequest / UserPromptSubmit / Stop Hooks

Beyond a blocked prompt's *content* (the `PreToolUse` hook above), three more things used to come only from scraping the rendered tmux pane:

1. The current permission mode (matching Claude Code's status-line text, e.g. "manual mode on")
2. Whether the session is actively working (matching an animated spinner glyph on the pane title)
3. Whether a permission prompt is blocking at all

All three are now fed by hook sequence:

- `host-agent/hooks/user_prompt_submit.php` (Claude Code's `UserPromptSubmit` hook, fires whenever a message is actually submitted) marks the session `working` and clears any previously-recorded blocked state.
- `host-agent/hooks/permission_request.php` (Claude Code's `PermissionRequest` hook, fires when a permission prompt is shown) records the blocked state: `permission_mode` from Claude Code's status line, and `blocked_since` timestamp. This is what triggers "⚠️ blocked on input" on the dashboard.
- `host-agent/hooks/stop.php` (Claude Code's `Stop` hook, fires when a session is stopped/killed) clears the blocked state, so the dashboard knows the session is no longer waiting.

Together, these three hooks own the full `blocked` / `working` / `idle` state machine without any pane scraping. If Claude Code's rendered state glyph ever changes, nothing breaks — Sessioneer uses the hooks, not the glyphs.

## Hook Installation and Scope

All hooks are installed at `host-agent/install.sh` time into `~/.claude/hooks/`. They're registered in Claude Code's per-project `.claude.yaml` or global `~/.claude/commands.yaml`.

Hook scope: a plain `claude` session started by hand (no `SESSIONEER_SESSION_NAME` env var) has the hooks installed but is a no-op for all of them. Only Sessioneer-tracked sessions participate.
