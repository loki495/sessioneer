# sessioneer-client

Run one Sessioneer CLI agent turn to completion from PHP, and get either the
answer or a failure that says what happened to the session it created.

This is the shared execution layer for Laravel apps that ask a coding agent a
question (classify, label, extract, summarise). The app keeps its own prompt,
schema, provider chain, settings and queue; this package owns the part that is
easy to get wrong: argv and stdin, safe option tokens, separate streams, a hard
deadline, and cleaning up the session it created.

- No dependencies: PHP >= 8.3 and `ext-*` only.
- Host-side: it runs the `sessioneer` binary, which talks to the host agent's
  socket. See [Where it can run](#where-it-can-run).

## Install

The package lives at `packages/sessioneer-client` in the Sessioneer repo while
its API settles. Require it from a consumer with a path repository, so no tag or
release is needed:

```json
{
    "repositories": [
        { "type": "path", "url": "../sessioneer/packages/sessioneer-client", "options": { "symlink": true } }
    ]
}
```

```bash
composer require loki495/sessioneer-client:*
```

When the API is stable the same directory is split into its own repository and
consumers switch the path repository for a VCS or Packagist requirement; the
namespace and API stay the same.

## Usage

```php
use Loki495\SessioneerClient\Agent;
use Loki495\SessioneerClient\ExecutionFailed;
use Loki495\SessioneerClient\ExecutionRequest;
use Loki495\SessioneerClient\SessioneerClient;

$request = new ExecutionRequest(
    workdir: base_path(),
    agent: Agent::Claude,
    model: 'sonnet',
    effort: 'low',
    timeoutSeconds: 60,
);

try {
    $result = (new SessioneerClient)->execute(
        $request,
        json_encode($payload, JSON_THROW_ON_ERROR),
    );

    $answer = $result->answer;      // the final assistant text

    if ($result->cleanupFailed()) {
        report($result->cleanup?->describe()); // the session outlived the call
    }
} catch (ExecutionFailed $e) {
    // $e->reason: unavailable | launch_refused | turn_error | timeout | process_failure
    // $e->session: the session this call created, already cancelled when it is not null
    // $e->cleanup: whether that cancellation worked
    report($e->describe());
}
```

One call is one attempt and one new session. There is no conversation reuse, no
retry and no fallback: those belong to the caller's provider chain, which is
what lets a chain try Claude, then Codex, then a local model.

## The contract it relies on

`sessioneer --wait` today:

| Stream | Content |
|---|---|
| stdout | the final assistant text, and nothing else |
| stderr | first line: the name of the session the call created; later lines: the error, if any |
| exit 0 | the turn finished |
| exit 1 | refused (`sessioneer: ...`), turn error (`session NAME errored: ...`) or timeout (`timed out after Ns ...`) |

The client keeps stdout and stderr apart (a caller must never parse an answer
out of a stream that also carries diagnostics), takes the session name from
stderr's first line only, and refuses to treat any line that is not a plain
argv-safe token as a session name.

A machine-readable `--json` envelope is a separate piece of Sessioneer work. When
it lands, only the parsing inside this client changes; `answer`, `session` and
the failure reasons stay the same.

## Safety

- **No shell.** The command is an argv array executed with `proc_open` and
  `bypass_shell`; nothing is interpolated into a string.
- **The prompt never touches argv.** It goes to the CLI on stdin (`-`), so
  quotes, newlines, JSON and size limits are not the caller's problem.
- **Option tokens are validated.** `model` and `effort` must start alphanumeric
  and contain only `A-Za-z0-9._:@+-/` (a slash allows `provider/model`); a value
  that could be another flag, a second option or a shell fragment is refused
  before any process starts.
- **Effort is checked per agent.** Claude's levels are enforced here, Ollama and
  OpenCode are refused; Codex validates the level against the chosen model, so
  it is passed through.
- **Agents are an enum.** `claude`, `codex`, `ollama` and `opencode`. Asking for
  an agent Sessioneer has no launcher for (`agy`, `antigravity`) fails with "not
  launchable through Sessioneer yet" instead of starting something with
  different permissions. OpenCode needs a Sessioneer with its launcher: an older
  CLI refuses it and the client reports a launch refusal.
- **No silent relaxation.** `--no-tools` is Claude-only, so `noTools: true`
  (the default) is refused for Codex, Ollama and OpenCode rather than dropped: a
  caller that wants an agent with its tools has to say so with
  `noTools: false`. `--effort` is refused for Ollama and OpenCode for the same
  reason.

## Timeouts and cleanup

Two deadlines, on purpose:

- `timeoutSeconds` becomes the CLI's own `--timeout`, so it can report a clean
  timeout and exit.
- `timeoutSeconds + graceSeconds` is the outer deadline on the process. If the
  CLI hangs past it, the client sends SIGTERM, then SIGKILL, and still has the
  session name from stderr.

Whatever happened — answer, launch refusal, turn error, inner timeout or outer
deadline — the session this call created is stopped before the call returns:

1. `sessioneer --agent <agent> --kill <session>` (never a reused or guessed name;
   the agent is explicit because the CLI routes a kill by it and defaults to
   Claude, so a Codex, Ollama or OpenCode session is only reachable with it);
2. if the CLI refuses because the session is still working, the same command
   with `--force`;
3. every refused or failed attempt is kept in `CleanupReport::$failures`, and a
   session that survives is reported through `cleanupFailed()` or
   `ExecutionFailed::describe()`.

A leaked session is not cosmetic: Sessioneer caps live child sessions, and once
the cap is reached every new launch is refused.

## Where it can run

The `sessioneer` binary is host-side: it needs the host agent's socket
(`$XDG_RUNTIME_DIR/sessioneer-agent.sock`, or `SESSIONEER_AGENT_SOCKET`). Run the
client where that socket is reachable:

- **Host worker** (recommended, and what a Laravel queue worker on the host
  does): the app's job runs on the host, so the client works directly.
- **Containerised app**: the container cannot see the socket, so the request has
  to be executed on the host. Use the host bridge below.

### Host bridge recipe

A containerised Laravel app hands each turn to a host-side worker instead of
running `sessioneer` itself. Two artisan commands, one worker, no shared
database credentials:

1. **The app enqueues a request row** (payload, attempt, state) and returns.
2. **A host-side worker polls the app** through `docker exec`, using two small
   artisan commands the app owns:

   ```bash
   # claim one pending request as JSON (or print nothing when the queue is empty)
   docker exec filer-app php artisan agent-bridge:claim

   # write the outcome back: answer, or the failure reason and cleanup report
   docker exec filer-app php artisan agent-bridge:complete 42 --answer="..."
   ```

3. **The worker runs this client on the host**, where `sessioneer` and its socket
   live, and reports back with the second command.

The same shape works for a web request that must not block: enqueue, poll, then
render. What the bridge must not do is let the container call `sessioneer`
through the socket path: it is not mounted, and mounting a user's runtime
directory into a container is a bigger grant than the bridge needs.

## Testing

The package carries its own tests and needs neither Composer nor this repo's
harness:

```bash
php packages/sessioneer-client/tests/run.php
```

They use fake `sessioneer` executables (written to a temp directory) to cover a
successful turn, a launch refusal, a turn error, the CLI's own timeout, the outer
deadline, a refused kill that needs `--force`, a cleanup that never succeeds, an
invalid option token and a missing binary. The repo suite runs the same checks
through `tests/test_sessioneer_client.php`.

## Limits

- One attempt per call: no retry, no fallback, no conversation reuse.
- No streaming: the answer arrives when the turn ends.
- No `--json`: the envelope is a separate Sessioneer feature; the client's public
  API does not depend on the current parsing.
- Codex, Ollama and OpenCode run with their own tools/MCP configuration; only
  Claude can be asked for `--no-tools`.
