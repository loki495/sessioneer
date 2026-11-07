# UI

A mobile-first UI: `/` is the dashboard, `/session` the session page and
`/archived-session` the archived view. The previous (classic) UI was removed;
its URLs answer 404 (git tag `classic-ui-final` marks the last state). The old
page URLs `/v2`, `/v2/session` and `/v2/archived-session` redirect to the plain
ones (query kept), and the JSON endpoints (`/action`, `/history`, `/archived-history`,
`/fragment`, `/archived`) also answer under `/v2/` for scripts cached before the rename. The other
JSON `*.php` endpoints are the API the pages call. Push notifications and Quick
Prompt session links open `/session`. A session card opens the session page, an
archived row the archived page. The file views (plan, todo, uploads) live in the session page's
details sheet.

## What it is made of

| Piece | Where |
|---|---|
| Routes `/`, `/session`, `/archived-session`, the redirecting `/v2*` page URLs, and the JSON `/history`, `/archived-history`, `/fragment`, `/archived`, `/action` | `src/routes.php` |
| Pages, poll fragment, archived list, history, JSON action endpoint | `src/lib/Controllers/UiController.php` |
| Render helpers (card, counts, archived and bare rows, session page) | `src/lib/Views/UiView.php` |
| Templates | `src/partials/ui/` |
| Design tokens and the dashboard's styling | `public/css/ui.css` (hand-written, no build step) |
| Session page styling | `public/css/ui-session.css` (uses the same tokens) |
| Dashboard behaviour (tabs, filters, polling, actions) | `public/js/ui.js` (plain ES5) |
| Continue-here / start-a-new-one buttons | `public/js/ui-advise.js` (plain ES5, mounts on `[data-advise-host]`) |
| Session page behaviour | `public/js/ui-session.js` (transcript, polling, prompts, footer) and `public/js/ui-session-sheets.js` (controls, details, files, search, viewers) |

There is no framework and no build step. Browser push is subscribed from a
Notifications card in Settings (`push-notify.js`, which also keeps the CSRF token
the service worker needs to answer prompts from a notification).

## Design

- **Tokens.** Every colour, radius, tap-target size and safe-area inset is a CSS
  custom property at the top of `ui.css`. Dark mode is the same set replaced
  under `<html data-theme="dark">`. An inline script in the layout sets it
  before first paint from the stored choice (Settings: match device / light /
  dark, kept in `localStorage`) or `prefers-color-scheme`.
- **Desktop size.** From 1024px up the root font is 18px (every rem size scales with it), the centered
  column is 900px and sheets are about 700px wide, and the session bar's usage and
  context readout is larger. Below 1024px nothing changes.
- **Navigation.** A bottom tab bar (Sessions, Search, Archived, Status) with a
  badge for sessions needing input, a floating "+ New" button, and a Settings
  sheet. Sheets are `<dialog>` elements.
- **Depth.** Neutrals are slightly blue-tinted (page, card and inset shades differ), and one
  accent (`--accent`, with `--accent-soft` and `--accent-line` derived from it) draws
  structure: bar and footer rules, card and sheet borders, section titles, buttons and
  chips. Sheet headers stay fixed while the body scrolls. Each agent has a colour
  (`--agent-*`) used for its usage group tint. Starred sessions get a faint amber tint.
- **Cards.** One card per session: status dot and left accent (needs input,
  working, idle), title, agent/account/runtime/worker chips, folder, age,
  context %, worktree, a one-line message preview. The agent chip also names the
  model and reasoning level the session is on ("Claude Code · Sonnet · high")
  when the agent reports them. A blocked prompt renders its
  question, its context and one full-width button per option. The whole card
  opens the session; star and the `...` menu (Rename, Take over for a terminal
  Claude session, Kill) sit above it.
- **Headless only.** `/` creates and resumes everything headless: there is no
  "Runs in" picker, an archived row has one Resume (it implies headless, for Claude
  `UiController` always passes `runtime=headless`), Antigravity (tmux-only) is not
  offered for new sessions, and nothing moves a session to a terminal. A session
  that still runs in a terminal (tmux; Claude or Antigravity) is listed and opened
  **read only**: a "Terminal, read only" chip, no composer, its prompt shown with every
  control disabled, and a note to take it over. Take over (card menu and session
  details) is the existing `switch_runtime` to headless; Claude only, since
  Antigravity cannot run headless.
- **Touch.** Controls are at least 44px, inputs are 16px (no iOS zoom), and the
  tab bar and sheets respect the safe-area inset.

## Data and actions

