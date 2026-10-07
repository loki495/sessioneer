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

Sessioneer is a personal, self-hosted, LAN-only application with **no built-in authentication**: whoever can reach it can control your agent sessions. The network binding is the access control (see the README's "Network binding" section). Within that model, every request is checked in layers:

- **Host allowlist:** requests whose `Host` isn't `localhost`, `127.0.0.1`, `::1`, `BIND_ADDR` or an `ALLOWED_HOSTS` entry are refused before any route runs, which blocks DNS rebinding.
- **Same-origin and CSRF:** every state-changing request must be a POST whose `Origin`/`Referer` matches the host, and must carry a session-bound CSRF token.
- **Fresh revalidation:** session names, prompt options and paths are re-checked against fresh state on each request; directory browsing is confined to the configured home root.
- **No shell strings:** the host agent runs every command as an argument array, never through a shell.
- **Container isolation:** the web container has no access to tmux, the host process table or the filesystem beyond its own code. It only talks to the host agent over a UNIX socket.

## Scope

Reports are especially welcome for anything that lets a request from outside the intended LAN scope reach the app, trigger session control, or read transcripts: CSRF, same-origin or Host-check bypasses, path or session-name traversal, and command injection in the host agent.

Known and accepted, given the model above:

- Anyone who can reach the app on the network can use it fully. Put an authenticated reverse proxy in front if that's not acceptable.
- The app may be framed (so it can be embedded in a dashboard). A hostile page on a host you allowed could therefore try clickjacking; this only matters if you allow-list hosts you don't control.
