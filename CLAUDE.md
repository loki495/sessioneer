# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A personal, single-user web UI for managing tmux sessions running Claude
Code, Codex, OpenCode, and Antigravity (`cc-*`, `cx-*`, `oc-*`, `ag-*`) on
this dev box — list sessions, see blocked prompts, answer them, send
messages, view transcripts, kill sessions. No database, no user accounts;
access control is the network binding (LAN-only), not a login.

## Commands

```bash
bash tests/run.sh          # run the whole test suite
bash tests/run.sh --bail   # stop at the first failing test file
```

No Composer test runner, no Pest, no build step for JS/CSS (plain files,
no bundler/npm — there is no `package.json`). `tests/run.sh` runs each
`tests/test_*.php` directly via the `php` CLI. Tests are self-isolating:
they point `TMUX_SOCKET`/`CLAUDE_BIN`/sidecar paths at fixtures
(`tests/.env.testing`), so they never touch the real tmux server or spawn
a real (billable) `claude` process, and `run.sh` always cleans up its
isolated tmux server + fixture processes on exit, failure, or interrupt.

To exercise a single area, run that one `tests/test_*.php` file directly
with `php` rather than the whole suite (check its own header comment for
what it covers and what fixtures it needs).

No Pint/Rector here — this is not a Laravel project. **PHPStan does run**,
though: `composer phpstan` (level 6 over `host-agent/lib`, `host-agent/hooks`
and `src/lib`), and CI gates on it, so run it before pushing. Findings that
predate a change live in `phpstan-baseline.neon` — note that a baseline entry
is keyed to the exact method name, so renaming or extracting a method moves
its body out from under its entry and resurfaces the error.

The container never needs a rebuild for PHP/JS edits (`src/`, `public/`,
and `vendor/` are all bind-mounted); `docker compose up -d --build` is
only needed if `docker-compose.yml`'s inline Dockerfile itself changes
(docroot, CMD, PHP extensions, etc.). The host agent (`host-agent/`) also
runs directly off the checked-out repo path with no restart needed per
edit — each connection gets a fresh PHP process, spawned by systemd
socket activation.

## Architecture: two runtimes, one repo

The web UI (`src/`) runs in a Docker container. It **never touches tmux or
the host process table directly** — it can't; the container has no such
access. It only speaks a one-request-one-response JSON protocol over a UNIX
socket (`src/lib/AgentClient.php`) to a separate, host-native **agent**
(`host-agent/`, installed via `host-agent/install.sh`, run by systemd
socket activation — not containerized).

**Why the split exists**: tmux auto-spawns its server as a child of
whichever process first talks to an unstarted socket. If the container ever
became that first process, the tmux server (and every session in it) would
be born inside the container's own filesystem namespace, unreachable from
the host and pointing at paths that don't exist there. Keeping all tmux/
`/proc` access in a process that is *always* host-native, never
Docker-spawned, makes that impossible by construction — not by convention.

### Request flow

1. `public/` is the document root - every request goes through
   `public/index.php`, the one front controller (standard Laravel/Symfony/
   Slim-style layout, not something tied to `php -S` specifically - it also
   works as a `php -S` router-script argument, which is how the Docker
   container actually runs it today; `public/.htaccess` covers Apache,
   nginx needs an equivalent `try_files` directive in its own config).
   `public/js/*.js` and `public/sw.js` are the only real static files there
   - `index.php` returns `false` for those two patterns and lets whatever's
   in front serve them directly.
2. `index.php` matches the request against `App\Http\Router`
   (`src/lib/Http/Router.php`, loaded via `src/routes.php`) - a
   deliberately simple exact-path matcher, no groups/middleware/path
   parameters yet. A match instantiates the mapped `App\Controllers\*`
   class (`src/lib/Controllers/`, one class per feature area -
   `DashboardController`, `SessionController`, `BrowseController`,
   `UploadController`, `PushController`, `QuotaController`) and calls the
   mapped method; no match is a hard 404. Every controller method is a
   thin action: call `AuthService` (CSRF/session - via one of
   `App\Controllers\Controller`'s two shared guard helpers,
   `require_post_json()`/`start_readonly_json()`, for everything except
   the two full-page renders and `BrowseController`, which have their own
   reasons not to), call `AgentClient::agent_call([...])` to talk to the
   agent, then hand the result to a `PageView`/`App\Views\*` class to
   render - no inline HTML in the controllers themselves.