The page and its poll (`/fragment`, every 3s by default, paused while the tab
is hidden) call the host agent's `list` action. The poll
leaves the list alone while a card menu is open. Existing endpoints are reused
as they are: `/session_star.php`, `/session_rename.php`, `/answer_prompt.php`,
`/browse.php`, `/create_folder.php`, `/session_list_models.php`,
`/session_list_claude_profiles.php`, `/search_sessions.php`, `/quota.php`.

`POST /action` is the JSON action endpoint. It takes
`action` = `new`, `resume`, `switch_runtime`, `take_over`, `take_over_confirm`, `kill`, `kill_bare`, `cleanup`,
`install_hook`, `set_push_timer_interval`, `advise` or `advise_act`, plus `csrf_token`, and answers `{ok, message, redirect?}`. `take_over` (a
bare process) answers with a redirect to the new headless session, or
`{needs_choice, pid, workdir, candidates, suggested_agent_session_id}` when the process
could not be identified (nothing is stopped yet); the page then shows a picker and posts
`take_over_confirm`. Both reach the host agent's `take_over_bare` / `take_over_bare_with_id`
with `runtime=headless`, so the final resume goes through the headless runtime, not a pane.
`advise` (`session`) answers `{status, advice}` from the host agent's `advise` action: the verdict, context
size, cache age and the token cost of each path. `advise_act` (`session`, `choice` = `resume` or `fresh`) acts on
the person's pick, not the verdict: resume sends the continue prompt to the same conversation when its account has room; fresh starts a new
session with a global `/takeover` prompt naming the source agent, profile and conversation. If a Claude source account is at 90% or more, fresh prefers Codex, then OpenCode, then manual Antigravity takeover, each with known quota below 90% in every metered window (OpenCode reports no window at all). The other Claude profile is the last resort when these alternatives are limited, unknown or incompatible. A blocked Codex source still prefers an available Claude profile. The receiver reads the full source
thread and Dibs state, then archives the old session after its checks; `advise_act` leaves it available meanwhile.
It answers with a redirect to the session that carries on. The idle guard and the source quota guard on resume still apply. The button itself is rendered only when the session's last activity is an hour or more old (`App\AdvisePolicy::CACHE_TTL_SECONDS`) or its account quota is at 90% or more (`App\AdvisePolicy::QUOTA_PARK_PCT`; `UiView::advise_visible()`, one memoised `quota` call per request and only when idle time alone does not decide). Nothing is
sent to the old session on `advise`, and the one small model call for a close verdict happens only there, for the one
session asked about. If its source quota resets in less than an hour, that call can instead weigh urgency, sensitivity and context transfer, and recommend waiting for the reset; a failed call defaults to takeover. `/action` has the same same-origin and CSRF checks as every other mutating endpoint.

## Continue here or start a new one

An idle headless Claude or Codex card has a **Continue or start a new one?** button, and the session page has the
same button below the transcript. Tapping it asks for advice for that one session and shows the reason, the context
size and cache age, and two buttons: **Continue here** (resume; its line gives the tokens re-written now when the
cache is cold, and re-read each turn) and **Start a new one** (reviews the full source thread and Dibs state;
archives this session after takeover checks). When the source account is nearly full, **Continue here** is disabled. The panel shows when its quota resets or says the reset is unknown. The other button becomes **Take over in another session**, names the receiving account or agent, and remains usable when that receiver has known room. If no launchable receiver is available, or manual Antigravity takeover is preferred over another Claude profile, the panel shows a manual `/takeover` prompt and names any manual agent with known room. A short reset can produce a distinct **Wait for quota, then continue** recommendation, while still allowing a person to choose takeover. The advised option is highlighted; a close call (`judgment`) highlights neither. Both buttons use white text on dark backgrounds in either theme.
Both buttons are disabled while the session is not idle. Starting a takeover
asks for confirmation. The poll leaves the list alone while an advice panel is open. The thresholds are in
[cli.md](cli.md) under `--advise`.

## Dashboard coverage

Covered: live session list with status/agent/account/runtime/worker/starred
state and ordering, needs-input / working / idle filters, one-tap answers to
single-question prompts, star, rename, kill, take over a terminal session as headless, new session (agent, account, model, starting mode, task
tools, an optional first message, folder browser with new folder), "Hide idle unstarred", "Show worker
sessions", poll interval, kill inactive > 12h, bare-process list with Take over (headless) and kill,
lazy archived list with filter, Resume (headless), content
search across live and archived sessions with Archive/Unarchive, usage meters
per agent, health checks, hook install banner, push-check interval, agent
unreachable banner, light/dark.

Deferred: bare-process Identify (Take over and Kill are there; identifying a
bare process as a particular conversation is not), "Show last 3 messages" on a
card, answering a free-text, multi-question or folder-trust prompt on the card
itself (the card opens the session page for those), Web Push
subscribe/unsubscribe, pull to refresh, a "New content"
divider and per-block highlight colours, and the full-screen draft editor.
There is no sidebar: the sessions popup in the session page replaces it.

