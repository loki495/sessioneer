# GEMINI.md

This file provides guidance to Antigravity (agy) when working with code in this repository.

## What this is

A personal, single-user web UI for managing Claude Code, Codex, OpenCode
and Antigravity sessions (tmux sessions `cc-*`, `oc-*`, `ag-*`, plus
headless ones) on this dev box — list sessions, see blocked prompts, answer them, send
messages, view transcripts, kill sessions. No database, no user accounts;
access control is the network binding (LAN-only), not a login.

## Commands

```bash
bash tests/run.sh          # run the whole test suite
bash tests/run.sh --bail   # stop at the first failing test file
```

No Composer test runner, no Pest, and nothing to build to run the app (plain
JS; `public/css/tailwind.css` is committed, regenerated with the dev-only
`npm run build:css`). `tests/run.sh` runs each `tests/test_*.php` directly via
the `php` CLI. Tests are self-isolating: they point `TMUX_SOCKET`/
`CLAUDE_BIN`/sidecar paths at fixtures (`tests/.env.testing`), so they
never touch the real tmux server or spawn a real (billable) process, and
`run.sh` always cleans up its isolated tmux server + fixture processes on
exit, failure, or interrupt.

To exercise a single area, run that one `tests/test_*.php` file directly
with `php` rather than the whole suite (check its own header comment for
what it covers and what fixtures it needs).

No PHPStan/Pint/Rector here — this is not a Laravel project.

The container never needs a rebuild for PHP/JS edits (`src/`, `public/`,
and `vendor/` are all bind-mounted); `docker compose up -d --build` is
only needed if `docker-compose.yml`'s inline Dockerfile itself changes
(docroot, CMD, PHP extensions, etc.). The host agent (`host-agent/`) also
runs directly off the checked-out repo path with no restart needed per
edit — each connection gets a fresh PHP process, spawned by systemd
socket activation.

## Architecture: two runtimes, one repo

The web UI (`src/`) runs in a Docker container. It **never touches tmux or
the host process table directly** — it only speaks a one-request-one-response
JSON protocol over a UNIX socket (`src/lib/AgentClient.php`) to a separate,
host-native **agent** (`host-agent/`, installed via `host-agent/install.sh`,
run by systemd socket activation — not containerized).

**Why the split exists**: tmux auto-spawns its server as a child of whichever
process first talks to an unstarted socket. If the container ever became that
first process, the tmux server (and every session in it) would be born inside
the container's own filesystem namespace, unreachable from the host. Keeping
all tmux/`/proc` access in a process that is *always* host-native makes that
impossible by construction — not by convention.

### Request flow

1. `public/` is the document root — every request goes through
   `public/index.php`, the one front controller. `public/js/*.js` and
   `public/sw.js` are served directly.
2. `index.php` matches the request against `App\Http\Router`
   (`src/lib/Http/Router.php`, loaded via `src/routes.php`) — a deliberately
   simple exact-path matcher. A match instantiates the mapped
   `App\Controllers\*` class and calls the mapped method; no match is a hard
   404. Every controller is a thin action: call `AuthService`, call
   `AgentClient::agent_call([...])`, hand the result to a view.
3. `AgentClient` opens the UNIX socket, writes one JSON request, reads one
   JSON response. `agent.php` (host-agent's entry point) is a per-connection
   process spawned by systemd.
4. Two dispatchers on the host-agent side: `Push.php`'s
   `dispatch_push_action()` handles `push_*` actions, falling through to
   `Sessions.php`'s `dispatch_action()` for everything else. The real logic
   lives in `host-agent/lib/Services/*` and `host-agent/lib/Stores/*`, all
   PSR-4 autoloaded.
5. `App\Views\*` (one render class per feature area) is what controllers
   hand their `AgentClient` result to. Views extend `App\Views\View`, which
   owns a shared `League\Plates` engine rooted at `src/partials/`.

Both `App\` (-> `src/lib/`) and `HostAgent\` (-> `host-agent/lib/`) are
Composer PSR-4 autoloaded from the one root `composer.json` — `public/`,
`src/`, and `vendor/` are bind-mounted into the container as siblings so the
same autoloader works in both places.

### Hooks

This app installs hooks into both Claude Code (`~/.claude/settings.json`) and
Antigravity. They feed `SessionStatusStore` (one `<session>.status.json` file
per tracked session under `Config::sidecar_dir()`) with mode/working-status/
blocked-prompt state:

- **PreToolUse / PreInvocation** — records exact, untruncated tool name/input
  for a pending tool call straight from the hook's stdin. Never approves or
  denies anything. Also clears blocked state on every firing (a new tool call
  starting is proof any earlier permission prompt was already resolved).
- **PermissionRequest** — sets blocked state with the prompt content.
- **UserPromptSubmit** — sets working state.
- **Stop** — sets idle state.
- **SessionStart** — rebinds the tracked session's sidecar to the new
  transcript UUID on /clear//compact/--resume/--fork-session.

All hooks are no-ops for sessions started outside this app (they key off a
`SESSIONEER_SESSION_NAME` tmux pane environment variable that only
`create_agent_session()`-spawned sessions have).

## Conventions worth knowing before editing

- **Check real docs before acting on memory for tool/hook details.** Both
  the model itself and a fresh subagent can hallucinate specific details
  (version numbers, env var names, wrong explanations) that sound confident
  but aren't real. Relevant doc URLs:
  - https://code.claude.com/docs/en/tools-reference
  - https://code.claude.com/docs/en/hooks
  - https://code.claude.com/docs/en/commands
  - https://code.claude.com/docs/en/cli-reference

- **`public/js/*.js` is plain ES5** (`var`, `function`, no `const`/`let`,
  arrow functions, `Set`, or template literals) — no transpiler in this
  project, and mobile Safari compatibility is the reason. Match this style;
  don't introduce ES6+ syntax.

- Every request re-validates state fresh rather than trusting client input:
  a session name for `kill`, an option number for `answer_prompt`, etc. are
  all re-checked against a fresh listing/whitelist inside the same request.
  Follow this pattern for any new state-changing action.

- All tmux/process invocations use `proc_open()` with the command as an
  **array**, never a shell string — no shell metacharacter injection surface.
  Keep new host-agent commands in this form.

- Comments throughout this codebase frequently record a specific bug found
  live (often "found live: ...", "verified live YYYY-MM-DD ...") and the
  reasoning that led to the fix — read them before assuming something is
  over-engineered.

- Every transcript block carries two cross-cutting attributes in both its PHP
  render (`transcript/block.php`) and its JS poll-time mirror (`renderBlock()`
  in `session.js`) — keep both in sync when touching either:
  - `data-line="<line>"` — the block's own raw JSONL line number, used to
    scroll to and highlight a search result.
  - A `.copy-btn` + `.copy-source` pair — the shared copy-to-clipboard
    affordance. A new block kind needs both attached the same way, or it
    silently loses search-jump and copy support.

- Branch model: work happens directly on `master` for anything normal. A
  separate `refactor/*` or `feature/*` branch is reserved for changes big/
  risky enough that the live site could stop loading partway through. Once
  merged, delete the branch (both local and remote) — don't let finished
  branches pile up.

- Andres may open-source this repo. When a design choice could go toward
  "simplest for the one deployment" vs "standard/portable shape," default to
  the portable one. Flag the trade-off if unsure, but lean portable by default.
