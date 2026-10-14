# Security Policy

This app can create and kill coding-agent sessions, approve their tool calls, and show their transcripts and prompts, so please report security issues privately rather than opening a public issue.

## Reporting a Vulnerability

Use GitHub's private vulnerability reporting:

1. Go to the [Security tab](https://github.com/loki495/sessioneer/security) of this repository.
2. Click **Report a vulnerability**.
3. Include as much detail as you can: steps to reproduce, affected version/commit, and potential impact.

You should receive an acknowledgement within a few days. Please don't disclose the issue publicly until it's been addressed.

## Supported versions

Sessioneer is early-access software. Fixes land on `master` and in the next release; older releases aren't patched.

## Security model

Sessioneer is a personal, self-hosted, LAN-only application with **no dashboard login**: whoever can reach the dashboard can control your agent sessions. The optional Quick Prompt endpoint additionally requires a dedicated bearer token. The network binding is the access control (see the README's "Network binding" section). Within that model, every request is checked in layers:

- **Host allowlist:** requests whose `Host` isn't `localhost`, `127.0.0.1`, `::1`, `BIND_ADDR` or an `ALLOWED_HOSTS` entry are refused before any route runs, which blocks DNS rebinding.
- **Same-origin and CSRF:** browser state-changing requests must be a POST whose `Origin`/`Referer`, when present, matches the host (a request with neither still needs the CSRF token), and must carry a session-bound CSRF token.
- **Quick Prompt:** `POST /api/quick-prompt` uses a dedicated environment-provided bearer token, compared with `hash_equals` over fixed-length hashes; it fails closed when unconfigured. Cookie/CSRF authentication is not used for this route. Origin submissions are rejected, JSON input is size-limited, and authenticated requests share an atomic rate limit. Projects are selected only through host-configured aliases; modes are Claude auto (default) or plan, or Codex read-only; approvals are never answered by the endpoint, and Claude auto mode means Claude's own classifier may approve routine actions in the allowlisted project. The optional Ollama router can only choose among configured values. Error responses include the agent's own failure text (usage limit, login, outage) with paths, secrets, tokens and addresses removed and a length cap. Session links use trusted HTTPS configuration. This token protects only this endpoint, so the broader app must remain private. Ingress must also limit unauthenticated traffic. See [Quick Prompt](docs/quick-prompt.md).
- **Fresh revalidation:** session names, prompt options and paths are re-checked against fresh state on each request; directory browsing (`browse_dir`/`create_dir`) is confined to the configured home root. Creating or resuming a session accepts any absolute directory the caller picks, consistent with the no-login model.
- **No shell strings:** the host agent runs every command as an argument array, never through a shell.
- **Container isolation:** the web container has no access to tmux, the host process table or the host filesystem beyond its bind-mounted code and dedicated web rate-limit state volume. It only talks to the host agent over a UNIX socket.

## Scope

Reports are especially welcome for anything that lets a request from outside the intended LAN scope reach the app, trigger session control, or read transcripts: CSRF, same-origin or Host-check bypasses, path or session-name traversal, and command injection in the host agent.

Known and accepted, given the model above:

- Anyone who can reach the app on the network can use it fully: launch agents in any directory, approve their tool calls and read their transcripts, which amounts to code execution as the host user. Put an authenticated reverse proxy in front if that's not acceptable.
- The app may be framed (so it can be embedded in a dashboard). A hostile page on a host you allowed could therefore try clickjacking; this only matters if you allow-list hosts you don't control.
