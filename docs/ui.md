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
| Session-flow band (rotation and wait decisions) | `public/js/ui-flow.js` (plain ES5, mounts on `[data-flow-host]`) |
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
as they are: `/session_star.php`, `/session_rename.php`, `/session_tags.php`, `/answer_prompt.php`,
`/browse.php`, `/create_folder.php`, `/session_list_models.php`,
`/session_list_claude_profiles.php`, `/search_sessions.php`, `/quota.php`.

`POST /action` is the JSON action endpoint. It takes
`action` = `new`, `resume`, `switch_runtime`, `take_over`, `take_over_confirm`, `kill`, `kill_bare`, `cleanup`,
`install_hook`, `set_push_timer_interval`, `flow_rotate`, `flow_rotate_request`, `flow_continuation`, `flow_continue_rotate`, `flow_judge`, `flow_offer_dismiss`, `wake` or `wake_cancel`, plus `csrf_token`, and answers `{ok, message, redirect?}`. `wake_cancel` (`job`) removes one scheduled wake-up through the host agent's `launch_wake_cancel`; `GET /wakes` lists them (`session`, `compact=1`) or, with `job`, returns one full prompt. `take_over` (a
bare process) answers with a redirect to the new headless session, or
`{needs_choice, pid, workdir, candidates, suggested_agent_session_id}` when the process
could not be identified (nothing is stopped yet); the page then shows a picker and posts
`take_over_confirm`. Both reach the host agent's `take_over_bare` / `take_over_bare_with_id`
with `runtime=headless`, so the final resume goes through the headless runtime, not a pane.
The four flow actions forward to the host agent's same-named action. `flow_rotate`
(`session`, `handoff_id`, optional `agent` and `profile`) starts the offer's receiver and
answers with a redirect to the new session; it refuses a handoff id that is not digits,
because a receiver is only started from a stored handoff. `flow_rotate_request` (`session`)
sends the `/rotate` turn to the idle source, so the source writes its own handoff and
starts the receiver; it refuses unless the source is idle and has a conversation id.
`flow_judge` (`session`) makes the one small model call for a close quota call and answers
`{flow}` with the decision. `flow_offer_dismiss` (`session`) dismisses the session's live
offer. `/action` has the same same-origin and CSRF checks as every other mutating endpoint.

Session tags appear as chips on dashboard cards and in the session page's
hierarchy row, including when there are no parent or child links. The dashboard
tag select filters cards by one tag; its options are rebuilt from each poll's
listed sessions and the selected value is retained. The details sheet includes
a Tags row and **Edit tags** action, which replaces the list using
comma-separated input. The New Session form accepts optional comma-separated
tags and sends them with the create request. `POST /session_tags.php` accepts
`csrf_token`, `session`, `mode` (`add`, `remove` or `set`) and `tags[]`, returns
the host agent's JSON result, and forwards to `tags_set`; `POST /action` with
`action=tags` uses the same operation.

## The session-flow band

A session with a context or quota decision shows a band: on its dashboard card, under the conversation on the
session page, and in the session details sheet. That is one band per place, two on the session page, and both act
the same. The band draws a decision the host agent has already stored: the `flow` the `list` overlay attaches to
an idle row whose cache is cold or whose context is at `CTX_GO_MAX` tokens or more, or the decision a live offer
carries when the row has none. The overlay reads stored Claude and Codex context snapshots in a batch, so an idle row at the context threshold gets a band even while its cache is warm. Missing or malformed readings stay unknown; this list path does not read transcripts or query live managers for context. Nothing is sent to the host and no model runs until someone taps a button.

A `ROTATE` decision offers:

- **Start receiver**, only when the offer carries a handoff id, starts a fresh session from that handoff and
  opens it.
- **Hand off and start**, when there is no handoff id yet, and **Take over from transcript** both send one
  `/rotate` turn to the idle source, which writes its own handoff and starts the receiver. The page stays on the
  source, which is the session running that turn.
- **Continue here** keeps the session where it is.
- **Ask a small model**, only for a close call (a quota rotation whose reset is less than an hour away, with a
  receiver that has room), asks that receiver's model whether to wait for the reset or rotate now. It is the only
  button that calls a model, and it launches nothing by itself.
- **Edit in form** opens the dashboard with its New Session sheet prefilled in `sessionStorage`: the receiver's
  agent and account when the decision names one, otherwise the session's own, the session's folder, and the
  offer's prompt when it has one. It starts nothing.

