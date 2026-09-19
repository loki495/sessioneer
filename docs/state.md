# Per-Session and Global State Storage (SQLite)

Sessioneer stores session metadata, sidecar state, and UI preferences in SQLite. This document covers the data model and persistence strategy.

## Database Location

The SQLite database lives at `~/.sessioneer/data.db` (local machine). The host agent manages reads/writes; the web container queries it over the socket protocol.

## Session State Tables

### `sessions` Table

Tracks active and recently-completed sessions with metadata:

- `id` — primary key
- `name` — session identifier (tmux pane name, headless server ID, etc.)
- `agent_type` — `claude_code`, `antigravity`, `opencode`, `codex`
- `working_directory` — where the session was spawned
- `created_at`, `last_activity` — timestamps
- `current_state` — `idle`, `working`, `blocked`
- `blocked_reason` — if state is `blocked`, why (permission prompt, user input, etc.)
- `context_usage_percent` — live token/context usage (Claude Code only)
- `tmux_pane_id` — if tmux-backed (Claude Code, Antigravity, OpenCode tmux fallback)
- `transcript_path` — path to the agent's transcript file (Claude Code)

### `sidecars` Table

One sidecar file per session, tracking state that Sessioneer maintains (independent of the agent):

- `session_id` (foreign key to `sessions.id`)
- `sidecar_path` — filesystem path to the sidecar JSON file (`~/.sessioneer/sidecars/<session-id>.json`)
- `claude_session_id` — current Claude Code session ID (changes on `/clear`, `/compact`, etc.; `SessionStart` hook updates this)
- `last_synced` — when this was last read/verified

### `ui_preferences` Table

Per-user UI state:

- `key` — setting name (e.g., `sidebar_collapsed`, `theme`)
- `value` — setting value (boolean, string, etc.)

This persists across browser refreshes and session resets.

## Hooks and State Updates

Session state is updated in two ways:

1. **Reactive polling** — the host agent periodically (every few seconds) queries tmux (`tmux list-panes`, `/proc` scanning) to detect state changes
2. **Hook callbacks** — Claude Code hooks (SessionStart, PermissionRequest, UserPromptSubmit, Stop) fire immediately on events, bypassing polling latency

Hooks are preferred wherever available since they're instant; polling is the fallback for agents without hook support (Codex, OpenCode headless mode).

## State Persistence Across Restarts

Sessioneer persists session metadata even after the container restarts. Sessions that were `working` or `blocked` retain their state. Sessions marked `idle` for more than a configured threshold (default: 1 hour) are archived and hidden from the active session list but remain queryable in history.

## Cleanup and Archival

Old sessions are archived automatically. The threshold and archive behavior are configurable in `.env`:

- `SESSION_IDLE_THRESHOLD` — how long (in seconds) a session can be idle before archival (default: 3600)
- `SESSION_RETENTION_DAYS` — how many days to keep archived sessions (default: 30)

Archived sessions can still be queried and resumed if needed.
