# Sessioneer

[![CI](https://github.com/loki495/sessioneer/actions/workflows/ci.yml/badge.svg)](https://github.com/loki495/sessioneer/actions/workflows/ci.yml)

A self-hosted, LAN-only web UI for managing coding-agent sessions - Claude
Code and Antigravity (`cc-*`/`ag-*`, tmux-driven), OpenCode (native `ses_*`
IDs through its headless server by default, `oc-*` tmux as a fallback), and
Codex (native thread UUIDs, headless only, no tmux at all) - on your own dev
box. See blocked prompts, answer
them, send messages, view transcripts, and kill sessions, all from a phone
or any browser on your network. No accounts, no login - see "Network
binding" below for how access is actually controlled.

> **Note on scope**: this project's primary author runs it as a personal
> tool on their own machine, so day-to-day development is driven by that
> use case. It's shared here as a working example and a starting point for
> your own setup, not as a polished, actively-triaged public product -
> issues and PRs are welcome (see [CONTRIBUTING.md](CONTRIBUTING.md)), but
> treat it as "read the code, adapt it" rather than "install and forget."

## Screenshots

![Dashboard listing active sessions](docs/screenshots/dashboard.png)

The dashboard lists tracked Claude Code, Codex, OpenCode, and Antigravity
sessions currently running on your box, using tmux names or each headless
agent's native session ID as appropriate.

A session waiting on a tool-permission approval, with real Approve/Deny
buttons right in the browser:

![A session showing a blocked tool-permission prompt with Approve/Deny buttons](docs/screenshots/blocked-prompt.png)

The dashboard and a blocked prompt, on a phone-sized viewport (this app is
an installable PWA, meant to be added to an iOS/Android home screen):

<img src="docs/screenshots/mobile-dashboard.png" alt="Dashboard on a mobile viewport" width="360">
<img src="docs/screenshots/mobile-blocked-prompt.png" alt="Blocked prompt on a mobile viewport" width="360">

## What it does

> For the exhaustive capability list and the per-agent coverage matrix
> (which agents support which feature, and where parity is partial), see
> [`docs/features.md`](docs/features.md).

- **Session list**: name/title, working directory, relative last-active
  time, attached/detached (tmux-backed sessions), live context-usage
  percentage, and flow state (`idle` / `working` / `blocked`).
- **Blocked-on-input warning**: if a session needs a human decision (folder
  trust on first launch in a new directory, a tool-permission approval, a
  question, ...), the row shows what it's waiting on, with real
  Approve/Deny-style buttons to answer it right from the browser - or a
  copy-pasteable `tmux attach` command if you'd rather answer by hand
  (tmux-backed agents only). Detection is per-agent: Claude Code primarily
  uses hooks, Antigravity combines lifecycle hooks with its live pane,
  OpenCode combines its serve API/plugin with its live pane when using the
  TUI, and Codex combines its private bridge with Codex hooks. A prompt owned
  by Codex Remote is visible here, but must be answered in Codex Remote; see
  [Codex: shared threads and ownership](#codex-shared-threads-and-ownership).
- **Session transcript view**: scroll a session's real conversation history
  - user/assistant messages, tool calls and their outputs (grouped into
  collapsible "N tool calls" runs so a long tool-heavy stretch doesn't
  spam the page), subagent calls/reports, plan presentations - with live
  polling for new messages while you watch, and a compose box to send a
  new message or answer a prompt without attaching to tmux at all. Every
  message and tool call/output has a Copy button.
- **Search**: a dashboard-wide search box finds a session by anything
  actually said inside it (not just its title), across live and archived
  transcripts alike; a per-session search box does the same within just
  that conversation, including older history you haven't scrolled to yet.
  Either one jumps straight to the matching point in the transcript.
  Dashboard-wide search currently covers Claude Code and OpenCode. See
  `docs/features.md` for the remaining per-agent gaps.
- Also lists any other real `claude` process found on the host that isn't
  inside a session this tool already tracks (started by hand in a plain
  terminal, for example) - killable, and adoptable into a tracked session
  via "Take over." (Claude Code specifically - not yet extended to the
  other three agents.)
- **New Session**: pick an agent, a working directory (a browsable folder
  picker rooted at your configured project directory), plus a model and
  starting mode where that agent supports them, and it starts a new tracked
  session there - tmux-backed or headless, whichever that agent uses.
- **Archived sessions**: browse and resume past, no-longer-running sessions
  an agent still has a transcript for, read-only until resumed. Transcript,
  working-directory, title, and resume routing support all four agents.
- No required auto-refresh - polls while a tab is visible, pauses in the
  background; a manual Refresh button always works too.
- **Usage quota footer**: per-agent session/weekly usage percentages and
  reset countdowns - read from whatever each agent already exposes (Claude
  Code's statusLine JSON via a small marker this app appends to your
  statusLine script, Antigravity's `/usage` endpoint, OpenCode's own local
  SQLite/usage endpoint, and Codex app-server rate limits) - no external
  screen scraper needed.
- **Web Push notifications**: get notified on your phone when a session
  needs input or finishes a long task, even with the tab closed - see "Web
  Push notifications" below.
- **Worker-session tagging**: subagent/worker sessions spawned by another
  session are tagged with their lineage and hidden from the main list by
  default (a "Show worker sessions" toggle reveals them).

## Architecture, in short

The web UI runs in a Docker container that **never touches tmux, the host
process table, or any other host-local process directly** - it only speaks
a small JSON request/response protocol over a UNIX socket to a separate,
host-native **agent** (`host-agent/`, installed directly on the host, not
containerized). For tmux-backed agents (Claude Code, Antigravity, and
OpenCode's tmux fallback), this split exists so the container can never
accidentally become the process that spawns tmux's own server (which would
put it inside the container's filesystem namespace, unreachable from the
host). For headless agents (Codex always, OpenCode by default), the host
agent instead proxies to that agent's own local server process
(`codex app-server`, `opencode serve`) - either way, everything that has to
run in the host's own namespace stays in one place. See
[CONTRIBUTING.md](CONTRIBUTING.md) for the full story and the rest of the
architecture.

Practically, this means **setup has two independent parts**: the host
agent (native, via systemd `--user`) and the container (Docker). The host
agent must be installed and running *before* the container starts.

## Major implementation decisions

- **Container/host-agent split exists for one specific reason**: tmux auto-spawns its
  server as a child of whichever process first talks to an unstarted socket. If the
  container were that first process, the tmux server (and every session in it) would be
  born inside the container's own filesystem namespace — unreachable from the host, and
  pointing at paths that don't exist there. Keeping all tmux/`/proc` access in a process
  that's always host-native makes that impossible by construction, not by convention.
- **Session state (blocked/working/idle) comes exclusively from each agent's own
  structured signal wherever the agent exposes one** — Claude Code uses hooks;
  Antigravity uses hooks for lifecycle/identity and its pane for the actual approval
  dialog; OpenCode uses its serve API, SQLite, permissions plugin, and (for TUI
  permissions) its pane; Codex uses the private bridge for Sessioneer-owned turns and
  hooks for Remote-owned activity. Pane parsing is therefore limited to prompt shapes
  whose owning agent exposes no authoritative structured equivalent, rather than being
  a generic activity detector.
- **Every command runs via `proc_open()` with the command as an array, never a shell
  string** — this isn't a hardening pass bolted on after the fact, it's the only way any
  command in this codebase is ever invoked, which rules out shell metacharacter injection
  by construction rather than by escaping.
- **`public/js/*.js` is deliberately plain ES5** (no `const`/`let`/arrow functions/template
  literals) — mobile Safari compatibility issues were the repeated reason, since this is a
  PWA meant to be added to an iOS/Android home screen.

## Requirements

- Linux, PHP 8.1+ (with the `pdo_sqlite` extension enabled - `php -m | grep
  sqlite`), Composer, and Docker/Docker Compose.
- `tmux`, if you're using Claude Code and/or Antigravity (both are always
  tmux-driven), or want OpenCode's tmux fallback. Not needed for a
  Codex-only, or OpenCode-headless-only, setup.
- systemd with user services (`systemd --user`) for the host agent.
- At least one of the CLIs you actually plan to manage: [Claude
  Code](https://claude.com/claude-code), [Codex](https://github.com/openai/codex),
  [OpenCode](https://opencode.ai), or Antigravity's `agy`. This tool manages
  sessions for whichever of these you have installed - it does not install the
  agent CLIs themselves.

## Setup

**Order matters: install and start the host agent *before* starting the
container.** Docker bind-mounts a source path that doesn't exist yet as an
empty directory, so if the container starts first, the agent socket path
inside the container will silently be a directory instead of the real
socket, and everything will fail with "Cannot reach host agent."

1. Install the host agent (runs natively via systemd `--user`, needs no
   containers):
   ```
   ./host-agent/install.sh
   ```
   This installs Composer dependencies if needed, copies `host-agent/
   .env.example` to `host-agent/.env` if you don't already have one, and
   installs + enables the systemd socket unit (the push-notification timer
   is also installed here but deliberately left disabled - see "Web Push
   notifications" below). Run `which <cli>` for whichever agent(s) you want
   to manage and put the result(s) in
   `host-agent/.env` (see `host-agent/.env.example` for the full list and
   which are required vs. opt-in per agent).

   Verify the socket exists and is a socket (`s` in `ls -la`), not a
   directory:
   ```
   ls -la $XDG_RUNTIME_DIR/sessioneer-agent.sock
   ```
   Lingering must be enabled for the socket to survive logout/reboot
   without an active login session - `install.sh` checks this and prints
   the fix if not.

2. `cp .env.example .env` and fill in:
   - `APP_GID` - must match the group the installer set on the agent
     socket (`install.sh` prints the exact gid to use at the end of its
     output). `APP_UID` doesn't need to match a specific host user - the
     container never touches the host filesystem or tmux directly, only
     the agent socket.
   - `SESSIONEER_AGENT_SOCKET_HOST` - path to the real socket from step 1,
     normally `/run/user/<your-uid>/sessioneer-agent.sock`.
   - `BIND_ADDR` / `APP_PORT` - see "Network binding" below.

3. Build and start the container:
   ```
   docker compose up -d --build
   ```
   Leave it running. Only re-run this if you change `docker-compose.yml`
   itself; plain PHP/JS edits under `src/`/`public/` never need a rebuild
   (see [CONTRIBUTING.md](CONTRIBUTING.md)).

4. Visit `http://<BIND_ADDR>:<APP_PORT>/`.

5. The dashboard's health box reports common prerequisites plus detailed
   Claude Code, OpenCode, and Codex integration checks. Its **Install hooks**
   button safely merges Sessioneer's Claude Code and Codex hooks into the
   existing files; unrelated hooks remain in place. Codex requires one extra
   trust step described below. OpenCode's plugin and both headless services
   are installed by `host-agent/install.sh`. Antigravity's global hooks are a
   separate opt-in because they apply to every `agy` invocation on the account.

## Agent-specific setup and behavior

The common install above is enough to run the UI, but each agent obtains
identity, status, transcripts, prompts, and writes differently. These details
matter when diagnosing an idle-looking session or a prompt that cannot be
answered in Sessioneer.

| Agent | Runtime | Status / prompts | Extra setup |
|---|---|---|---|
| Claude Code | tmux only | Claude hooks, with narrow pane fallbacks for folder trust and `AskUserQuestion` UI state | Click **Install hooks** |
| Antigravity | tmux only | Hooks provide lifecycle and conversation identity; the live pane identifies approval dialogs | Install its global hooks; optionally enable its quota timer |
| OpenCode | `opencode serve` by default; tmux fallback | Serve API + SQLite + global permissions plugin; tmux permissions also consult the live pane | Re-run `host-agent/install.sh` after setting `OPENCODE_BIN` |
| Codex | headless only | Private app-server bridge for locally-owned startup; persistent queue + Codex hooks for shared/Remote-owned threads | Click **Install hooks**, trust them in Codex, and bootstrap Remote control when sharing with Codex Remote |

### Claude Code

Sessioneer starts `cc-*` sessions in tmux. Five hooks in
`~/.claude/settings.json` (`SessionStart`, `PreToolUse`,
`PermissionRequest`, `UserPromptSubmit`, and `Stop`) maintain transcript
identity, flow state, permission details, mode, and the last response. The
installer merges only Sessioneer's entries and refuses to overwrite malformed
JSON. Already-running Claude sessions may need to be resumed or restarted
before newly-installed hooks take effect.

Permission approvals, free-text questions, multi-question
`AskUserQuestion`, message sending, escape, model/mode switching, and manual
`tmux attach` remain available. Folder trust and the currently-visible tab of
an `AskUserQuestion` are the deliberate pane-based exceptions because Claude's
hook payload does not contain enough UI state. Bare-process discovery and
**Take over** are currently Claude-only.

Claude quota data is captured when Claude renders its configured status line;
it can show unavailable until at least one session has rendered that line.

**Multiple accounts:** to run sessions under more than one Claude account
(e.g. a separate work login), add named profiles to
`host-agent/config/agents.php`, each with its own `config_dir` (the account's
`CLAUDE_CONFIG_DIR`) and optionally its own `bin`. Once configured, the New
Session form shows an Account selector; each configured account's hooks and
status-line quota capture need installing into that account's own
`CLAUDE_CONFIG_DIR`/`settings.json` the same way as the default account
above. A session's account is shown as a small badge on its row, header, and
archived view, and the quota footer shows one row per configured account.

### Antigravity

Sessioneer starts `ag-*` sessions in tmux. Install the four global hooks
(`PreToolUse`, `PostToolUse`, `PreInvocation`, and `Stop`) from the repository
root with:

```bash
php -r 'require "vendor/autoload.php"; print_r(\HostAgent\Services\AntigravityHookService::install_session_hook());'
```

They are merged under the `sessioneer` group in
`~/.gemini/config/hooks.json`, leaving other groups untouched. Because this is
a global Antigravity configuration, the hooks fire for every `agy` process;
the scripts use `SESSIONEER_SESSION_NAME` to make non-Sessioneer sessions a
no-op. Reopen existing sessions after installing or changing them.

The hooks provide working/idle state, tool metadata, and reactive binding to
Antigravity's real conversation ID. Antigravity does not expose an authoritative
"approval is currently visible" event, so Sessioneer confirms and answers the
actual approval dialog through the tmux pane. Model switching changes
Antigravity's account-wide default, not only the current session. The optional
quota poller is installed but disabled by default:

```bash
systemctl --user enable --now sessioneer-antigravity-quota-check.timer
```

### OpenCode

With `OPENCODE_BIN` configured, `host-agent/install.sh` installs and enables
`opencode-serve.service` and `sessioneer-opencode-events.service`, and copies
`host-agent/opencode-plugins/sessioneer-permissions.js` to
`~/.config/opencode/plugins/`. Restart the server and any already-running TUI
sessions after installing or updating the plugin:

```bash
systemctl --user restart opencode-serve.service
```

Headless sessions are the preferred path: Sessioneer talks directly to the
serve API for lifecycle, messages, questions, and permissions. The tmux TUI is
retained as a fallback. OpenCode creates its `ses_*` ID only after the first
prompt; Sessioneer binds it reactively from `opencode.db`. The permissions
plugin records `permission.asked` events because the tested OpenCode version
does not expose reliable persistent permission state through its HTTP API.
The dashboard health section checks both the service and plugin file.

The events service listens to each tracked headless session's exact OpenCode
directory and records question prompts for Sessioneer's existing answer forms.
It reconnects independently of page visits; normal status polling preserves
pending event-fed questions. Question replies use their server request ID,
with a scoped legacy fallback where the v2 reply endpoint is unavailable.
Check the consumer with `systemctl --user status sessioneer-opencode-events.service`
and `journalctl --user -u sessioneer-opencode-events.service`.

OpenCode supports model selection but not Sessioneer's Claude-style
manual/accept-edits/plan mode vocabulary. Its quota display combines local
SQLite usage with the OpenCode Go usage endpoint when configured.

### Codex: shared threads and ownership

Sessioneer never puts Codex in tmux. `host-agent/install.sh` installs and
enables `sessioneer-codex-bridge.service`, which owns a private, persistent
`codex app-server --stdio` connection for thread creation, the first turn of
an unmaterialized thread, bridge-owned prompt responses, model/effort settings,
and lifecycle operations.
Check or restart it with:

```bash
systemctl --user status sessioneer-codex-bridge.service
systemctl --user restart sessioneer-codex-bridge.service
```

For a thread that already has a persisted rollout, the compose box sends via
Codex's persistent `codex queue --thread ... --message ...` path. That makes a
thread writable from Sessioneer even when Codex Remote started it or currently
has it loaded. Queue writes are FIFO and deferred while a turn is active; they
do not steal the active turn or its prompt ownership. The first message of a
brand-new, not-yet-materialized Sessioneer thread is the one exception and is
sent through the private bridge that created it.

To share threads with Codex Remote, its managed daemon must be running with
remote control enabled:

```bash
codex app-server daemon bootstrap --remote-control
codex app-server daemon version
```

Use the absolute `CODEX_BIN` path here if `codex` is not on your shell's
`PATH`.

Rebootstrapping the managed daemon does not delete persisted Codex sessions,
so they remain resumable, but it can interrupt an active turn. Restarting the
private Sessioneer bridge likewise clears any stale bridge-owned pending prompt
because its response ID died with the old connection.

The **Install hooks** button adds Sessioneer's observer to
`~/.codex/hooks.json` for `SessionStart`, `UserPromptSubmit`,
`PreToolUse(request_user_input)`, `PermissionRequest`, `PostToolUse`, `Stop`,
`Interrupt`, and `SessionEnd`. These hooks are intentionally passive: they
only update Sessioneer's status database and always return a neutral response.
Codex requires new or changed non-managed hooks to be reviewed and trusted in
`/hooks`; changing a hook definition changes its trust hash. Reopen or resume
sessions that were already open when the hook configuration changed. See the
[official Codex hooks documentation](https://developers.openai.com/codex/hooks).

An approval or `request_user_input` prompt belongs to the app-server
connection that created it. Therefore Sessioneer can answer a prompt only when
its private bridge owns the active turn (notably the first turn described
above). A prompt from Codex Remote or from the shared queue/managed transport
is observability-only in Sessioneer: it shows the session as blocked, preserves
useful prompt context, removes answer buttons, and directs you to Codex Remote.
The persistent queue solves cross-owner **messages**, not cross-owner **prompt
responses**.

**Deploying behind a real web server instead of `php -S`**: point its
document root at `public/`, nothing else. Apache: enable `mod_rewrite` and
use `public/.htaccess` as-is. nginx: add the equivalent of
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Configuration (host agent)

`host-agent/lib/Services/Config.php` reads these from the environment (via
`host-agent/.env`, loaded by the systemd unit). None has a universal
default - set the `*_BIN` var(s) for whichever agent(s) you actually use
(see `host-agent/.env.example` for the complete list, including Web
Push-related variables covered below):

| Variable                    | Default                                       | Meaning                                    |
|------------------------------|-----------------------------------------------|---------------------------------------------|
| `CLAUDE_BIN`                 | *(none - required to manage Claude Code)*     | Real `claude` CLI path (`argv[0]` must match) |
| `CODEX_BIN`                  | *(none - required to manage Codex)*           | Real `codex` CLI path                      |
| `CODEX_BRIDGE_SOCKET`        | `/run/user/<uid>/sessioneer-codex-bridge.sock` | Private Sessioneer-to-Codex bridge socket |
| `OPENCODE_BIN`               | *(none - required to manage OpenCode)*        | Real `opencode` CLI path                   |
| `ANTIGRAVITY_BIN`            | *(none - required to manage Antigravity)*     | Real `agy` CLI path                        |
| `WWW_ROOT`                   | `HOME_ROOT`                                   | Starting folder for the New Session browser |
| `HOME_ROOT`                  | your real `$HOME`                             | Upper bound the folder browser can't escape |
| `TMUX_SOCKET`                | `/tmp/tmux-<uid>/default`                     | tmux socket this agent drives (`-S`)        |
| `SIDECAR_DIR`                | `/run/user/<uid>/sessioneer-sessions`         | Per-session workdir/spawned_at metadata, and the local SQLite state (session status, push subscriptions) |
| `CACHE_DIR`                  | `/run/user/<uid>/sessioneer-cache`            | Session-list cache (see below)              |
| `SESSION_LIST_CACHE_TTL_SECONDS` | `0.9`                                     | How long a session-list scan is reused across near-simultaneous callers |
| `HEADLESS_SYNC_SECONDS`      | `15`                                          | Minimum interval between headless-agent syncs |
| `CLEANUP_THRESHOLD_SECONDS`  | `43200` (12h)                                 | Inactivity threshold for "Kill inactive"    |
| `TMUX_PANE_WIDTH` / `_HEIGHT` | `200` / `150`                                | Fixed pane size tmux sessions are created at |
| `SESSIONEER_REPO_ROOT`       | this repo's own checkout path                 | Root the agent resolves its own paths from  |
| `SESSIONS_SQLITE_FILE`       | `<SIDECAR_DIR>/sessions.sqlite`               | Per-session status/state (see below)        |
| `PUSH_SQLITE_FILE`           | `host-agent/state/push.sqlite`                | Web Push subscriptions/state (see below)    |
| `OPENCODE_SERVE_URL`         | `http://localhost:4096`                       | OpenCode server this agent talks to         |
| `OPENCODE_DB_PATH`           | `~/.local/share/opencode/opencode.db`         | OpenCode's own session database             |
| `OPENCODE_AUTH_PATH`         | `~/.local/share/opencode/auth.json`           | OpenCode's own auth file                    |
| `OPENCODE_PERMISSION_DIR`    | `<SIDECAR_DIR>/opencode-permissions`          | Where OpenCode permission-request state is written |

## Network binding (read this)

There is **no login** - the network binding *is* the access control. This
app is intentionally **not** meant to be reachable from the public
internet - it can create and kill agent sessions on your machine.

- `docker-compose.yml` publishes the port as
  `"${BIND_ADDR}:${APP_PORT}:80"`. Set `BIND_ADDR` in `.env` to your
  machine's actual **LAN IP** (e.g. `192.168.1.50`), never `0.0.0.0`. Leave
  it at the default `127.0.0.1` if you only want to reach it from the host
  itself (e.g. via an SSH tunnel).
- If you put a reverse proxy in front of it, make sure **that proxy** is
  also LAN-only - it's easy to have this app's own bind address correctly
  restricted while an existing shared reverse proxy in front of it is
  bound more broadly, silently exposing this app anyway.
- Consider a host firewall rule (`iptables`/`ufw`/`nftables`) restricting
  inbound `APP_PORT` to your LAN subnet as defense in depth.

## Home screen bookmark

On a phone on the same LAN, open `http://<BIND_ADDR>:<APP_PORT>/` (or your
own HTTPS URL if you've set one up - required for Web Push, see below) and
use "Add to Home Screen" (Safari: Share → Add to Home Screen; Chrome: ⋮
menu → Add to Home Screen).

## Web Push notifications

Lets a session's newly-blocked prompt reach your phone without the tab
open and polling. Off by default on a fresh checkout (no VAPID keys, no
timer running) - every piece is a harmless no-op until you opt in.

0. **Requires HTTPS - a hard platform requirement, not optional.** Service
   Workers (what Web Push is built on) are blocked entirely by the browser
   outside a secure context - plain `http://` never works for this
   specifically, silently (no error, the "Enable notifications" button
   just never appears). You need some way to serve this app over HTTPS
   with a certificate your phone trusts - a reverse proxy (Traefik, Caddy,
   nginx) with either a real certificate (Let's Encrypt, if this is
   reachable enough for that - unlikely for a LAN-only app) or a
   self-signed one you manually trust on your device works.

   If you go the self-signed route on iOS specifically: a "proceed
   anyway" click through Safari's own warning page does **not** carry over
   to a home-screen-installed app's separate WebView context - you need to
   actually install the certificate as a trusted profile (AirDrop/email
   yourself the `.crt`, not the `.key`; Settings will offer "Install
   Profile"; then separately enable full trust for it under Settings →
   General → About → Certificate Trust Settings). Keep the cert's validity
   under 398 days if you want it to be trustable via iOS's Certificate
   Trust Settings at all - Apple caps it there, and a longer-lived cert
   will silently never be trustable that way.

1. **Generate a VAPID keypair** (one-time, on the host):
   ```
   php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"
   ```
2. Put the two resulting keys, plus a contact address, in `host-agent/.env`:
   ```
   VAPID_PUBLIC_KEY=...
   VAPID_PRIVATE_KEY=...
   VAPID_SUBJECT=mailto:you@example.com
   ```
3. Reload the page in the browser you want notifications on and tap
   "Enable notifications" (only appears once the keys above are set).
   Requires the site to already be added to the home screen first - iOS
   Safari only exposes the Push API to a home-screen-launched PWA, not a
   regular browser tab.
4. Enable the timer that actually checks for newly-blocked sessions and
   sends the pushes (installed but left disabled by `install.sh`, since
   starting a new recurring background service deserves a deliberate
   opt-in):
   ```
   systemctl --user enable --now sessioneer-push-check.timer
   ```

**iOS's subscription lifecycle is flaky, by design of the platform, not a
bug here**: a subscription can silently die after roughly 1-2 weeks. The
frontend's own mitigation: every page load with an existing subscription
silently re-POSTs it to stay fresh, so simply opening the app periodically
self-heals a subscription that's started to go stale.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full push-delivery
mechanism, notification-content details, and the quota-push variant.

## Running tests

```
bash tests/run.sh          # run every test file
bash tests/run.sh --bail   # stop at the first failing test file
bash tests/run.sh --no-browser  # skip Chrome-dependent tests
```

No Composer test runner needed - plain PHP scripts driven by a bash
entrypoint. The suite is fully isolated from your real tmux
sessions/processes (a separate tmux server, a fixture `claude` binary,
never the real billable CLI). The normal run includes headless-browser tests
when Chrome/Chromium is available; use `--no-browser` on a host without one.
See [CONTRIBUTING.md](CONTRIBUTING.md) for the isolation mechanism and what
each test file covers.

## Security summary

- No login, no accounts - the network binding is the access control (see
  "Network binding" above).
- No free-text fields except the optional custom working-directory path
  for New Session, which is passed as a `proc_open()` array argument
  (never through a shell) and only ever used as a spawn-target directory -
  it can change *where* a session starts, not *what* command runs.
- Every state-changing POST is guarded twice: a same-origin check
  (`Origin`/`Referer` vs `Host`) and a session-bound CSRF token, checked
  with `hash_equals()`.
- Every session name/pid the app can act on is re-validated against a
  fresh listing computed in the same request, never trusted from the
  client.
- The container has no access to the host filesystem, tmux, or the
  process table - only a single UNIX socket to the host agent, gated
  further by UNIX socket permissions.
- No user database, no multi-tenant data - SQLite is used only for local
  host-agent state (session status, push subscriptions, quota cache; see
  the Configuration table above), plus a PHP session (a CSRF token and the
  next flash message) and small JSON sidecar files recording which working
  directory a session was started with.

See [CONTRIBUTING.md](CONTRIBUTING.md) for how commands are actually
built/run (the `proc_open()` array-form convention that rules out shell
injection entirely) and the full architecture.

## Current limitations

- No accounts, no multi-user support — this is a single-operator tool for one person's
  own dev box, gated by network binding, not a login (see "Network binding" above).
- Only manages sessions on the same host the container and host agent run on — no
  remote/multi-host session management.
- Feature parity across agents (Claude Code, Antigravity, OpenCode, Codex) is partial —
  see [`docs/features.md`](docs/features.md) for the exact per-agent coverage matrix.
- Content search currently covers Claude Code and OpenCode; bare/untracked-process
  discovery is Claude Code only.
- Web Push on iOS is inherently flaky (a platform limitation, not a bug here) — a
  subscription can silently die after 1-2 weeks; see "Web Push notifications" above.
- Browser coverage exercises the main rendered interactions, but live calls to
  third-party agent services still require explicit local verification.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the architecture deep-dive, file
structure, hook rationale, development workflow, and testing details.

## License

[MIT](LICENSE)
