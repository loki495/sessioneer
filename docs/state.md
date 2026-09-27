# State storage

Conversations live in each agent's own transcript files. Sessioneer itself keeps only small SQLite stores about *which sessions it tracks* and their live status, plus one persistent store for push and quota state. There is no user table, no archive table and no retention setting: an "archived" session is just a transcript Sessioneer is not currently tracking, and killing a session deletes its rows.

## The two SQLite files

| File | Default location | Lifetime | Tables |
| --- | --- | --- | --- |
| `sessions.sqlite` | `$SIDECAR_DIR/sessions.sqlite` (`SESSIONS_SQLITE_FILE` overrides; `SIDECAR_DIR` defaults to `/run/user/<uid>/sessioneer-sessions`) | tmpfs, cleared on reboot (the tracked processes died with the reboot anyway) | `sidecars`, `session_status`, `pending_tools` |
| `push.sqlite` | `host-agent/state/push.sqlite` (`PUSH_SQLITE_FILE` overrides) | persistent (a phone's push subscription must survive a reboot) | `push_subscriptions`, `push_session_state`, `push_quota_state`, `global_state` |

Both are opened with WAL mode by one connection per request; the schemas live in `host-agent/lib/Stores/SqliteDb.php`, and columns added later are migrated in with `ALTER TABLE ... ADD COLUMN` on first use.

## `sessions.sqlite`

**`sidecars`** - one row per tracked session, its identity:

| Column | Meaning |
| --- | --- |
| `session_name` (primary key) | the session's stable ref: `cc-*`, `ag-*`, `oc-*` for tmux sessions, `claude-headless-*` for headless Claude, or the native id (`ses_*`, a Codex thread id) |
| `workdir`, `spawned_at`, `spawned_by_app` | where and when it was started, and whether Sessioneer started it |
| `agent` | `claude`, `antigravity`, `opencode` or `codex` |
| `agent_session_id` | the agent's current conversation id. A Claude id changes on `/clear` and on a fork: the `SessionStart` hook re-points it for tmux sessions, the headless manager from each `init` event |
| `runtime` | `headless`, or empty for tmux |
| `profile` | the Claude account (a `host-agent/config/agents.php` profile) the session runs under; empty is the default account |
| `title` | a title the agent or a sync supplied |

**`session_status`** - one row per tracked session, its live state: `status` (`idle`, `working`, `blocked`), `blocked_json` (the pending prompt), `mode`, `model`, `last_message`, `last_turn_error`, `token_usage_json`, `updated_at`.

**`pending_tools`** - the exact, untruncated tool call a tmux session is about to run, written by the `PreToolUse` hook so a permission prompt can show it in full.

## `push.sqlite`

- `push_subscriptions`, `push_session_state` (each session's last `idle`/`working`/`blocked` state and since when, for the "finished" and "needs input" notifications) and `push_quota_state` (which quota notifications already fired for each bucket).
- `global_state` - a key/JSON store for single-blob state: `quota_live_state` and `quota_live_state:<profile>` (Claude's rate-limit windows), `antigravity_quota_live_state`, `push_check_status`, `push_quota_check_status`, `headless_sessions_sync`, `codex_headless_sessions_sync`, `opencode_models`, `claude_models_seen` (the newest raw model id read per Claude family, which labels the model dropdown), and `tui_layout_mismatch`.

## Who writes the status

- **Claude in tmux, Antigravity, Codex hooks:** the agent's hooks (`SessionStart`, `UserPromptSubmit`, `PreToolUse`, `PermissionRequest`, `Stop`) fire immediately and update `session_status`; see [hooks](hooks.md).
- **Claude headless:** only the manager writes it (below).
- **OpenCode and Codex:** the `opencode serve` sync and events service, and the Codex bridge.

A throttled listing cache (`$CACHE_DIR/session_list.json`, about 0.9 s) coalesces concurrent dashboard polls, and a per-conversation `<sha1>.resume-lock` file in the sidecar directory keeps two resumes of one conversation from racing.

**Kill inactive** removes sessions idle for longer than `CLEANUP_THRESHOLD_SECONDS` (default 12 hours).

## Headless Claude sessions

A headless Claude session is a sidecar row with `agent = claude` and `runtime = headless`, named `claude-headless-<timestamp>` (the same `<agent>-headless-*` pattern any agent's headless sessions follow) rather than after the Claude UUID, because the UUID changes on `/clear` while the name must stay stable for URLs, status rows and push state. The row carries the account `profile` and the current `agent_session_id`.

The row is the truth and the process is a cache: a session with no running process is "dormant" and the next message starts one with `--resume`. The manager is the only writer of that session's `session_status` row; the hooks never write it. The transcript is still read from Claude's own `~/.claude/projects/<cwd>/<id>.jsonl`.

Claude's account-wide rate-limit windows are kept in `global_state` (`quota_live_state`, plus `quota_live_state:<profile>` per extra account). Two writers feed it through one merge rule: the statusLine script of a tmux session, and the headless manager from its processes' rate-limit events (a headless process renders no status line).