`PARK` and `WAIT` offer **Wait for reset** (dismisses the offer) and, below it, one **Wait for reset: AGENT PROFILE, in 2 h 5 min**
button per profile with a stored reset time. The page render asks the host for those times (`flow_resets`) only when
a band is `PARK` or `WAIT`. Each button carries `data-flow-wait-reset`, `data-agent`, `data-profile` and
`data-reset-at`; nothing is posted on render, poll or dismissal. A tap posts `flow_schedule` with `approved: true`
(set by `UiController`, never by the page), `expected_reset_at` (the host refuses it as stale only when it is more than 120 seconds from the stored reset) and one context: the offer's handoff id or plan id
(with the session's folder), else the session (a wake of its conversation). The band then shows the job id and time,
or the host's refusal. For a wake (an offer with no handoff or plan) only the session's own agent and profile get a button, because the host refuses a wake whose session runs under another account; a handoff or plan offers every profile. `WARM_TIGHT` is one chip carrying the reason, with no button.
`GO`, `CHECKPOINT`, `DONE` and `UNKNOWN` draw nothing. **Continue here** and **Wait for reset** dismiss the
offer, which removes every band of that session. The session page is served with `Cache-Control: no-store` so a
browser never shows a stale band or reset time.

A tap disables that band's buttons and marks it busy, so the dashboard's poll does not replace the band under the
tap. A failed action shows its message inside the band and removes the message after ten seconds, so the poll
resumes; a started receiver opens its new session, and a dismissed offer takes its band away. The thresholds are
in [cli.md](cli.md#context-quotas-and-rotation).

## Dashboard coverage

Covered: live session list with status/agent/account/runtime/worker/starred
state and ordering, needs-input / working / idle filters, one-tap answers to
single-question prompts, star, rename, kill, take over a terminal session as headless, new session (agent, account, model, starting mode, task
tools, an optional first message, folder browser with new folder), "Hide idle unstarred", "Show worker
sessions", poll interval, kill inactive > 12h, bare-process list with Take over (headless) and kill, scheduled wake-ups fold (hidden when empty) with full prompt and Cancel, a clock chip on cards with a pending wake-up,
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

**No zoom.** Every page sets `maximum-scale=1, user-scalable=no` in its viewport meta and
`touch-action: pan-x pan-y` on `html`; the layout also cancels the `gesture*` events, which iOS
Safari raises for a pinch even when the meta tag forbids it.

**Voice input.** `public/js/ui-voice.js` adds a mic button to the session footer and a **Dictate the
first message** button to the New session form; both stay hidden when the browser has no
`SpeechRecognition`/`webkitSpeechRecognition`. One tap starts a hands-free flow:

1. **Listen.** Speak the whole message. Recognition that ends on its own (iOS does after a pause) is
   restarted and the pieces are joined.
2. **Stop on a pause.** After the chosen silence (2, 3 or 5 seconds, default 3, kept as
   `v2-voice-pause`; "Never" means tap **Stop**) listening ends. Ten seconds with no speech at all also ends it.
3. **Read back.** The transcript is spoken with `speechSynthesis` (`v2-voice-readback`, on by default) and is editable. If the browser has not started speaking after 2 seconds, the panel says so and points at
   the iPhone ringer switch and volume.
4. **Spoken command.** After the read back the panel listens once for a short phrase (`v2-voice-commands`,
   on by default): "send" (also "yes", "confirm", "go ahead"; on the form "use it"), "add more" or "continue",
   "clear" or "retry", "cancel". It only accepts up to four words and ignores any phrase with a negation, so
   "don't send" never sends. Three listening attempts of 8 seconds each (a no-speech or aborted error counts as heard nothing and listens
   again; any other error is named in the status); after that the buttons remain.
   The buttons (**Send** / **Use this text**, **Add more**, **Clear**, **Read back**) always work.

A large banner at the top of the panel shows the current mode in words and colour: **Starting the
microphone**, **Listening** (red, pulsing dot), **Reading it back** (blue), **Your turn: say send, add
more or clear** (bold, fast pulse), **Paused: choose below** or **Not listening** (error). **Add more**
and **Clear** wait 0.4 seconds before starting the next recognizer, because iOS refuses one that starts
while the previous is still closing; a start that still fails is retried once.

Nothing is sent until you confirm. On the form **Use this text** only fills the first message; **Start
session** still creates the session. A blocked or failing recognizer shows its error code and where to
allow it: a web page cannot open iOS Settings, and home-screen apps have historically not offered
recognition, in which case the keyboard's own mic key still works.

**Keyboard dictation prompt.** The same script watches the session message box and the form's first
message. The browser gives no "dictation stopped" event, so an input that adds at least 8 characters at
once (not a paste, drop or delete) counts as dictation; after the same pause a bar offers **Send** (session
page only), **Add more** and **Clear**. One-character typing, pastes and edits never show it, and typing
after a phrase hides it.

**Wrapping.** Transcript entries, tool-call headlines and code frames wrap long unbroken text
(`overflow-wrap: anywhere`) instead of widening the page. Code blocks (fenced text and tool output) do
not wrap: they scroll inside their own frame, sideways for long lines and up to 16rem tall. The Details sheet's
View section has **Wrap long lines in code blocks** (off by default, kept in `localStorage` as
`v2s-wrapcode`) to wrap them instead.

**Code blocks.** Every fenced block in message text gets Copy and Full screen, however short.
Inline code and quotes are not fenced and get no buttons.

**Scroll buttons.** Up and down step to the previous or next visible user or assistant message
(tool calls are not stops). Down treats a message that cannot reach the top edge as the end, so it
cannot stall on the last messages, and a landed message counts as passed even when it reads a
fraction of a pixel low (`NAV_SLACK`). Up loads older messages at the top. Last jumps to the newest
message; on an archived page opened at a search hit it first loads every later page.

**Rendering.** The server renders only the shell and a bootstrap JSON (the
session detail, hex-escaped so transcript text cannot close the `<script>`); the
transcript comes from `/history` (the `session_history` action plus
`include_thinking`) and is drawn by one client renderer for the first page,
"Load older", and the live tail. An archived page opened on a search hit (`jump_line`) ends at the hit and has a **Load newer** button that calls `/archived-history` with `after` set to the last line shown (50 at a time; a short page hides the button, since `after` carries no `has_more`). Archived pages never poll. Each user and assistant message is a bubble or
free text with markdown (`markdown.js`); every tool
call and its result is one collapsed `<details>` whose headline is the call's
description; pretty-printed JSON results; plan, question, subagent,
task-notification and agent-message cards; inline images and attachments as
thumbnails. Polling is
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
popup of every session: starred first, then needs-input, working and idle, newest
activity first within each tier (starred rows are faintly amber), a filter box, a star toggle per row,
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
The session details popup header has icon-only Sessions (swaps to the sessions popup) and Star buttons plus Rename and Kill; the open session is highlighted and flagged "Current" in the sessions popup. Each row also shows the session's working directory, with the home directory written as `~`. Two dropdowns under the filter box narrow the other sessions by agent and by profile (Claude accounts); their options come from the rows, and the choice is kept in `localStorage` so it survives reopening the popup and reloading the page. The open session stays pinned whatever the dropdowns say.
The details popup's Actions section has **Wake up session at…** for any session with a conversation id: it opens a form with a date-time picker and a prompt prefilled with `continue`, converts the time to a unix time in the browser, and posts `wake` to `/action`, which sends the CLI's `launch_wake` (an `at` job, see docs/cli.md `--wake`). Under that form, `#sess-wakes` lists this session's pending wake-ups, fetched from `GET /wakes?session=…&compact=1` when the sheet opens and after a schedule or cancel. Each row has **Show full prompt** (loaded on first use, shown as text) and **Cancel**, which asks for confirmation with `window.confirm` before posting `wake_cancel`. Each row links to the session with its title (the card's title cascade; the session name or the last 8 characters of the conversation id follow it in muted text). The dashboard's wake-up fold uses the same rows and buttons; `public/js/ui-wakes.js` holds the handlers both pages share. It also has **Take over in new session** for any session with a conversation id. It launches nothing: it opens the dashboard's New Session form prefilled and editable. The prefill travels in `sessionStorage` (read once), never in the URL, so a link from another site cannot prefill a prompt or folder. The agent and account come from the stored flow decision's receiver, with no model call (the session's own when that decision names none), the model is carried over only when agent and account are unchanged, the folder is the session's, and the first message is `/takeover <agent> [profile] <conversation id>`.
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
sheet; tapping toggles fit and natural size (scroll to pan). Pinch zoom is off. "Open original" opens the file.

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

