# UI v2 (`/v2`)

A mobile-first redesign served beside the classic UI. It reads the same
host-agent data and calls the same actions; nothing in the classic UI changes
except one "Try the new UI" link on its dashboard. `/v2` has a "Classic" link in
its app bar and a "Back to classic" button in Settings and Status.

Only the dashboard exists so far (the pilot). A session card opens the classic
session page; the session, archived-session and file views move over in later
steps.

## What it is made of

| Piece | Where |
|---|---|
| Routes `/v2`, `/v2/`, `/v2/fragment`, `/v2/archived`, `/v2/action` | `src/routes.php` |
| Page, poll fragment, archived list, JSON action endpoint | `src/lib/Controllers/V2Controller.php` |
| Render helpers (card, counts, archived and bare rows) | `src/lib/Views/V2View.php` |
| Templates | `src/partials/v2/` |
| Design tokens and all styling | `public/css/v2.css` (hand-written, no build step) |
| Behaviour (tabs, filters, polling, actions) | `public/js/v2.js` (plain ES5, like the classic JS) |

There is no new framework and no build step. The classic `tailwind.css` is not
loaded by `/v2`.

## Design

- **Tokens.** Every colour, radius, tap-target size and safe-area inset is a CSS
  custom property at the top of `v2.css`. Dark mode is the same set replaced
  under `<html data-theme="dark">`. An inline script in the layout sets it
  before first paint from the stored choice (Settings: match device / light /
  dark, kept in `localStorage`) or `prefers-color-scheme`.
- **Navigation.** A bottom tab bar (Sessions, Search, Archived, Status) with a
  badge for sessions needing input, a floating "+ New" button, and a Settings
  sheet. Sheets are `<dialog>` elements.
- **Cards.** One card per session: status dot and left accent (needs input,
  working, idle), title, agent/account/runtime/worker chips, folder, age,
  context %, worktree, a one-line message preview. A blocked prompt renders its
  question, its context and one full-width button per option. The whole card
  opens the session; star and the `...` menu (Rename, move to
  headless/terminal, Kill) sit above it.
- **Touch.** Controls are at least 44px, inputs are 16px (no iOS zoom), and the
  tab bar and sheets respect the safe-area inset.

## Data and actions

The page and its poll (`/v2/fragment`, every 3s by default, paused while the tab
is hidden) call the same `list` action as the classic dashboard. The poll
leaves the list alone while a card menu is open. Existing endpoints are reused
as they are: `/session_star.php`, `/session_rename.php`, `/answer_prompt.php`,
`/browse.php`, `/create_folder.php`, `/session_list_models.php`,
`/session_list_claude_profiles.php`, `/search_sessions.php`, `/quota.php`.

`POST /v2/action` is the JSON twin of the classic `POST /` handler (which
answers with a redirect and a flash message that `fetch()` cannot use). It takes
`action` = `new`, `resume`, `switch_runtime`, `kill`, `kill_bare`, `cleanup`,
`install_hook` or `set_push_timer_interval`, with the same parameters as the
classic one, plus `csrf_token`, and answers `{ok, message, redirect?}`. It has
the same same-origin and CSRF checks as every other mutating endpoint.

## Dashboard coverage

Covered: live session list with status/agent/account/runtime/worker/starred
state and ordering, needs-input / working / idle filters, one-tap answers to
single-question prompts, star, rename, kill, move between headless and
terminal, new session (agent, account, runtime, model, starting mode, task
tools, folder browser with new folder), "Hide idle unstarred", "Show worker
sessions", poll interval, kill inactive > 12h, bare-process list with kill,
lazy archived list with filter, Resume (terminal) and Resume headless, content
search across live and archived sessions with Archive/Unarchive, usage meters
per agent, health checks, hook install banner, push-check interval, agent
unreachable banner, light/dark.

Deferred to later steps: bare-process Take over and Identify, "Show last 3
messages" on a card, free-text and multi-question prompt answers (the card links
to the session page for those), the folder-trust dialog (same), Web Push
subscribe/unsubscribe, pull to refresh, the session, archived-session and file
pages, the sidebar, per-session context and mode controls.

## Running a second instance

The classic container serves `public/` with `php -S` behind a bind-mount. To
preview a worktree on the LAN without touching it, run the same server natively:

```bash
cd <worktree>
composer install   # vendor/ is not committed
SESSIONEER_AGENT_SOCKET=/run/user/1000/sessioneer-agent.sock \
SESSIONEER_BIND_ADDR=<lan ip> PHP_CLI_SERVER_WORKERS=4 \
  php -S <lan ip>:<free port> -t public public/index.php
```

It talks to the real host agent, so actions are real. The Host allowlist accepts
the bind address.
