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
- `agent_session_id` — the agent's current conversation id (a Claude id changes on `/clear` and fork; the `SessionStart` hook updates it for tmux sessions, the headless manager from each `init` event)
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

## Headless Claude Sessions

A headless Claude session is a sidecar row with `agent = claude` and `runtime = headless`, named `claude-headless-<timestamp>` (the same `<agent>-headless-*` pattern any agent's headless sessions follow) rather than after the Claude UUID, because the UUID changes on `/clear` while the name must stay stable for URLs, status rows and push state. The row carries the account `profile` and the current `agent_session_id`.

The row is the truth and the process is a cache: a session with no running process is "dormant" and the next message starts one with `--resume`. The manager is the only writer of that session's `session_status` row (status, mode, model, blocked prompt, last error, token usage); the hooks never write it. The transcript is still read from Claude's own `~/.claude/projects/<cwd>/<id>.jsonl`.

Claude's account-wide rate-limit windows are kept in the persistent state database (`host-agent/state/push.sqlite`, key `quota_live_state`, plus `quota_live_state:<profile>` per extra account). Two writers feed it through one merge rule: the statusLine script of a tmux session, and the headless manager from its processes' rate-limit events (a headless process renders no status line).
