# Sockets

Sessioneer's web container never touches tmux, the process table or an agent directly. It talks to the host over UNIX sockets, and the host-native agent talks to the long-running services the same way. This page covers each socket and what to do when one stops answering.

## The host agent socket

`host-agent/systemd/sessioneer-agent.socket` listens on `%t/sessioneer-agent.sock`, which is `/run/user/<uid>/sessioneer-agent.sock` (`$XDG_RUNTIME_DIR/sessioneer-agent.sock` where that is set, as `bin/sessioneer` assumes). The socket is created with `SocketMode=0660` and the `SocketGroup` the installer writes into the unit (the installing user's primary group); those file permissions are the only access control on it. systemd starts one `sessioneer-agent@<connection>.service` (running `host-agent/agent.php`) per connection, with the socket as its stdin and stdout, so the agent needs no long-running process of its own.

**Protocol:** the client writes one JSON object, `{"action": "<name>", ...parameters}`, half-closes its write side and reads one JSON reply until end of stream. Replies carry `"ok": true|false` and, on failure, a `"message"`. An empty or malformed request gets `{"ok": false, "message": "Malformed request"}`. `src/lib/AgentClient.php` is the client the web UI uses; `bin/sessioneer` ([cli.md](cli.md)) is a second client, sending the `launch`, `launch_kill`, `launch_list`, `launch_send` and `launch_peers` actions to start, resume, reuse, stop, list or message sessions (`launch` accepts an optional `effort`: a Claude level, or one the Codex model lists), and (with `--usage`) the existing `quota` action to read the last known usage stats.

The web container gets the socket through a bind mount in `docker-compose.yml` (`${SESSIONEER_AGENT_SOCKET_HOST:-/run/user/1000/sessioneer-agent.sock}` to `/run/sessioneer-agent.sock`, with `SESSIONEER_AGENT_SOCKET` pointing at the container path). The socket is host-local:

1. Only one Sessioneer container can talk to a host's agent, and the container and agent must be on the same machine. There is no network layer.
2. The mount is of the socket **file**. If the socket unit is stopped and started, systemd creates a new file, and a container that is already running keeps the old, dead one until it is restarted.

### When the container cannot reach the agent

```bash
systemctl --user status sessioneer-agent.socket           # is it listening?
stat -c %i /run/user/$(id -u)/sessioneer-agent.sock       # host inode
docker exec sessioneer-app stat -c %i /run/sessioneer-agent.sock   # container inode
```

- Different inodes: the container holds a stale socket. Run `docker restart sessioneer-app`.
- The socket unit is inactive: `systemctl --user restart sessioneer-agent.socket`, then the container restart above.
- Unit files missing or unlinked (for example after moving dotfiles or systemd files around): restore them, then `systemctl --user daemon-reload` and start the socket.
- `systemctl --user list-units --state=failed` shows `sessioneer-agent@...` instances: each is one request whose client hung up before the reply was written (exit 255, a broken pipe in `agent.php`). One-off ones are harmless; a steady stream means something is connecting and closing without sending a request. Clear them with `systemctl --user reset-failed 'sessioneer-agent@*'`.

## Claude headless manager socket

Headless Claude sessions add a second, separate socket. The persistent `sessioneer-claude-headless-manager.service` listens on `/run/user/<uid>/sessioneer-claude-headless.sock` (override with `CLAUDE_HEADLESS_SOCKET`). Only the host agent talks to it, never the web container, so this socket is not bind-mounted anywhere.

The protocol is one request and one reply per connection, newline-delimited JSON: `{"method": "sessioneer/sendInput", "params": {...}}` answered by `{"ok": true|false, ...}`. Methods: `spawn`, `stop`, `sendInput`, `interrupt`, `pendingPrompt`, `answerPrompt`, `setMode`, `setModel`, `setEffort`, `status`, `list`, `health`, all under the `sessioneer/` prefix. Every request re-validates its session against the sidecar store, and a prompt answer must name the request id of the prompt that is pending right now.

`sessioneer/setEffort` accepts `session` and an `effort` string (`low`, `medium`, `high`, `xhigh`, `max`, or empty to clear the override). A live child receives session-scoped `apply_flag_settings`; only a successful acknowledgement persists the choice. A dormant session saves it without spawning. `sessioneer/status` with `include_effort: true` adds `current_effort`, `effort_available` and `effort_message`, using the live CLI's `get_settings.applied.effort`. Private effective settings and source paths never leave the manager. Ordinary status probes remain immediate and unchanged. The web's CSRF-guarded `POST /session_effort.php` reaches the host-agent `set_effort` action through the runtime interface.

If the manager is not running, the host agent returns a handled "Cannot reach Claude headless manager" message and tmux sessions are unaffected. The client retries only the connection itself, never a request whose bytes were already written, and read-only probes (the process listing, the health box) do not retry at all, so an absent manager costs nothing. Check it with `systemctl --user status sessioneer-claude-headless-manager.service`. The service loads its code once: restart it after editing it (that stops every headless process; sessions resume on their next message and any open prompt is lost).

## Other services

The Codex bridge listens on `CODEX_BRIDGE_SOCKET` (default `/run/user/<uid>/sessioneer-codex-bridge.sock`) with the same one-request, one-reply JSON shape as the Claude manager. OpenCode is reached over HTTP at `OPENCODE_SERVE_URL` (default `http://127.0.0.1:8889`), not a socket; it needs HTTP Basic auth (`opencode` plus the password in `~/.config/opencode/service.json`, overridable with `OPENCODE_PASSWORD`). Ollama is plain HTTP too, at `OLLAMA_URL` (default `http://127.0.0.1:11434`) with no auth at all - which is why it must stay on loopback.

## Quick Prompt actions

`quick_prompt_start` accepts `input` (the validated HTTP JSON schema) and `key` (a SHA-256 idempotency identifier). Host project/default/model restrictions are revalidated here. It creates through existing RuntimeProvider implementations and delivers the first prompt; it returns a fixed public error or `type: session`, `status`, `session_id`, and internal `request_id` / native Codex `turn_id`. `quick_prompt_status` accepts `session_id`, `request_id`, and Codex `turn_id`; only tracked Quick Prompt sessions can be inspected. It reports terminal completion (`completed`, bounded `text`, `has_tools`) from the Claude manager's result snapshot or Codex's completed native turn. `quick_prompt_dispose` accepts `session_id`, `request_id` and the final `response`; for a tracked session that is idle (and only then) it stops and archives it and stores `response` as the idempotent result, unless `QUICK_PROMPT_ARCHIVE_ANSWERS=0`. Failed turns and undeliverable prompts are archived by the start/status actions themselves. No action waits for the agent to finish or approves operations. See [quick-prompt.md](quick-prompt.md).
