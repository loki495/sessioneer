# `sessioneer` command line

`bin/sessioneer` starts, resumes, takes over, shows, answers and stops Sessioneer sessions from a shell, so a script, a subagent or a running session can spawn a continuation and drive it to the end, and you can still watch and answer it in the dashboard like any other session. It is host-side only: it talks to the host agent's socket (`$XDG_RUNTIME_DIR/sessioneer-agent.sock`, or `SESSIONEER_AGENT_SOCKET`), so access is whatever that socket's permissions allow: any process running as the same user (or in the socket's group) can launch sessions. No login, no token.

```bash
# a new headless Claude session with a first prompt
sessioneer --workdir ~/www/some/project --model sonnet --mode plan "Review the failing test and propose a fix"

# continue a conversation (reuses the live session if there is one)
sessioneer --resume <claude-conversation-id> "Now apply the fix"

# run to completion: block until the session is idle, print its final message
sessioneer --wait --timeout 600 --workdir ~/www/some/project "Summarise the open TODOs"

# last known usage stats for every agent and Claude account, as JSON
sessioneer --usage

# what should this session do about its context and quota? (reads state, never acts)
sessioneer --next

# every tracked session, one line each
sessioneer --next --all

# start a fresh session from a Dibs handoff or plan; the source keeps running
sessioneer --rotate --handoff 4486
sessioneer --rotate --plan 1762 --agent codex --profile work

# offer that rotation to the dashboard, or withdraw it
sessioneer --offer --handoff 4486
sessioneer --offer --clear

# prompt from stdin, full reply as JSON
echo "Summarise the open TODOs" | sessioneer --json -

# a session is blocked on a prompt: read it, answer it
sessioneer --show claude-headless-20261103-101500
sessioneer --answer claude-headless-20261103-101500 --option 1 --expect Yes

# a Claude running in a terminal Sessioneer did not start: list it, take it over
sessioneer --bare
sessioneer --take-over 12345
```

| Option | Meaning |
|---|---|
| `PROMPT` / `-` | The message to deliver; `-` reads stdin. Required for a new session, optional with `--resume`. |
| `--agent NAME` | Agent to launch: `claude` (default), `codex`, `ollama` or `opencode`; see "Codex", "Ollama" and "OpenCode" below. |
| `--resume ID` | Existing conversation id. |
| `--workdir DIR` | Working directory. Default for a new session: the current directory. With `--resume`: the directory recorded in the transcript (for OpenCode, the directory the serve records when the id is already there). |
| `--model NAME` | Model for a new session. Required for `--agent ollama`, which has no default: it must be one the configured Ollama server has pulled. For `--agent opencode` it is `provider/model` (for example `opencode-go/gpt-6.1`); without it the serve's default model is kept. |
| `--effort LEVEL` | Reasoning level for a new or resumed session, so a launch names agent, model and effort together. Claude: `low`, `medium`, `high`, `xhigh` or `max` (`claude --effort`, fixed when the process starts and re-applied whenever the session respawns after going dormant; the CLI itself rejects a level the model does not support). Codex: a level the chosen model lists (the default model when `--model` is absent), set on the thread with `thread/settings/update` before the prompt, so it also retunes a Codex thread you reuse or resume. An unknown level, a model without it, or a Claude session that is already tracked (its effort cannot change) is refused. Not offered for `--agent ollama` or `--agent opencode`, and refused with `--kill`, `--list` and `--peers`. |
| `--mode MODE` | Permission mode for a new session (for example `plan`). Not validated by the CLI: for Claude, a value that isn't a known permission mode (`manual`, `accept edits`, `plan`, `auto` or `dontAsk`) is ignored and the session starts in the default mode; for Codex, only `plan` and `full` are accepted (see "Codex" below). `dontAsk` (Claude `--permission-mode dontAsk`) denies every tool call that `--allowed-tools` or the user's settings have not pre-approved, so an unattended session never blocks on a prompt. It can only be chosen at launch: it is not in the dashboard's mode switcher. Not offered for `--agent ollama` or `--agent opencode`. |
| `--profile NAME` | Claude account profile (see `host-agent/config/agents.php`). Without it, the session runs under the profile whose `config_dir` matches the caller's `CLAUDE_CONFIG_DIR`, so a session started from a work-account shell stays on that account; no `CLAUDE_CONFIG_DIR` means the default account (the launched session runs with the variable unset, whatever the manager inherited), and a dir that matches no profile is refused. |
| `--task-tools` | Enable the task tools in a new session. Claude only; not offered for `--agent ollama` or `--agent opencode`. |
| `--no-tools` | Start a new session with no tools at all: no built-in tools (`--tools ""`) and no MCP servers (`--strict-mcp-config`), so it can only answer in text. The setting is remembered, so the session stays tool-less when it is resumed after going dormant. Only for a new session: refused with `--resume`, `--kill`, `--send`, `--list` or `--peers`, and not combinable with `--task-tools`. Claude only; not offered for `--agent ollama` or `--agent opencode`. |
| `--allowed-tools LIST` | Claude only, new session only. Comma separated tool rules to pre-approve, passed as one argument to `claude --allowedTools` (additive to the user's settings; `--task-tools` joins the same list), for example `"WebFetch,Bash(bun run .agents/skills/*),Write(job_scraper/**)"`. The list is validated on the host agent: letters, digits, spaces and `, ( ) * . _ / : @ ~ = + # % ! ? [ ] -` only, no leading `-`, at most 2000 characters; quotes, backticks, `$`, `;`, `|`, `&`, backslash and newlines are refused. Remembered, so a dormant respawn re-applies it. Refused with `--resume`, `--kill`, `--send`, `--list`, `--peers` and `--no-tools`, and for `--agent codex`, `ollama` and `opencode`. |
| `--disallowed-tools LIST` | Same as `--allowed-tools` but passed to `claude --disallowedTools`: the rules are always denied. Same validation and restrictions. |
| `--kill TARGET` | Stop a session, by session name or conversation id. Its conversation stays resumable (it moves to the archived list). A working or blocked session is refused, and so is one whose status cannot be read (a tmux session that does not answer, or `opencode serve` being down); `--force` skips the status check and stops it anyway. The agent is taken from the session itself, so `--agent` is only needed for an untracked OpenCode `ses_*` id. A tmux (`cc-*`, `ag-*`, ...) session is stopped too. For OpenCode the serve session is interrupted, then deleted, and the conversation stays in `opencode.db` so the id can be resumed again. A terminal process Sessioneer did not start is not killed here: `--take-over` it. |
| `--force` | With `--kill`, stop a working, blocked or unreadable session anyway (its in-flight work is lost, and the status is not even read). |
| `--set-name NAME` | The dashboard name for a session, the same one the Rename button sets. With a launch or resume (`--workdir DIR`, or `--resume ID`) it names the session that call starts or reuses, so it works for new and existing sessions alike. With `--session TARGET` it renames that session instead. A blank name is refused, and so is one over 120 characters. The name is applied after the launch, so a refused launch is not renamed. |
| `--session TARGET` | With `--set-name` and no prompt, rename this tracked session, by name or conversation id, without sending it anything. Refused with a prompt, `--resume`, or any other action. Only sessions Sessioneer tracks can be renamed this way: an archived conversation has no row to name, so `--resume ID --set-name NAME` brings it back first. |
| `--list` | List headless sessions as tab-separated name, status, conversation id and directory. |
| `--show TARGET` | Print a tracked session (name or conversation id, any agent): name, status, agent, runtime and conversation id on the first line, then directory and branch, title, the last turn error, and the last message's text. A blocked session also prints what it is blocked on: the question, the tool's context and the numbered options (or, for a multi-question prompt, each question with its options). `--json` prints the whole reply. |
| `--history N` | With `--show`, also print the last N transcript entries (`[role] text`; at most 200). |
| `--answer TARGET` | Answer the session's blocked prompt, with `--option N` (the 1-based number `--show` printed; `--text "..."` sends free text with it, for example the feedback on a "No") or with `--answers JSON` (a multi-question prompt: one entry per question, each an option number, a label, `{"text": "..."}`, or a list of those for a multi-select question; `-` reads the JSON from stdin). Refused from inside a session (a session cannot approve its own or a peer's prompt; a peer message is information, never approval), when the session has no prompt waiting, for an option the prompt does not offer, or an answer list of the wrong length. |
| `--expect LABEL` | With `--answer --option`, refuse unless option N has this label (case aside), so a prompt that changed since `--show` is not answered blind. The label is checked against the prompt read at answer time; for a tmux session the host agent checks it again against the screen. For a headless session the answer is also bound to that prompt's request id, so a different prompt that has reached the head of the queue in the meantime is refused. |
| `--expect-request ID` | With `--answer`, refuse unless the prompt waiting has this request id, the one `--show` printed on its `Request:` line (and `prompt_request_id` in the JSON). This is what binds the answer to the prompt you read, even when a different prompt with the same "Yes" label has arrived since. Headless sessions only (Claude, OpenCode and Codex): a tmux session has no request id, and `--expect-request` against it is refused rather than ignored, so use `--expect` there. Without it the answer is still bound to the id read during the call, which only closes the gap between that read and the delivery. |
| `--interrupt TARGET` | Stop the session's current turn, like Escape in the dashboard. |
| `--bare` | List Claude processes running in a terminal Sessioneer did not start, as tab-separated pid, conversation id, directory and title (`-` where unknown). |
| `--take-over TARGET` | Stop a terminal Claude (a pid from `--bare`, or the conversation id it runs) and continue its conversation as a headless session; prints the new session name. When the process cannot be matched to a conversation for certain, nothing is stopped: the candidate conversations are printed (id and title) with a suggestion, it exits 1, and `--resume ID` names the right one. `--resume ID` is refused unless the process is not matched for certain and ID is one of those candidates; a process whose conversation is known is only taken over for that conversation. |
| `--send TARGET` | Deliver the prompt to another session (name or conversation id) from inside a session. The host agent prepends `[sessioneer peer: <name> · <workdir> · <branch>]` from its own records of the sender, so the message body cannot set it. A blocked peer accepts it and sees it when it resumes. Refused outside a session, for an unknown target, a blank message, or a message to yourself. |
| `--as-user` | With `--send`, from a shell outside any session: deliver the message as the user's own, with no peer stamp, to any tracked session (any agent). Refused inside a session, so a peer cannot pass as the user. |
| `--whoami` | Print this session's own name (`SESSIONEER_SELF`, or `CODEX_THREAD_ID` inside Codex); exits 1 outside a session. |
| `--peers` | List the other live sessions in this session's git repository (worktrees included) as tab-separated name, status, branch and directory. |
| `--usage` | Print the last known usage (quota) stats as JSON and exit 0: `agents`, one entry per agent (`claude`, `antigravity`, `opencode`, `codex`), each with a `label` and a `profiles` map. Claude's configured accounts are the profiles (the default account keyed `personal`); an agent with one account has a single `default` profile. Each profile has `ok`, `quota`, `fetched_at` (unix time, or null) and `message` (why there is no data yet); there is no quota above the profiles, so read `agents.claude.profiles.work.quota`. Read-only: it returns what the dashboard footer shows and never refreshes anything. Exits 1 if the host agent is unreachable. |
| `--next [TARGET]` | Decide what a session should do about its context and quota, and print it without acting. TARGET defaults to the calling session (`SESSIONEER_SELF`, or `CODEX_THREAD_ID` inside Codex). The state is `GO`, `WARM_TIGHT`, `CHECKPOINT`, `ROTATE`, `PARK`, `WAIT`, `DONE` or `UNKNOWN`; the reply also carries `why`, the `trigger` (`context`, `quota`, `cold_cache` or `none`), `close_call`, the `receiver` for a rotation, `reset_at`, `needs_approval` and any threshold warnings. The facts are stored numbers, no model is called, and the session is never woken: the session's context size (falling back to its transcript tail when a Claude sidecar has none), its idle time, and the session and weekly quota readings `--usage` shows. The first rule that matches decides: `DONE` with `--intent done`; `UNKNOWN` when it can read no facts at all; `PARK` when the session's account is at `QUOTA_PARK` or more and no other account has room; `WAIT` with `--intent wait` or while the session is blocked; `ROTATE` at `CTX_ROTATE` tokens or more, at `QUOTA_PARK` or more with a receiver, or with a cold cache after `IDLE_MIN` minutes and `IDLE_FLOOR` tokens or more; `CHECKPOINT` with `--intent step-end` while running tight; `WARM_TIGHT` from `CTX_GO_MAX` tokens or `QUOTA_GO_MAX` quota; otherwise `GO`. A receiver is an `agent/profile` account whose session and weekly quota are both known and below `QUOTA_GO_MAX`, Codex and OpenCode before another Claude account. Plain output is one tab-separated line: name, state, reason, receiver and prompt kind. Exits 0 for every state, `UNKNOWN` included, and 1 when the target is unknown or the host refuses. See [Context, quotas and rotation](#context-quotas-and-rotation). |
| `--all` | With `--next` and no TARGET: decide every tracked headless session and print one line each in the same fields. It never posts an offer, even with `--post`. |
| `--intent STEP` | With `--next`, what the caller is about to do: `step-end` makes a tight context or quota a `CHECKPOINT` instead of `WARM_TIGHT`, `wait` returns `WAIT` because an answer or an external event is pending, and `done` returns `DONE`. Any other value is refused. |
| `--post` | With `--next`: post the offer the decision calls for to the dashboard (in `session_offers`, live for 24 hours), or clear the offer a hook posted earlier when the decision no longer calls for one. `ROTATE` and `PARK` post; every other state clears; `UNKNOWN` posts nothing. A hook-made offer never replaces a live agent-made one. |
| `--handoff ID` | With `--next`, `--rotate` or `--offer`: the Dibs handoff id (a task id, digits only) that the fresh session takes over from. It becomes the receiver's prompt: read the handoff, claim the task, re-verify its state, then continue its next steps. |
| `--plan ID` | With `--next`, `--rotate` or `--offer`: the Dibs plan id, digits only, the fresh session continues from. `--handoff` and `--plan` cannot be combined. |
| `--rotate [TARGET]` | Start a fresh receiver session for TARGET (default: the calling session) from exactly one of `--handoff`, `--plan` or `--prompt`. The source keeps running: it is never archived or stopped here, and the receiver does that after its own takeover checks. `--agent`, `--model`, `--effort`, `--profile`, `--workdir` and `--set-name` shape the receiver. Without them it keeps the source agent, workdir, effort and profile, the permission mode where the receiver can reproduce it, and the tool rules when it stays on the same agent; the receiver must be Claude or Codex, so name `--agent` for any other source. A cross-agent rotate refuses an explicit `--model` or `--effort` and a source with tool rules, and a tool-less source cannot rotate at all. Before launching, it checks the source for Dibs claims through `SESSIONEER_CLAIMS_CMD`, takes a per-source lock, and starts the receiver through the launch path. Prints the receiver's name on stdout; host warnings go to stderr. A refusal exits 1 and leaves the source as it is. |
| `--prompt TEXT` | With `--rotate`: a self-contained prompt of 1 to 8000 characters that the receiver gets with no earlier conversation. With `--offer`: the same text as the last argument. |
| `--dry-run` | With `--rotate`: print the receiver line (`Receiver: <agent>`, with its account when there is one) and the prompt, and start nothing. |
| `--offer (--handoff ID, --plan ID or TEXT)` | Post an offer to rotate the calling session to the dashboard without launching anything. The host stores the decision it makes now, the handoff or plan id, and the prompt; the flow band on the dashboard card and the session page then shows it, and the offer expires after 24 hours. It never replaces a live agent-made offer. |
| `--clear` | With `--offer`: dismiss the calling session's live offer, so its band goes away. Exits 1 when there is none. |
| `--wait` | With a launch or resume: block until the session is idle, then print the text of its final assistant message on stdout (the session name goes to stderr). Exits 1 if the launch is refused, the turn reports an error, or `--timeout` runs out. With `--agent codex` it also waits until the last message is the assistant's reply, because a new Codex thread reads idle for a few seconds before its turn starts (an interrupted turn therefore waits until `--timeout`). For `--agent opencode` the idle check uses the serve's live `/api/session/active` map, so a turn that just started is not mistaken for a stale idle. A session waiting on a permission prompt is not idle, so it keeps being waited on: read it with `--show` and answer it with `--answer` (see [Answering a blocked session](#answering-a-blocked-session)), or set `--timeout`. Not combinable with `--kill`, `--list`, `--send`, `--peers`, `--show`, `--answer`, `--interrupt`, `--bare` or `--take-over`; both that and a bad `--timeout` are refused before anything is launched. A prompt that is the name or conversation id of an existing session is refused the same way, because it would otherwise start a new session named after that prompt; read the existing session with `--show`. A final message with no text (only tool calls) prints an empty line. |
| `--timeout N` | With `--wait`, give up after N seconds (a positive integer). Default: no limit. The session keeps running after a timeout. |
| `--json` | With `--wait`: print one JSON object on stdout instead of the plain text, on success and on failure alike — see [For programs](#for-programs). Otherwise: print the host agent's reply as JSON instead of the plain output, which is how `--next` returns the whole decision. Everything human stays on stderr. |

On success it exits 0: a launch, resume or take-over prints the session name (the dashboard opens it at `/session?session=NAME`), `--next` prints its decision, `--rotate` the receiver's name, and `--offer` one confirmation line. Any refusal prints `sessioneer: <message>` on stderr and exits 1.

## Context, quotas and rotation

`--next` answers one question with numbers, not a model: continue here, checkpoint at a step end, rotate to a fresh session, or wait. Its facts are the ones the dashboard already has: the session's context size and idle time from its sidecar and transcript, and its account's session and weekly quota readings from `--usage`. It never wakes the session, and without `--post` it changes nothing.

The thresholds live in one file that the hooks read too, `~/dotfiles/ai/skills/session-flow/thresholds.conf`. These are the keys the decision reads:

| Key | Default | Meaning |
|---|---:|---|
| `CTX_GO_MAX` | 150000 | From this many context tokens, the decision is `WARM_TIGHT` or `CHECKPOINT`, not `GO`. |
| `QUOTA_GO_MAX` | 80 | From this percentage in either quota window, the decision is `WARM_TIGHT` or `CHECKPOINT`, and a receiver must be below it in both windows. |
| `CTX_ROTATE` | 250000 | At this many context tokens the decision is `ROTATE`. |
| `QUOTA_PARK` | 90 | At this percentage in either window the decision is `ROTATE` to a receiver, or `PARK` when none has room. |
| `IDLE_MIN` | 60 | Minutes of idleness after which the prompt cache counts as cold. |
| `IDLE_FLOOR` | 100000 | With a cold cache, this many context tokens or more means `ROTATE`. |

`SESSION_FLOW_THRESHOLDS`, in the host agent's environment, sets another path. A missing or unreadable file, an unknown key, or a value that is not a whole number leaves the built-in default in place and adds a warning, which the CLI prints on stderr.

`--rotate` checks the source for Dibs claims before it starts anything, so work is not abandoned behind a fresh session. `SESSIONEER_CLAIMS_CMD`, in the host agent's environment, is a shell command that the host agent runs as `sh -c '<command> "$1"' sh <conversation-id>`; it prints JSON, either a list of claims or `{"claims": [...]}`. An empty list lets the rotate proceed; any claim, a failing command, or output that is not JSON refuses it and leaves the source as it is. When the variable is unset, the check is skipped and the reply carries a warning saying so.

Exit status is 0 whenever `--next` can decide, `UNKNOWN` and `PARK` included, and when `--rotate` starts a receiver or prints a dry run, or `--offer` posts or dismisses an offer. A refusal or an error exits 1 with `sessioneer: <message>` on stderr: an unknown target, an unreachable host agent, a claims check that fails, or an option that does not combine. A bad decision is never an error.

## What `--resume` does

1. A session Sessioneer already tracks on that conversation (headless, dormant or tmux) **receives the prompt**; no second process is opened on the transcript. Without a prompt, nothing is sent.
2. Otherwise the conversation is resumed as a headless session and the prompt is delivered.
3. If the conversation is running in a terminal Sessioneer did not start, it is refused: use `--take-over` (or **Take over** in the dashboard), which stops that process.

Resume and take over are headless by default; a tmux pane is an opt-in of the dashboard's own actions and the CLI never opens one.

## Answering a blocked session

An unattended session can stop on a permission or a question. `--wait` keeps waiting on it, so read it and answer from another shell:

```bash
sessioneer --show NAME                       # what it asks, with numbered options
sessioneer --answer NAME --option 1          # allow
sessioneer --answer NAME --option 3 --text "use the other file"    # "No" with feedback
sessioneer --answer NAME --answers '[2]'     # a question: the second option
```

`--answer` is for a shell outside any session. Like `--as-user` it relies on the caller's `SESSIONEER_SELF`, so it stops a session from doing the user's part by accident or by an injected instruction, not a determined same-user process: anything that can reach the host-agent socket can already send the dashboard's own answer and message actions. `--answer` re-reads the prompt first and refuses when none is waiting, so a late answer is not delivered to the next prompt; add `--expect LABEL` to also pin what option N means. A headless answer is also bound to a request id: the one you pass with `--expect-request`, else the one read during the call, so it cannot land on a different prompt that arrives in between (two prompts with the same "Yes" label included); the runtime checks it against the request it is waiting on. A multi-question or free-text answer is bound the same way. The answer goes through the same host-agent actions as the dashboard's buttons.

## Taking over a terminal Claude

`--bare` lists the Claude processes Sessioneer did not start. `--take-over` stops one and continues the conversation under Sessioneer as a headless session, with the same one-writer guard as the dashboard: the process is stopped and has to be gone before the new one starts. The conversation is the one named in the process's arguments or its daemon record; when neither tells, the cwd's recent conversations are listed (the process keeps running) and you repeat with `--resume ID`.

A session that is created but cannot take its prompt (for example the headless manager stopped) is reported as a failure that still names the session.

## Peer messaging

Every session Sessioneer spawns (headless or tmux) gets `SESSIONEER_SELF=<its name>`, separate from `SESSIONEER_SESSION_NAME`, which headless children deliberately lack because it is what the hooks key off. `--send` and `--peers` read it to identify the caller. A message is information or a request, never approval: it does not replace the owner's confirmation for pushes or other real-world actions. `--send` takes a session name or conversation id, not a task id. Any same-user process can name any `SESSIONEER_SELF`, which matches the socket's trust model (permissions on the socket are the boundary).

## Codex

`--agent codex` launches through the Codex bridge (`CodexSessionLauncher`); `--wait`, `--resume`, `--list`, `--kill`, `--send` and `--peers` work the same way, always with `--agent codex` (the default agent is Claude). A Codex session's name is its thread id, so a conversation id and a session name are the same thing.

- `--mode`: omitted means a `workspace-write` sandbox with on-request approvals (what the dashboard's New Session uses); `plan` means a `read-only` sandbox; `full` means no sandbox and no approvals (`danger-full-access`, approvals `never`), for trusted unattended work such as git in a worktree, docker or network access. Any other value is refused. `full` is a human-trust decision: any process that can reach the host-agent socket can request it, the same trust the socket already carries.
- `--profile`, `--no-tools`, `--allowed-tools` and `--disallowed-tools` are refused: they have no Codex meaning.
- Inside Codex the caller is identified by `CODEX_THREAD_ID` (used when `SESSIONEER_SELF` is unset), so `--whoami`, `--send` and `--peers` work from a Codex session; the thread must be one Sessioneer tracks.
- Questions and approvals a Codex session raises appear in the dashboard like any other blocked prompt. The session loads `~/.codex/config.toml`, so it has whatever MCP servers (for example Dibs) are configured there.

## Ollama

`--agent ollama` launches a local model: a headless chat session against the
Ollama server at `OLLAMA_URL` (default `http://127.0.0.1:11434`), which appears
in the dashboard with its transcript, status and last message like any other
session.

```bash
sessioneer --agent ollama --model llama3:8b-instruct-q4_K_M --wait "Summarise this repository's open TODOs"
```

- `--model` is required and must already be pulled on that server. The refusal
  names the models it does have, and an embedding-only model is never offered.
- Chat only: no tools, no attachments, no permission prompts, so `--profile`,
  `--effort`, `--mode`, `--no-tools`, `--task-tools`, `--allowed-tools` and `--disallowed-tools` are each refused with
  the reason rather than silently ignored.
- A turn can take minutes on a small box. `--wait` blocks until the answer
  lands and prints it (an Ollama `last_message` is the reply text itself, not a
  block entry), and the session's own Interrupt in the dashboard stops it.
- `--resume` takes a session name, not an agent conversation id, and reuses only
  a session this app still tracks: a local model has no agent-side archive, so
  a killed conversation cannot be resumed from its id. `--kill` stops the
  session and removes it from the dashboard; its transcript file stays under
  `$SIDECAR_DIR/ollama/` with nothing in the app pointing at it.
- No quota data exists for a local model, so `--usage` carries no Ollama row.

## OpenCode

`--agent opencode` launches through `opencode serve` (`OpenCodeSessionLauncher`);
`--wait`, `--resume`, `--list`, `--kill`, `--send` and `--peers` work the same
way, always with `--agent opencode`. A session's name is its `ses_*`
conversation id, so a conversation id and a session name are the same thing.

```bash
sessioneer --agent opencode --workdir ~/www/some/project --model opencode-go/gpt-6.1 --wait "Summarise the open TODOs"
```

- A new session needs an existing absolute workdir and a prompt. `--model` is
  `provider/model`; without it the serve's default model is kept. The model is
  applied with the session-scoped model endpoint before the prompt is sent.
- `--resume` takes a `ses_*` id. A session Sessioneer already tracks receives
  the prompt; an untracked id is resumed through the serve's own session
  endpoint in the directory you give (or the one the serve records), so the
  conversation continues rather than forks.
- `--kill` interrupts, then deletes the serve session. The conversation stays
  in `opencode.db` and can be resumed by id; a working or blocked session needs
  `--force`.
- `--profile`, `--effort`, `--mode`, `--no-tools`, `--task-tools`,
  `--allowed-tools` and `--disallowed-tools` are
  refused: a serve session has no account profile, reasoning-level control,
  permission-mode vocabulary or tool-set flags.
- `--send` and `--peers` work like the other agents, but only for a session
  Sessioneer tracks (a serve session adopted into a sidecar).

## For programs

`--wait --json` is the one-shot contract a program should use: stdout is exactly
one JSON object, so nothing has to be read out of prose.

```bash
echo "Classify this file: ..." | sessioneer --wait --json --timeout 60 --no-tools --workdir ~/www/some/project --agent claude -
```

```json
{
    "ok": true,
    "answer": "document",
    "session": "claude-headless-20261030-101500",
    "conversation_id": "0f0e1d2c-aaaa-bbbb-cccc-1234567890ab",
    "agent": "claude",
    "model": "sonnet",
    "workdir": "/home/andres/www/some/project",
    "exit_code": 0,
    "error": null
}
```

| Key | Meaning |
|---|---|
| `ok` | `true` when the turn finished and `answer` holds its text. |
| `answer` | The final assistant text, verbatim (no added newline); `null` on failure. |
| `session` | The session this call created, or `null` when the launch never got that far. |
| `conversation_id` | That session's agent-side conversation id, when the CLI could read it. |
| `agent` | The agent the session runs on. |
| `model` | The `--model` the call named, or `null` when it named none. |
| `workdir` | The session's directory. |
| `exit_code` | `0` or `1`, matching the process status. |
| `error` | `null` on success, otherwise `{"kind": ..., "message": ...}`. |

`error.kind` is one of `launch_refused` (nothing started), `turn_error` (the turn
started and failed), `timeout` (`--timeout` ran out; the session keeps running),
or `session_error` (the session could not be read while waiting). An option
error — a bad `--timeout`, or `--wait` with `--kill` — is refused before anything
is launched and stays a plain `sessioneer: ...` message on stderr with empty
stdout.

The session name is still the first line of stderr, and a timeout still leaves
the session running: whoever started the turn must `--kill` it, naming its agent.
See [From a PHP app](#from-a-php-app).

## From a PHP app

`packages/sessioneer-client` is a standalone Composer package (no dependencies) that runs one `--wait --json` turn from PHP: it builds the argv, writes the prompt to stdin, keeps stdout and stderr apart, enforces a hard deadline, and stops the session it created. Its README has the install (a path repository while the API settles), the usage example and the host-bridge recipe for a containerised app.

Two parts of the contract matter to any client, in any language:

- **stdout is the envelope, stderr is diagnostics.** The answer is `answer` in the envelope and nowhere else. The session name is the first line of stderr, written before the turn is waited on; it is the fallback when a client's own deadline kills the CLI before it can write the envelope, and an error line is never a session name.
- **A timeout leaves the session running.** `--timeout` only stops the waiting; the session keeps its slot against the live-session cap. Whoever started the turn must `--kill` that exact session, naming its agent with `--agent` when it is not Claude (the CLI routes a kill by agent and defaults to Claude), with `--force` when it is still working, and report it if the kill is refused. Killing a session the call did not create is never acceptable.

The binary is host-side: it needs the host agent's socket, which a container does not have. Run the client on the host (a host-side queue worker, for example), or use the bridge recipe in the package README.

## Design


There is no separate "archive": stopping a session archives its conversation, exactly like the dashboard's Kill. `--kill` works for every agent (Claude, Codex, Ollama and OpenCode, routed by the session's own agent, or `--agent`) and for a tmux session; a bare terminal process is not killed but taken over.

`--usage` and the flow options (`--next`, `--offer`, `--rotate`) are not launcher actions. `--usage` sends the dashboard's own `quota` request and prints its `agents` map reshaped so each agent is a `label` plus `profiles` (Claude's accounts, or one `default`); the flow options read the dashboard's stored session records, and `--rotate` starts a receiver through the same launch path. The other options combine only with their own modifiers (`--kill`, `--list`, `--show`, `--answer`, `--interrupt`, `--bare`, `--take-over`, `--send`, `--peers` and `--usage` are mutually exclusive, and `--next`, `--rotate` and `--offer` are mutually exclusive with each other and with those).

The CLI sends one `launch`, `launch_kill`, `launch_list`, `launch_send`, `launch_peers`, `launch_show`, `launch_answer`, `launch_interrupt`, `launch_bare` or `launch_take_over` request to the host agent, or one `flow_next`, `flow_rotate`, `flow_offer` or `flow_offer_dismiss` request for the flow options. The last five of those launch actions are agent-independent and live in `HostAgent\Launch\SessionControl`: it resolves the target (name or conversation id) and then calls the dashboard's own `session_detail`, `answer_prompt*`, `send_escape` and `BareProcessService` code, so nothing is implemented twice. The dashboard's Kill (`kill`), Resume (`resume`) and Take over buttons run the same code: `kill` is `launch_kill` with `force`, a resume that names no runtime is `launch` (headless; `runtime=tmux` keeps the legacy pane path), and Take over posts `launch_take_over`. `HostAgent\Launch\LaunchService` picks a `SessionLauncher` by agent id; the interface documents the request and reply keys for all five. Supporting another agent means one new class implementing `SessionLauncher` and one line in `LaunchService::LAUNCHERS`; the CLI and the action do not change. The optional `effort` request key is validated by each launcher; `list` rows carry the stored `effort`. Launchable agents: `claude`, `codex`, `ollama` and `opencode`.

`--wait` is client-side only: after the `launch` reply (sent once the prompt is accepted, so the turn has started) the CLI polls the dashboard's own `session_detail` request every 2 seconds and stops at status `idle` or a `last_turn_error`. No new host-agent action. `--json` only changes what that loop prints: the same values it already has, in one object, on success and on failure alike.

Tests: `tests/test_launch.php`, `tests/test_launch_codex.php`, `tests/test_launch_ollama.php`, `tests/test_launch_opencode.php`, `tests/test_launch_control.php` (show, answer, interrupt, send as user, kill, bare, take over), `tests/test_cli_control.php` (option rules, requests built, output), `tests/test_cli_usage.php`, `tests/test_cli_wait.php`, `tests/test_cli_flow.php` (`--next`), `tests/test_cli_flow_rotate.php` (`--rotate`, `--offer`) and `tests/test_cli_flow_removed.php`.