## Session page (`/session?session=<name>`)

One column, three parts: a sticky bar (back with a badge counting other sessions
that need input, title with status and chips, search, details menu), the
transcript as the only scroller, and a footer (scroll buttons, controls summary,
message box). The shell follows the visual viewport, so the footer stays above
the iOS keyboard and a pinch-zoom does not resize it.

**Rendering.** The server renders only the shell and a bootstrap JSON (the
session detail, hex-escaped so transcript text cannot close the `<script>`); the
transcript comes from `/history` (the `session_history` action plus
`include_thinking`) and is drawn by one client renderer for the first page,
"Load older", and the live tail. An archived page opened on a search hit (`jump_line`) ends at the hit and has a **Load newer** button that calls `/archived-history` with `after` set to the last line shown (50 at a time; a short page hides the button, since `after` carries no `has_more`). Archived pages never poll. Each user and assistant message is a bubble or
free text with markdown (`markdown.js`); every tool
call and its result is one collapsed `<details>` whose headline is the call's
description; pretty-printed JSON results; plan, question, subagent and
task-notification cards; inline images and attachments as thumbnails. Polling is
`session_detail` plus `session_history?after=<newest line>` on the same interval
setting as the dashboard, paused while the tab is hidden, and a rotated transcript
(`/clear`, `/compact`, `--resume`) restarts from its first page.

**Blocked prompts** (the panel above the footer, answered through
`answer_prompt.php` / `answer_multi_question.php`):

- No heading repeats the first question. A question form shows its questions
  only; a permission prompt shows its tool context and one button per option.
- The agent's own text before the prompt (the latest assistant text of the
  current turn that is on screen) sits in a panel above the options. If the agent
  wrote no text before asking, nothing is shown: the transcript has nothing to
  show. What Sessioneer used to hide were each option's `description` and the
  question's `header`; both are shown now (multi-question forms and one-question
  prompts alike).
- Every question can be answered with typed text, multi-select included
  ("Type something..." next to the boxes, sent as `[1, {"text": "..."}]`).
  Multi-select typed answers are enabled for headless Claude only: the terminal
  (tmux) key driver cannot combine a checkbox and typed text, and OpenCode
  questions have their own answer shape.
- Permission prompts, free-text replies, multi-question forms and a pending
  OpenCode `question` block all use the same builders, an
  answer shows an optimistic reply and is rolled back with the reason when the
  agent refuses it, and "Confirm before sending prompt answers" is a view option.
  A pending OpenCode `question` block in the transcript is read-only: the prompt
  panel below is the one answer surface, so the same question is not offered
  twice (numbered buttons above, radio form below).

**Footer.** Send (reads Queue while the agent is working), Stop (interrupt, after a confirmation; solid red while usable),
attach files (uploaded to the project, removed again when the chip is removed),
a draft kept per session, Shift+Enter sends (not on a touch screen, where Enter is always a newline and the Send button sends). Up and down buttons jump one message
at a time and skip tool calls; the bottom button shows a dot when new content
arrived off screen. The controls button opens model, permission mode and effort
(Claude), the model catalog (Codex with effort, OpenCode, Ollama) or the fixed
Antigravity list; each change posts to its JSON endpoint and the next poll
shows what the session actually ended up with.

**Answered questions.** An answered question form (Claude Code's and OpenCode's
`User has answered your questions: "Q"="A", ...` result) is drawn as Question /
Answer rows instead of one paragraph, and so is the optimistic reply sent from the
page. The text is parsed with a regex, with the exact pairs this browser sent
(kept in `localStorage`, the last 12 per session) preferred, because a quote inside
an answer is ambiguous; text that does not match stays raw. OpenCode's in-transcript
question block carries the whole result text (`answer_output`). Codex writes no
recognizable answer text in its transcript, so its answers stay as they are.

