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
