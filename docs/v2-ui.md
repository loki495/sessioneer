# UI v2 (`/v2`)

A mobile-first redesign served beside the classic UI. It reads the same
host-agent data and calls the same actions; nothing in the classic UI changes
except one "Try the new UI" link on its dashboard. `/v2` has a "Classic" link in
its app bar and a "Back to classic" button in Settings and Status.

The dashboard and the session page exist so far. A session card opens the v2
session page (which links back to the classic one); the archived-session and
file views move over in later steps.

## What it is made of

| Piece | Where |
|---|---|
| Routes `/v2`, `/v2/`, `/v2/session`, `/v2/history`, `/v2/fragment`, `/v2/archived`, `/v2/action` | `src/routes.php` |
| Pages, poll fragment, archived list, history, JSON action endpoint | `src/lib/Controllers/V2Controller.php` |
| Render helpers (card, counts, archived and bare rows, session page) | `src/lib/Views/V2View.php` |
| Templates | `src/partials/v2/` |
| Design tokens and the dashboard's styling | `public/css/v2.css` (hand-written, no build step) |
| Session page styling | `public/css/v2-session.css` (uses the same tokens) |
| Dashboard behaviour (tabs, filters, polling, actions) | `public/js/v2.js` (plain ES5, like the classic JS) |
| Session page behaviour | `public/js/v2-session.js` (transcript, polling, prompts, footer) and `public/js/v2-session-sheets.js` (controls, details, files, search, viewers) |

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
- **Depth.** Neutrals are slightly blue-tinted (page, card and inset shades differ), and one
  accent (`--accent`, with `--accent-soft` and `--accent-line` derived from it) draws
  structure: bar and footer rules, card and sheet borders, section titles, buttons and
  chips. Sheet headers stay fixed while the body scrolls. Each agent has a colour
  (`--agent-*`) used for its usage group tint. Starred sessions get a faint amber tint.
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
single-question prompts, star, rename, kill, move a terminal session to headless, new session (agent, account, runtime, model, starting mode, task
tools, an optional first message for headless and API agents, folder browser with new folder), "Hide idle unstarred", "Show worker
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

## Session page (`/v2/session?session=<name>`)

One column, three parts: a sticky bar (back with a badge counting other sessions
that need input, title with status and chips, search, details menu), the
transcript as the only scroller, and a footer (scroll buttons, controls summary,
message box). The shell follows the visual viewport, so the footer stays above
the iOS keyboard and a pinch-zoom does not resize it.

**Rendering.** The server renders only the shell and a bootstrap JSON (the
session detail, hex-escaped so transcript text cannot close the `<script>`); the
transcript comes from `/v2/history` (the classic `session_history` action plus
`include_thinking`) and is drawn by one client renderer for the first page,
"Load older", and the live tail. Each user and assistant message is a bubble or
free text with markdown (`markdown.js`, shared with the classic pages); every tool
call and its result is one collapsed `<details>` whose headline is the call's
description; pretty-printed JSON results; plan, question, subagent and
task-notification cards; inline images and attachments as thumbnails. Polling is
`session_detail` plus `session_history?after=<newest line>` on the same interval
setting as the dashboard, paused while the tab is hidden, and a rotated transcript
(`/clear`, `/compact`, `--resume`) restarts from its first page.

**Blocked prompts** (the panel above the footer, answered through the classic
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
  OpenCode `question` block inside the transcript all use the same builders, an
  answer shows an optimistic reply and is rolled back with the reason when the
  agent refuses it, and "Confirm before sending prompt answers" is a view option.

**Footer.** Send (reads Queue while the agent is working), Stop (interrupt),
attach files (uploaded to the project, removed again when the chip is removed),
a draft kept per session, Shift+Enter sends. Up and down buttons jump one message
at a time and skip tool calls; the bottom button shows a dot when new content
arrived off screen. The controls button opens model, permission mode and effort
(Claude), the model catalog (Codex with effort, OpenCode, Ollama) or the fixed
Antigravity list; each change posts to the classic endpoint and the next poll
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
tap to switch. Each row shows agent, account, runtime and context chips, folder and
age, and what the session waits on or its last message. A swipe right goes back in
history (the dashboard when there is none). Swipes work anywhere except where a
finger may be scrolling (a code block, a field), need 80px of mostly horizontal
travel, ignore the outer 24px (left to the browser's own back/forward gestures) and
are ignored while a popup is open. Every sheet (`public/js/v2-sheets.js`, both pages)
closes on a tap outside it or a swipe down from its fixed header.
The gauge button opens this session's context share and the usage meters (this
session's agent first, every Claude account when several are configured), rendered
by `public/js/v2-quota.js`, shared with the dashboard's Status tab: each agent a
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
rename, move a terminal session to headless (moving to a terminal is deprecated and
not offered), open in classic, kill. Then the task list; todo file (full
screen), plan and handoff files, uploaded files (open, delete, delete all); view
options: show reasoning text, show subagent calls, confirm answers, poll
interval. **Search** looks through this session (jumping to the hit, with a
"Back to latest" notice) or all sessions (live ones open in v2, archived ones in
the classic archived view).

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

Deferred: Web Push subscribe, the archived-session view (cards for archived
sessions still open the classic one), pending messages surviving a reload
(classic keeps them in localStorage), the "New content" divider and per-block
highlight colours of the classic page (new entries flash instead), and the full-screen
text editor for long drafts.

## Host-agent additions for the session page

Additive changes, none visible to the classic UI:

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