### Session hierarchy

Rows carry `launched_by` and `waiting_on` (see docs/cli.md, "Hierarchy fields"). On the dashboard a card whose `launched_by` names another listed card sits directly under it (`ui-card-child`, `data-parent`, indented up to three steps; deeper rows keep their real `data-parent` at the third step). A child whose parent is not listed stays at the top level with its `spawned by` link, and a launch cycle renders every row once. Each waiting target is a `waiting on NAME` chip (`ui-chip-waiting`, greyed with `is-ended`, a muted colour with 4.5:1 contrast, plus a `waiting on NAME (ended)` title and aria-label, when the target is not live; an archived session reads `unknown`) linking to that session. The sessions popup nests and badges rows the same way, keeps a family together despite the status sort and pins the open session on top. The session page shows `Launched by`, `Launched:` and the badges as links, in a line under the bar and in the details sheet.

### Quota tint

A row whose `quota_exhausted` is exactly `true` (docs/cli.md, "Quota field") gets a dim red background so you can see which sessions cannot work until a reset. The dashboard card carries class `ui-quota-out` and `data-quota="out"`; the sessions popup row (`.sess-srow`) carries the same. `false`, `null` and a missing field render plain. Text keeps 4.5:1 contrast on the tint (3:1 for the blocked status bar), the status dot and star are no worse than on a healthy card, and the current popup row keeps its inset outline. The tint changes the background only: borders, outlines, the status bar and the nesting accent stay as on an untinted row. The quota reading behind it is cached for 30 seconds (docs/cli.md, "Quota field"), so polls do not pay the roughly 0.5 s cost of reading it.

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
- `session_detail` includes `tags`, a list of normalized strings, empty when
  the session has no tags. The dashboard `list` rows carry the same field.

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

The Details Actions section checks `flow_continuation` once when opened and recommends Rotate session or Take over in new session, with a reason. A live idle source with a readable empty claims list and a current handoff or plan recommends rotation; unavailable or unsafe facts recommend takeover. The check is read-only. Rotate posts `flow_continue_rotate`, which checks the source, claims and offer again before starting a receiver; an idle source without ready context or clear claims is asked to prepare rotation instead. Both choices remain available, including on archived conversations, where rotation is refused if the source is unavailable.
