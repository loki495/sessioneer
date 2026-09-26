# The Agent Socket Caveat

This document explains the UNIX socket protocol that connects the Docker container (web UI) to the host-native agent, and an important limitation to understand.

## Socket Protocol

Sessioneer's web container and host agent communicate over a UNIX socket (default: `~/.sessioneer/agent.sock`) using a simple JSON request/response protocol. The container sends JSON-encoded requests and the agent responds with JSON results.

**Request shape:**
```json
{
  "method": "list_sessions",
  "params": { ... }
}
```

**Response shape:**
```json
{
  "status": "ok",
  "data": { ... }
}
```

## Important Caveat: One Container Instance Per Machine

The UNIX socket is **host-local and host-only**. This means:

1. **Only one Sessioneer container can run on a machine at a time**, since they all talk to the same host-native agent process.

2. **The host agent and container must be on the same physical machine** — you cannot run the container on one machine and point it at an agent on another machine. There's no network protocol layer; the socket is literally a file on the host's filesystem.

3. **The socket must be bind-mounted into the container** at runtime (`docker-compose.yml` handles this). If the mount is missing or misconfigured, the container will fail to communicate with the agent and show "agent unavailable" on the dashboard.

## Socket Debugging

If the container can't reach the agent:

1. Verify the socket exists: `ls -la ~/.sessioneer/agent.sock`
2. Check the host agent is running: `systemctl --user status sessioneer-agent`
3. Verify the bind-mount in `docker-compose.yml`: should include `- ~/.sessioneer:/root/.sessioneer`
4. Check logs: `docker logs sessioneer-container-name`

If the container and agent are running but still disconnected, the socket file itself may have been corrupted. Delete it and restart the host agent: `rm ~/.sessioneer/agent.sock && systemctl --user restart sessioneer-agent`

## Claude Headless Manager Socket

Headless Claude sessions add a second, separate socket. The persistent `sessioneer-claude-headless-manager.service` listens on `/run/user/<uid>/sessioneer-claude-headless.sock` (override with `CLAUDE_HEADLESS_SOCKET`). Only the host agent talks to it — never the web container — so this socket is not bind-mounted anywhere.

The protocol is one request and one reply per connection, newline-delimited JSON: `{"method": "sessioneer/sendInput", "params": {...}}` answered by `{"ok": true|false, ...}`. Methods: `spawn`, `stop`, `sendInput`, `interrupt`, `pendingPrompt`, `answerPrompt`, `setMode`, `setModel`, `status`, `list`, `health`, all under the `sessioneer/` prefix. Every request re-validates its session against the sidecar store, and a prompt answer must name the request id of the prompt that is pending right now.

If the manager is not running the host agent returns a handled "Cannot reach Claude headless manager" message and tmux sessions are unaffected. The client retries only the connection itself, never a request whose bytes were already written, and read-only probes (the process listing, the health box) do not retry at all so an absent manager costs nothing. Check it with `systemctl --user status sessioneer-claude-headless-manager.service`.