**Sessions and usage popups.** The bar's list button (or a swipe left) opens a
popup of every session: needs-input first, then working, then idle, starred first
within each (starred rows are faintly amber), a filter box, a star toggle per row,
tap to switch. The Sessions and Usage popups open at full height and do not
resize once their content arrives. Each row shows agent (with its model and
reasoning level when known),
account, runtime and context chips, folder and
age, and what the session waits on or its last message. A swipe right goes back in
history (the dashboard when there is none). Swipes work anywhere except where a
finger may be scrolling (a code block, a field), need 80px of mostly horizontal
travel, ignore the outer 24px (left to the browser's own back/forward gestures) and
are ignored while a popup is open. Every sheet (`public/js/ui-sheets.js`, both pages)
closes on a tap outside it, a swipe down from its fixed header, or a swipe right anywhere inside it (not over a field, code block or sideways-scrolling area, nor from the screen edges).
The session details popup header has icon-only Sessions (swaps to the sessions popup) and Star buttons plus Rename and Kill; the open session is highlighted and flagged "Current" in the sessions popup.
The bar also carries a very small stacked readout for this session's agent and
account: 5-hour use, weekly use and context (`UiQuota.mini`, from `/quota.php?session=`,
refreshed every five minutes; context follows the detail poll). It opens the same popup
as the gauge button, which stays.
The gauge button opens this session's context share and the usage meters (this
session's agent first, every Claude account when several are configured), rendered
by `public/js/ui-quota.js`, shared with the dashboard's Status tab: each agent a
tinted group, reset times in Pacific time plus a relative one. On the Status tab
Usage and Health fold; Health starts collapsed when every check passes and each
health group is collapsed on its own unless one of its checks fails. While a popup,
viewer or full-screen text is open the page behind it does not scroll. The keyboard
fix (the shell follows the visual viewport's offset as well as its height) cannot be
exercised off a real iPhone.

**Images.** A thumbnail opens a full-screen layer above the footer and every
sheet; tapping toggles fit and natural size (scroll to pan), and pinch zoom is the
browser's own. "Open original" opens the file.

**Details sheet.** One panel per topic. Details: session name and id, workdir and
resume command (copy), branch, worktree, account, runtime, context %. Actions: star,
rename, take over a terminal session (headless), kill. Then the task list; todo file (full
screen), plan and handoff files, uploaded files (open, delete, delete all); view
options: show reasoning text, show subagent calls, confirm answers, poll
interval. **Search** looks through this session (jumping to the hit, with a
"Back to latest" notice) or all sessions (live ones open in the session page,
archived ones in the archived page).

Reasoning text: the toggle re-fetches the transcript with `include_thinking`.
Claude Code writes empty reasoning blocks for models that do not persist it, so
the toggle shows nothing for them.

### Session page coverage

Covered: transcript rendering (tool calls and results, thinking indicator,
reasoning text, subagent calls, plan/question/task-notification cards, images,
attachments, pretty JSON), live updates, load earlier and load back to the last
message, blocked prompts (permission, free text, multi-question, typed answers
everywhere, option descriptions, text before the prompt), message footer
(send, queue, interrupt, attachments), model / mode / effort per agent, status,
context %, branch, worktree, account and runtime chips, plan and todo files,
task list, uploads, in-session and all-session search, rename, star, kill, switch
runtime, resume command, copy buttons, other-sessions-need-input badge, one
message up and down, zoomable images, light and dark.

Deferred: Web Push subscribe, pending messages surviving a reload,
the "New content" divider and per-block highlight colours (new entries flash instead), and the full-screen
text editor for long drafts.

## Archived-session page (`/archived-session?agent_session_id=<id>`)

A dormant conversation opens in the same page shell as the session page,
booted read only (`archived: true` in the bootstrap, `jump_line` supported).
The transcript comes from `/archived-history` (the
`archived_session_history` call as JSON entries, rendered by the same
`ui-session.js` code, so bubbles, tool calls, images and attachments match the
live page); there is no polling and no composer. `ui-archived.js` adds the
details sheet, search and **Resume**: a footer button and a details-sheet action
posting `resume` (headless, with the account) to `/action` and following the
redirect into the new live session. That is also how an archived conversation is
unarchived. Search covers this conversation (jumping to the hit) and all
sessions (live hits open the session page, archived ones this page). Archived
rows on the dashboard and archived search hits link here. The JSON
endpoints it uses (`archived_session_search.php`, `archived_session_attachment.php`,
`search_sessions.php`) are the shared API and stay. Antigravity conversations (no
headless form) show no Resume.

## Host-agent additions for the session page

Additive changes:

- `session_history` takes `include_thinking`. Only then are an assistant's
  reasoning blocks returned, as `{kind: "thinking", text}` blocks; a model that
  does not persist its reasoning writes empty ones, which stay dropped.
- `session_detail` carries `starred` and `resume_command` (the `cd ... && claude
  --resume <id>` line, with the account's `CLAUDE_CONFIG_DIR` and binary when the
  session runs under a work profile; Codex and OpenCode have their own forms,
  Ollama and Antigravity none).

- A headless Claude `AskUserQuestion` answer may carry a typed answer inside a
  multi-select list (`[1, {"text": "..."}]`); a blank one is refused.

The preview server talks to the installed host agent (the live checkout), so
these show up on it only after they are merged and deployed.

## Running a second instance

The container serves `public/` with `php -S` behind a bind-mount. To
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