3. `AgentClient` opens the UNIX socket, writes one JSON request, reads one
   JSON response. `agent.php` (host-agent's entry point) is a per-connection
   process spawned by systemd; it decodes the request, dispatches on
   `action`, and writes back one JSON response.
4. Two dispatchers on the host-agent side: `Push.php`'s
   `dispatch_push_action()` handles `push_*` actions, falling through
   (`null`) to `Sessions.php`'s `dispatch_action()` for everything else.
   Both are now thin switches — the real logic lives in
   `host-agent/lib/Services/*` (36 classes; the load-bearing ones are
   `Config`, `SessionService`, `SessionLifecycleService`,
   `PromptInteractionService`, `ArchivedSessionService`, `PlanFileService`,
   `TmuxService`, `TranscriptService`, `PromptParser`, `ProcessInspector`,
   `ProcessRunner`, `QuotaService`, `UploadService`, `HookService`, plus the
   push set) and `host-agent/lib/Stores/*` (`SidecarStore`,
   `PendingToolStore`, `SessionStatusStore`, `SessionListCacheStore`,
   `GlobalStateStore`, `PushSubscriptionStore`, `PushSessionStateStore`,
   `PushQuotaStateStore`, `SqliteDb`).

   Two more namespaces carry the multi-agent design and are where new
   agent/runtime work belongs — keep per-agent branching out of the services:
   `host-agent/lib/Agents/*` is WHICH agent (`AgentAdapter` + `AgentRegistry`,
   with `ClaudeCodeAdapter`/`CodexAdapter`/`OpenCodeAdapter`/
   `AntigravityAdapter` behind it), and `host-agent/lib/Runtimes/*` is HOW it
   runs, independently of which agent it is (`TmuxRuntime` vs
   `HeadlessRuntime` behind `RuntimeProvider`/`RuntimeRegistry`/`RuntimeType`,
   plus `OpenCodeServeClient`, `CodexBridgeClient`, `CodexHeadlessRuntime` —
   see `docs/headless-runtime-plan.md`). All PSR-4 autoloaded under
   `HostAgent\Services`/`Stores`/`Agents`/`Runtimes`.
5. `App\Views\*` (one render class per feature area — `TranscriptView`,
   `SessionRowView`, `BlockedPromptView`, `QuotaFooterView`,
   `HealthBoxView`, `PushNotifyView`, plus `PageView` for the two full-page
   templates) is what controllers hand their `AgentClient` result to.
   Views extend `App\Views\View`, which owns a shared `League\Plates`
   engine rooted at `src/partials/` — templates are grouped by feature
   (`partials/transcript/`, `partials/blocked-prompt/`,
   `partials/session-row/`, `partials/pages/`, ...), not one flat directory.

Both `App\` (→ `src/lib/`) and `HostAgent\` (→ `host-agent/lib/`) are
Composer PSR-4 autoloaded from the one root `composer.json` — `public/`,
`src/`, and `vendor/` are bind-mounted into the container as siblings
(mirroring the host's own repo-root layout) so the same autoloader works
in both places.

### Five Claude Code hooks this app installs into `~/.claude/settings.json`

- **SessionStart** (`host-agent/hooks/session_start.php`) — Claude Code
  rotates to a new transcript-file UUID on `/clear`/`/compact`/`--resume`/
  `--fork-session` while staying in the same tmux pane. This hook rebinds
  the tracked session's sidecar to the new UUID every time it fires, or the
  app would keep reading an abandoned, no-longer-growing transcript file.
- **PreToolUse** (`host-agent/hooks/pre_tool_use.php`) — records the exact,
  untruncated `tool_name`/`tool_input` for a pending tool call straight from
  the hook's stdin, so a blocked permission prompt's preview doesn't depend
  on scraping a small, best-effort-rendered tmux pane. Never approves or
  denies anything — writes nothing to stdout, always exits 0. Also clears
  `SessionStatusStore`'s blocked state on every firing (added 2026-08-22,
  see CONTRIBUTING.md) — a later tool call starting is itself proof any
  earlier permission prompt was already resolved, which is what actually
  clears a stale "waiting on input" prompt that PermissionRequest set for a
  tool call earlier in the same turn.
- **PermissionRequest** (`host-agent/hooks/permission_request.php`),
  **UserPromptSubmit** (`host-agent/hooks/user_prompt_submit.php`), and
  **Stop** (`host-agent/hooks/stop.php`) — feed `SessionStatusStore`
  (`HostAgent\Stores`, the `session_status` table in
  `Config::sessions_sqlite_path()`, one row per tracked session) with
  mode/working-status/blocked-prompt
  state, inferred from hook SEQUENCE (`UserPromptSubmit` → working,
  `PermissionRequest` → blocked, `Stop` → idle, `PreToolUse` → also clears
  blocked). **Mandatory, not a
  "prefer this, fall back to pane-scraping" cascade** (decided 2026-08-22):
  `SessionService::build_session_entry()` reads mode/working-status/
  blocked-prompt content EXCLUSIVELY from this file for every tool except
  `AskUserQuestion` — a session with no status file (hooks not installed)
  just reports unknown/idle/no-prompt, even if the pane shows a real one.
  Only two prompt shapes still need the live pane, structurally, regardless
  of hook installation: the initial folder-trust dialog (fires none of
  these hooks at all) and a SINGLE-question `AskUserQuestion`'s CONTENT (no
  tab bar exists for one question, so there's nothing to gain from the
  hook-fed `questions[]` over the pane). A MULTI-question `AskUserQuestion`
  (2+ questions) is answered entirely from the hook-fed `blocked.tool_input.
  questions[]` instead — `build_session_entry()` exposes it as
  `prompt_questions`, and `PromptInteractionService::answer_multi_question()` computes
  and sends the whole tab-bar key sequence in one shot (see
  `PromptParser::build_multi_question_key_sequence()` and CONTRIBUTING.md's
  "Answering a multi-question AskUserQuestion without reading the pane" for
  the confirmed mechanics) — no more Prev/Next tab navigation needed for
  this shape, so the old mechanism (`SessionService::navigate_prompt()`,
  `/session_navigate.php`, the `nav-prompt-btn` UI) was deleted outright
  2026-08-22, not left around unused. The pane-title-spinner-glyph working-status
  detection this replaced (`PromptParser::pane_title_is_working()`,
  `TmuxService::tmux_session_panes()`'s old `working` key) was deleted
  outright, not left around unused — see CONTRIBUTING.md for the full
  reasoning. All three are pure-observe, same as PreToolUse.

All five are no-ops for a plain `claude` session started by hand outside
this app (they key off a `SESSIONEER_SESSION_NAME` tmux pane environment variable
that only `create_agent_session()`-spawned sessions have).

## Conventions worth knowing before editing

- **OpenCode server integration: prefer the v2 `/api/session` API for
  everything possible from now on** (decided 2026-08-26). v1 `/session`
  only returns the tiny "currently live" set and is unreliable for
  enumeration (caused the headless sync to prune just-resumed sessions);
  v2 is the fuller, canonical surface (see the "Convention" note in
  `docs/headless-runtime-plan.md`). When touching any opencode-server
  call, check whether a v2 endpoint exists and use it; fall back to v1
  only where v2 genuinely isn't available/working for that operation.
 - **OpenCode question events (1.18.21):** `GET /event` delivers
   `question.asked/replied/rejected` when scoped with the URL-encoded
   **exact session directory** in `x-opencode-directory`. An unscoped or
   parent-directory connection can deliver only connected/heartbeat events.
   The host-native `sessioneer-opencode-events.service` consumes these events
   into `SessionStatusStore`; throttled headless polling must preserve its
   live questions. Replies require the question request ID, not the tool
   call ID. v2 replies use no-content success; scoped legacy replies return
   JSON `true`. See `.ai/research/opencode-11821-webui-sse-question-prompts.md`
   for the captured evidence and WebUI compatibility-layer distinctions.

- **Claude Code tools/hooks questions: check the real docs first, not
  memory.** Whenever a change or investigation touches what tools/hooks a
  Claude Code session has available (this came up 2026-08-22/23
  investigating why `TodoWrite` wasn't available for the sidebar's Tasks
  feature), check the actual current docs before acting on assumption or
  a subagent's summary - both the model itself and a fresh subagent can
  hallucinate specific details (a version number, an env var name, a
  wrong explanation like "the MCP server disconnected") that sound
  confident but aren't real:
  - https://code.claude.com/docs/en/tools-reference - and specifically
    https://code.claude.com/docs/en/tools-reference#task-tool-availability
    for why `TodoWrite`/`TaskCreate`/`TaskGet`/`TaskUpdate`/`TaskList` may
    or may not be available on a given model (confirmed: disabled by
    default in Claude Code v2.1.233+ on Opus 4.8/Sonnet 5/Fable 5/Mythos 5
    and later, opt back in via `CLAUDE_CODE_ENABLE_TODO_TOOLS=1`, naming
    one in `--allowedTools`, or listing them in `--tools`).
  - https://code.claude.com/docs/en/hooks
  - https://code.claude.com/docs/en/commands - existing slash commands,
    before assuming one needs to be built from scratch.
  - https://code.claude.com/docs/en/cli-reference - CLI flags/parameters
    and their actual behavior (this is where `--tools`/`--allowedTools`/
    `--disallowedTools`/`--session-id`/etc. are documented).
  - Confirmed live 2026-08-23 (empirically, not just from docs): `--tools`
    REPLACES the session's built-in tool set with exactly what's named
    (e.g. `--tools=TaskCreate,TaskList` drops `Bash`/`Read`/`Write`/`Edit`
    entirely) - `--allowedTools` is ADDITIVE instead, naming a tool turns
    it on alongside the normal default set. For a real coding session
    that still needs Bash/Read/Write/Edit, `--allowedTools`/
    `CLAUDE_CODE_ENABLE_TODO_TOOLS=1` are the safe options, not `--tools`.

- **`public/js/*.js` is plain ES5** (`var`, `function`, no `const`/`let`,
  arrow functions, `Set`, or template literals) — no transpiler in this
  project, and mobile Safari compatibility has repeatedly been the reason
  (see the several iOS-specific comments throughout `session.js`). Match
  this style; don't introduce ES6+ syntax.
- Every request re-validates state fresh rather than trusting client input:
  a session name for `kill`, an option number for `answer_prompt`, etc. are
  all re-checked against a fresh listing/whitelist inside the same request.
  Follow this pattern for any new state-changing action.
- All tmux/process invocations use `proc_open()` with the command as an
  **array**, never a shell string — no shell metacharacter injection
  surface. Keep new host-agent commands in this form.
- Comments throughout this codebase frequently record a specific bug found
  live (often "found live: ...", "verified live YYYY-MM-DD ...") and the
  reasoning that led to the fix — read them before assuming something is
  over-engineered; the non-obvious behavior it's guarding against is
  usually explained right there.
- Every transcript block (text/plan/tool_use/tool_result/task_notification) carries two
  cross-cutting attributes in both its PHP render (`transcript/block.php`)
  and its JS poll-time mirror (`renderBlock()` in `session.js`) — keep
  both in sync when touching either:
  - `data-line="<line>"` — the block's own raw JSONL line number, used to
    scroll to and highlight a search result (see "Session/dashboard
    content search" in CONTRIBUTING.md).
  - A `.copy-btn` + `.copy-source` pair (wrapped in `.copy-block`, or the
    block's own `<pre>` for a `<details>`-expanded collapsible block) —
    the shared copy-to-clipboard affordance (`copyTextToClipboard()` in
    `common.js`). A new block kind needs both attached the same way, or it
    silently loses search-jump and copy support that every other kind has.
- Branch model actually used here: work happens directly on `master`
  (pushed straight to `origin/master`) for anything normal - a new feature,
  a bug fix, a doc update. A separate `refactor/*` or `feature/*` branch
  (sometimes in a second git worktree, e.g.
  `../claude-session-manager-refactor`) is reserved for changes big/risky
  enough that the live site could stop loading partway through - a
  multi-phase structural refactor (the Plates-templating and
  front-controller/router migrations were the two so far), not a small
  self-contained feature. Once merged, delete the branch (both local and
  remote) - don't let finished branches pile up. This repo does not use the
  generic global `master → local → feature` model.
- Andres may open-source this repo. When a design choice could go either
  toward "simplest for the one deployment this runs today" or "the
  standard/portable shape," default to the portable one, even if it's a
  bit more setup now. Concrete precedent: `public/index.php` is a
  conventional Laravel/Symfony/Slim-style front controller rather than a
  `php -S`-router-script-only trick, even though `php -S` is the only
  server this app actually runs on today - it still works as a `php -S`
  router-script argument, but also works unmodified behind Apache
  (`public/.htaccess`, added preemptively) or nginx (documented `try_files`
  equivalent in the README). Flag the trade-off if you're unsure it's
  worth the extra effort in a given case, but lean portable by default.

## Feature atlas

`.ai/feature-atlas/` (gitignored, maintainer-local — not published; run
`/feature-atlas` to regenerate it if you need one) is the source of truth
for this project's feature/subsystem inventory when present: one
`DETAILS.md` + `AUDIT.md` per subsystem, plus `SUMMARY.md` (registry +
digest) and `REPORT.md` (ranked, cross-subsystem findings). Read it before
re-deriving feature boundaries from scratch, and run `/feature-atlas`
after adding/removing or substantially changing a feature, or at minimum
before a refactor spanning multiple subsystems.
`/feature-atlas-subsystem <id>` refreshes one subsystem; `/feature-atlas-report`
re-ranks existing audits without rescanning. Note that the atlas only reports
feature attribution across files (a file can be listed under several
subsystems); it does not imply or require any physical file split.

`docs/features.md` is the canonical, exhaustive capability list for the tool
(what it can do), plus a per-agent coverage matrix and the known parity gaps —
read it with `REPORT.md` when planning "same features for each agent" work.
