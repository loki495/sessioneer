# `sessioneer` command line

`bin/sessioneer` starts, resumes or reuses a Sessioneer session from a shell, so a script, a subagent or a running session can spawn a continuation that you then watch and answer in the dashboard like any other session. It is host-side only: it talks to the host agent's socket (`$XDG_RUNTIME_DIR/sessioneer-agent.sock`, or `SESSIONEER_AGENT_SOCKET`), so access is whatever that socket's permissions allow. No login, no token.

```bash
# a new headless Claude session with a first prompt
sessioneer --workdir ~/www/some/project --model sonnet --mode plan "Review the failing test and propose a fix"

# continue a conversation (reuses the live session if there is one)
sessioneer --resume <claude-conversation-id> "Now apply the fix"

# prompt from stdin, full reply as JSON
echo "Summarise the open TODOs" | sessioneer --json -
```

| Option | Meaning |
|---|---|
| `PROMPT` / `-` | The message to deliver; `-` reads stdin. Required for a new session, optional with `--resume`. |
| `--agent NAME` | Agent to launch. Default and only supported value today: `claude`. |
| `--resume ID` | Existing conversation id. |
| `--workdir DIR` | Working directory. Default for a new session: the current directory. With `--resume`: the directory recorded in the transcript. |
| `--model NAME` | Model for a new session. |
| `--mode MODE` | Permission mode for a new session (for example `plan`). |
| `--profile NAME` | Claude account profile (see `host-agent/config/agents.php`). |
| `--task-tools` | Enable the task tools in a new session. |
| `--kill TARGET` | Stop a headless session, by session name or conversation id. Its conversation stays resumable (it moves to the archived list). A working or blocked session is refused. |
| `--force` | With `--kill`, stop a working or blocked session anyway (its in-flight work is lost). |
| `--list` | List headless sessions as tab-separated name, status, conversation id and directory. |
| `--json` | Print the full reply as JSON instead of the plain output. |

On success it prints the session name (the dashboard opens it at `session.php?session=NAME`) and exits 0; any refusal prints `sessioneer: <message>` on stderr and exits 1.

## What `--resume` does

1. A session Sessioneer already tracks on that conversation (headless, dormant or tmux) **receives the prompt**; no second process is opened on the transcript. Without a prompt, nothing is sent.
2. Otherwise the conversation is resumed as a headless session and the prompt is delivered.
3. If the conversation is running in a terminal Sessioneer did not start, it is refused: use **Take over** in the dashboard, which stops that process.

A session that is created but cannot take its prompt (for example the headless manager stopped) is reported as a failure that still names the session.

## Design

There is no separate "archive": stopping a session archives its conversation, exactly like the dashboard's Kill. `--kill` only manages headless Claude sessions; a terminal (tmux) session or a bare process is refused, and is stopped from the dashboard.

The CLI sends one `launch`, `launch_kill` or `launch_list` request to the host agent. `HostAgent\Launch\LaunchService` picks a `SessionLauncher` by agent id; the interface documents the request and reply keys for all three. Supporting another agent means one new class implementing `SessionLauncher` and one line in `LaunchService::LAUNCHERS`; the CLI and the action do not change. Not yet offered: a thinking/effort level (the stream-json protocol is unconfirmed for it) and agents other than Claude.

Tests: `tests/test_launch.php`.
