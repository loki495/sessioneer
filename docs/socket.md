# Sockets

Sessioneer's web container never touches tmux, the process table or an agent directly. It talks to the host over UNIX sockets, and the host-native agent talks to the long-running services the same way. This page covers each socket and what to do when one stops answering.

## The host agent socket

`host-agent/systemd/sessioneer-agent.socket` listens on `%t/sessioneer-agent.sock`, which is `/run/user/<uid>/sessioneer-agent.sock`. systemd starts one `sessioneer-agent@<connection>.service` (running `host-agent/agent.php`) per connection, with the socket as its stdin and stdout, so the agent needs no long-running process of its own.

**Protocol:** the client writes one JSON object, `{"action": "<name>", ...parameters}`, half-closes its write side and reads one JSON reply until end of stream. Replies carry `"ok": true|false` and, on failure, a `"message"`. An empty or malformed request gets `{"ok": false, "message": "Malformed request"}`. `src/lib/AgentClient.php` is the client the web UI uses.

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

The protocol is one request and one reply per connection, newline-delimited JSON: `{"method": "sessioneer/sendInput", "params": {...}}` answered by `{"ok": true|false, ...}`. Methods: `spawn`, `stop`, `sendInput`, `interrupt`, `pendingPrompt`, `answerPrompt`, `setMode`, `setModel`, `status`, `list`, `health`, all under the `sessioneer/` prefix. Every request re-validates its session against the sidecar store, and a prompt answer must name the request id of the prompt that is pending right now.

If the manager is not running, the host agent returns a handled "Cannot reach Claude headless manager" message and tmux sessions are unaffected. The client retries only the connection itself, never a request whose bytes were already written, and read-only probes (the process listing, the health box) do not retry at all, so an absent manager costs nothing. Check it with `systemctl --user status sessioneer-claude-headless-manager.service`. The service loads its code once: restart it after editing it (that stops every headless process; sessions resume on their next message and any open prompt is lost).

## Other services

The Codex bridge listens on `CODEX_BRIDGE_SOCKET` (default `/run/user/<uid>/sessioneer-codex-bridge.sock`) with the same one-request, one-reply JSON shape as the Claude manager. OpenCode is reached over HTTP at `OPENCODE_SERVE_URL` (default `http://localhost:4096`), not a socket.
