# `sessioneer` command line

`bin/sessioneer` starts, resumes or reuses a Sessioneer session from a shell, so a script, a subagent or a running session can spawn a continuation that you then watch and answer in the dashboard like any other session. It is host-side only: it talks to the host agent's socket (`$XDG_RUNTIME_DIR/sessioneer-agent.sock`, or `SESSIONEER_AGENT_SOCKET`), so access is whatever that socket's permissions allow: any process running as the same user (or in the socket's group) can launch sessions. No login, no token.

```bash
# a new headless Claude session with a first prompt
sessioneer --workdir ~/www/some/project --model sonnet --mode plan "Review the failing test and propose a fix"

# continue a conversation (reuses the live session if there is one)
sessioneer --resume <claude-conversation-id> "Now apply the fix"

# run to completion: block until the session is idle, print its final message
sessioneer --wait --timeout 600 --workdir ~/www/some/project "Summarise the open TODOs"

# last known usage stats for every agent and Claude account, as JSON
sessioneer --usage

# prompt from stdin, full reply as JSON
echo "Summarise the open TODOs" | sessioneer --json -
```

| Option | Meaning |
|---|---|
| `PROMPT` / `-` | The message to deliver; `-` reads stdin. Required for a new session, optional with `--resume`. |
| `--agent NAME` | Agent to launch: `claude` (default), `codex`, `ollama` or `opencode`; see "Codex", "Ollama" and "OpenCode" below. |
| `--resume ID` | Existing conversation id. |
| `--workdir DIR` | Working directory. Default for a new session: the current directory. With `--resume`: the directory recorded in the transcript (for OpenCode, the directory the serve records when the id is already there). |
| `--model NAME` | Model for a new session. Required for `--agent ollama`, which has no default: it must be one the configured Ollama server has pulled. For `--agent opencode` it is `provider/model` (for example `opencode-go/gpt-6.1`); without it the serve's default model is kept. |
| `--effort LEVEL` | Reasoning level for a new or resumed session, so a launch names agent, model and effort together. Claude: `low`, `medium`, `high`, `xhigh` or `max` (`claude --effort`, fixed when the process starts and re-applied whenever the session respawns after going dormant; the CLI itself rejects a level the model does not support). Codex: a level the chosen model lists (the default model when `--model` is absent), set on the thread with `thread/settings/update` before the prompt, so it also retunes a Codex thread you reuse or resume. An unknown level, a model without it, or a Claude session that is already tracked (its effort cannot change) is refused. Not offered for `--agent ollama` or `--agent opencode`, and refused with `--kill`, `--list` and `--peers`. |
| `--mode MODE` | Permission mode for a new session (for example `plan`). Not validated by the CLI: for Claude, a value that isn't a known permission mode is ignored and the session starts in the default mode; for Codex, only `plan` and `full` are accepted (see "Codex" below). Not offered for `--agent ollama` or `--agent opencode`. |
| `--profile NAME` | Claude account profile (see `host-agent/config/agents.php`). Without it, the session runs under the profile whose `config_dir` matches the caller's `CLAUDE_CONFIG_DIR`, so a session started from a work-account shell stays on that account; no `CLAUDE_CONFIG_DIR` means the default account (the launched session runs with the variable unset, whatever the manager inherited), and a dir that matches no profile is refused. |
| `--task-tools` | Enable the task tools in a new session. Claude only; not offered for `--agent ollama` or `--agent opencode`. |
| `--no-tools` | Start a new session with no tools at all: no built-in tools (`--tools ""`) and no MCP servers (`--strict-mcp-config`), so it can only answer in text. The setting is remembered, so the session stays tool-less when it is resumed after going dormant. Only for a new session: refused with `--resume`, `--kill`, `--send`, `--list` or `--peers`, and not combinable with `--task-tools`. Claude only; not offered for `--agent ollama` or `--agent opencode`. |
| `--kill TARGET` | Stop a headless session, by session name or conversation id. Its conversation stays resumable (it moves to the archived list). A working or blocked session is refused. For `--agent opencode` the target is a `ses_*` id: the serve session is interrupted, then deleted, and the conversation stays in `opencode.db` so the id can be resumed again. |
| `--force` | With `--kill`, stop a working or blocked session anyway (its in-flight work is lost). |
| `--list` | List headless sessions as tab-separated name, status, conversation id and directory. |
| `--send TARGET` | Deliver the prompt to another session (name or conversation id) from inside a session. The host agent prepends `[sessioneer peer: <name> · <workdir> · <branch>]` from its own records of the sender, so the message body cannot set it. A blocked peer accepts it and sees it when it resumes. Refused outside a session, for an unknown target, a blank message, or a message to yourself. |
| `--whoami` | Print this session's own name (`SESSIONEER_SELF`, or `CODEX_THREAD_ID` inside Codex); exits 1 outside a session. |
| `--peers` | List the other live sessions in this session's git repository (worktrees included) as tab-separated name, status, branch and directory. |
| `--usage` | Print the last known usage (quota) stats as JSON and exit 0: `agents`, one entry per agent (`claude`, `antigravity`, `opencode`, `codex`), with Claude's configured accounts nested under `agents.claude.profiles` (the default account keyed `personal`). Each entry has `label`, `ok`, `quota`, `fetched_at` (unix time, or null) and `message` (why there is no data yet). Read-only: it returns what the dashboard footer shows and never refreshes anything. Exits 1 if the host agent is unreachable. |
| `--wait` | With a launch or resume: block until the session is idle, then print the text of its final assistant message on stdout (the session name goes to stderr). Exits 1 if the launch is refused, the turn reports an error, or `--timeout` runs out. With `--agent codex` it also waits until the last message is the assistant's reply, because a new Codex thread reads idle for a few seconds before its turn starts (an interrupted turn therefore waits until `--timeout`). For `--agent opencode` the idle check uses the serve's live `/api/session/active` map, so a turn that just started is not mistaken for a stale idle. A session waiting on a permission prompt is not idle, so it keeps being waited on: answer it in the dashboard or set `--timeout`. Not combinable with `--kill`, `--list`, `--send` or `--peers`; both that and a bad `--timeout` are refused before anything is launched. A final message with no text (only tool calls) prints an empty line. |
| `--timeout N` | With `--wait`, give up after N seconds (a positive integer). Default: no limit. The session keeps running after a timeout. |
| `--json` | With `--wait`: print one JSON object on stdout instead of the plain text, on success and on failure alike — see [For programs](#for-programs). Otherwise: print the launch/list/peers reply as JSON instead of the plain output. Everything human stays on stderr. |

On success it prints the session name (the dashboard opens it at `session.php?session=NAME`) and exits 0; any refusal prints `sessioneer: <message>` on stderr and exits 1.

## What `--resume` does

1. A session Sessioneer already tracks on that conversation (headless, dormant or tmux) **receives the prompt**; no second process is opened on the transcript. Without a prompt, nothing is sent.
2. Otherwise the conversation is resumed as a headless session and the prompt is delivered.
3. If the conversation is running in a terminal Sessioneer did not start, it is refused: use **Take over** in the dashboard, which stops that process.

A session that is created but cannot take its prompt (for example the headless manager stopped) is reported as a failure that still names the session.

## Peer messaging

Every session Sessioneer spawns (headless or tmux) gets `SESSIONEER_SELF=<its name>`, separate from `SESSIONEER_SESSION_NAME`, which headless children deliberately lack because it is what the hooks key off. `--send` and `--peers` read it to identify the caller. A message is information or a request, never approval: it does not replace the owner's confirmation for pushes or other real-world actions. `--send` takes a session name or conversation id, not a task id. Any same-user process can name any `SESSIONEER_SELF`, which matches the socket's trust model (permissions on the socket are the boundary).

## Codex

`--agent codex` launches through the Codex bridge (`CodexSessionLauncher`); `--wait`, `--resume`, `--list`, `--kill`, `--send` and `--peers` work the same way, always with `--agent codex` (the default agent is Claude). A Codex session's name is its thread id, so a conversation id and a session name are the same thing.

- `--mode`: omitted means a `workspace-write` sandbox with on-request approvals (what the dashboard's New Session uses); `plan` means a `read-only` sandbox; `full` means no sandbox and no approvals (`danger-full-access`, approvals `never`), for trusted unattended work such as git in a worktree, docker or network access. Any other value is refused. `full` is a human-trust decision: any process that can reach the host-agent socket can request it, the same trust the socket already carries.
- `--profile` and `--no-tools` are refused: they have no Codex meaning.
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
  `--effort`, `--mode`, `--no-tools` and `--task-tools` are each refused with
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
- `--profile`, `--effort`, `--mode`, `--no-tools` and `--task-tools` are
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


There is no separate "archive": stopping a session archives its conversation, exactly like the dashboard's Kill. `--kill` only manages headless Claude, Codex, Ollama and OpenCode sessions; a terminal (tmux) session or a bare process is refused, and is stopped from the dashboard.

`--usage` is the one option that is not a launcher action: it sends the dashboard's own `quota` request and prints its `agents` map with `claude_profiles` nested under the Claude entry. The other options combine only with their own modifiers (`--kill`, `--list`, `--send`, `--peers` and `--usage` are mutually exclusive).

The CLI sends one `launch`, `launch_kill`, `launch_list`, `launch_send` or `launch_peers` request to the host agent. `HostAgent\Launch\LaunchService` picks a `SessionLauncher` by agent id; the interface documents the request and reply keys for all five. Supporting another agent means one new class implementing `SessionLauncher` and one line in `LaunchService::LAUNCHERS`; the CLI and the action do not change. The optional `effort` request key is validated by each launcher; `list` rows carry the stored `effort`. Launchable agents: `claude`, `codex`, `ollama` and `opencode`.

`--wait` is client-side only: after the `launch` reply (sent once the prompt is accepted, so the turn has started) the CLI polls the dashboard's own `session_detail` request every 2 seconds and stops at status `idle` or a `last_turn_error`. No new host-agent action. `--json` only changes what that loop prints: the same values it already has, in one object, on success and on failure alike.

Tests: `tests/test_launch.php`, `tests/test_launch_codex.php`, `tests/test_launch_ollama.php`, `tests/test_launch_opencode.php`, `tests/test_cli_usage.php`, `tests/test_cli_wait.php`.
