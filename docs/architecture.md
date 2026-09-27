# Architecture

## Overview

The web UI runs in a Docker container that **never touches tmux, the host process table, or any other host-local process directly** — it only speaks a small JSON request/response protocol over a UNIX socket to a separate, host-native **agent** (`host-agent/`, installed directly on the host, not containerized).

For tmux-backed sessions (Claude Code's tmux runtime, Antigravity, and OpenCode's tmux fallback), this split exists so the container can never accidentally become the process that spawns tmux's own server (which would put it inside the container's filesystem namespace, unreachable from the host).

For headless runtimes (Codex always, OpenCode and Claude Code by default), the host agent instead proxies to a local server process: `codex app-server`, `opencode serve`, or, for Claude Code, a persistent host-native manager that owns one `claude -p` stream-json process per active session. Either way, everything that has to run in the host's own namespace stays in one place.

**Practically, this means setup has two independent parts:**
- The host agent (native, via systemd `--user`)
- The container (Docker)

The host agent must be installed and running *before* the container starts.

For the full technical details, see the [CONTRIBUTING.md](../CONTRIBUTING.md#architecture-container--host-native-agent) section.

## Major Implementation Decisions

### Container/Host-Agent Split

The container/host-agent split exists for one specific reason: tmux auto-spawns its server as a child of whichever process first talks to an unstarted socket. If the container were that first process, the tmux server (and every session in it) would be born inside the container's own filesystem namespace — unreachable from the host, and pointing at paths that don't exist there.

Keeping all tmux/`/proc` access in a process that's always host-native makes that impossible by construction, not by convention.

### Session State Detection

Session state (blocked/working/idle) comes exclusively from each agent's own structured signal wherever the agent exposes one:
- **Claude Code** uses hooks (tmux runtime) or, for a headless session, the structured events of its own process, written to the session status store by the manager
- **Antigravity** uses hooks for lifecycle/identity and its pane for the actual approval dialog
- **OpenCode** uses its serve API, SQLite, permissions plugin, and (for TUI permissions) its pane
- **Codex** uses the private bridge for Sessioneer-owned turns and hooks for Remote-owned activity

Pane parsing is therefore limited to prompt shapes whose owning agent exposes no authoritative structured equivalent, rather than being a generic activity detector.

### Command Invocation

Every command runs via `proc_open()` with the command as an array, never a shell string. This isn't a hardening pass bolted on after the fact — it's the only way any command in this codebase is ever invoked, which rules out shell metacharacter injection by construction rather than by escaping.

### Frontend Compatibility

`public/js/*.js` is deliberately plain ES5 (no `const`/`let`/arrow functions/template literals) — mobile Safari compatibility issues were the repeated reason, since this is a PWA meant to be added to an iOS/Android home screen.
