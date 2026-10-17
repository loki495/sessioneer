# iOS Quick Prompt

`POST /api/quick-prompt` accepts JSON and a dedicated `Authorization: Bearer TOKEN` header. It creates a **new** Sessioneer-managed headless conversation by default. Quick Prompt Claude references carry a random suffix so simultaneous submissions cannot reuse the same timestamp-based name. Claude uses its existing headless manager; Codex uses its existing app-server bridge. The `sessioneer` CLI remains Claude-only. No extra queue, agent runner, Ollama service, or UI is required, unless you enable the optional prompt router (see "Routing from the prompt"), which uses a local Ollama.

## Configuration

Generate a token locally with `openssl rand -hex 32`. Save it in the web deployment's private `.env` as `QUICK_PROMPT_API_TOKEN`; never commit it, include it in a URL, or paste it into a prompt. Tokens must contain at least 32 bytes and no whitespace. The endpoint fails closed without one. Keep the Shortcut private: its Authorization header contains this credential.

Web container environment (the Compose file forwards these from `.env`):

| Variable | Default / meaning |
|---|---|
| `QUICK_PROMPT_API_TOKEN` | Empty; required dedicated bearer token. |
| `QUICK_PROMPT_BASE_URL` | Empty; required trusted HTTPS application root, e.g. `https://sessioneer.example`. No credentials, query, or fragment. Session links append `/session.php?session=ID`; Host/forwarded headers never supply this root. |
| `QUICK_PROMPT_WAIT_SECONDS` | `6`, integer clamped to 0–15 seconds. Completion wait **after dispatch**, not the agent lifetime. |
| `QUICK_PROMPT_MAX_ANSWER_BYTES` | `2000`, integer clamped to 1–8192 UTF-8 bytes. |
| `QUICK_PROMPT_RATE_LIMIT` | `10`, integer clamped to 1–60 requests per rolling minute, shared by all users of this token and all PHP workers. |
| `QUICK_PROMPT_STATE_DIR` | `/tmp/sessioneer-quick-prompt`; writable SQLite rate-limit state directory. Compose provides a persistent named volume at `/var/www/quick-prompt-state`. |

Host-agent environment (`host-agent/.env`, loaded by the existing systemd EnvironmentFile; **not** the web container):

| Variable | Default / meaning |
|---|---|
| `QUICK_PROMPT_PROJECTS` | `{}`; required JSON object of project aliases to existing absolute host paths, e.g. `{"dibs":"/home/you/www/dibs"}`. Only these paths can be selected. Paths are canonicalized on every dispatch. |
| `QUICK_PROMPT_DEFAULT_PROJECT` | Empty; required alias in that map. |
| `QUICK_PROMPT_AGENTS` | `claude`; comma-separated subset of `claude,codex`. Enable Codex only with its existing bridge configured. |
| `QUICK_PROMPT_DEFAULT_AGENT` | `claude`; must be in the enabled list. |
| `QUICK_PROMPT_MODELS` | `{"claude":["sonnet","haiku","opus","fable"],"codex":[]}`; exact per-agent model allowlists. Add locally available Codex models before explicitly selecting one. No model field uses the existing agent default. |
| `QUICK_PROMPT_DEFAULT_MODEL` | Empty; optional exact allowed model. This single default must work for the selected agent; prefer explicit fields when switching agents. |
| `QUICK_PROMPT_DEFAULT_PROFILE` | Empty; optional Claude account name from `host-agent/config/agents.php`. Only Claude currently has account profiles; it is ignored for Codex. |
| `QUICK_PROMPT_DEFAULT_MODE` | `auto`; Claude's starting permission mode, `auto` or `plan` (an invalid value falls back to `plan`). Codex is always `read-only`. |
| `QUICK_PROMPT_ARCHIVE_ANSWERS` | `1`; set `0` to keep every session, see "Cleanup" below. |
| `QUICK_PROMPT_ROUTER_URL` | Empty (router off); base URL of an Ollama server reachable from the host, e.g. `http://127.0.0.1:11434`. See "Routing from the prompt". |
| `QUICK_PROMPT_ROUTER_MODEL` | `qwen3:4b`; any local model that supports Ollama structured output. |
| `QUICK_PROMPT_ROUTER_TIMEOUT_SECONDS` | `20`; 1–60. Covers a cold model load (about 5 s for `qwen3:4b` on a small GPU); a slow or unreachable router never blocks a prompt: the defaults apply. |
| `QUICK_PROMPT_ROUTER_KEEP_ALIVE` | `1h`; how long Ollama keeps the model loaded after each request (sent per request, so the server needs no special setup). |

Permissions come from a short allowlist: Claude `mode` is `auto` (the default, from `QUICK_PROMPT_DEFAULT_MODE`) or `plan`; Codex is `read-only` with `approvalPolicy: "on-request"`. Other modes are rejected. Auto mode lets Claude's own classifier approve routine actions without a human, so a Quick Prompt session can edit files and run commands inside its project; choose `plan` if that is too much. Neither is an OS filesystem sandbox for Claude. Quick Prompt itself never answers an approval, and a session waiting on one is returned as a link. A continuation must still be in the mode it started in. Existing dashboard workflows retain their permission settings.

An example systemd environment-file line for project mapping is:

```dotenv
QUICK_PROMPT_PROJECTS='{"dibs":"/home/you/www/dibs"}'
QUICK_PROMPT_DEFAULT_PROJECT=dibs
QUICK_PROMPT_DEFAULT_AGENT=claude
QUICK_PROMPT_AGENTS=claude,codex
```

Applying this feature requires deploying both web and host code and rebuilding the web image once to initialize ownership of its state volume. The Claude headless manager needs a scheduled restart to load the new terminal-result snapshot; restarting it affects live headless sessions. Arrange that deployment explicitly. Implementing the feature does not change any running service or existing `.env`.

## Request and response

Minimal native Shortcuts JSON body:

```json
{"prompt":"Review the latest failing tests"}
```

Optional fields:

```json
{"prompt":"Review the latest failing tests","agent":"claude","project":"dibs","mode":"plan","model":"sonnet","profile":"work","session_id":null}
```

`prompt` must be nonempty after trimming, valid UTF-8, without NUL, and at most 16384 bytes. The entire JSON body is limited to 24576 bytes. Unknown fields, arbitrary filesystem paths, CLI arguments, unknown models/accounts, and unsupported agent/project/mode combinations are rejected. An account is a named `profile`, never a credentials/config directory supplied by the client.

An explicit first line can organize a dictated or typed prompt without a model call:

```text
@sessioneer agent=claude model=sonnet profile=work project=dibs
Review the latest failing tests
```

The first line is stripped before delivery. Its space-separated `key=value` fields support `agent`, `model`, `profile`, `project`, and `mode`; values contain letters, numbers, `_`, `.`, or `-`. Conflicting JSON fields or repeated/unknown hints are rejected. Without the router, ordinary prose such as “use Codex” remains agent input and does **not** change the routing.

## Routing from the prompt

With `QUICK_PROMPT_ROUTER_URL` set, the host asks a small local Ollama model (structured JSON output) before any session exists: which agent, Claude account, model and project the request names. So “use codex to explain this” or “with the work profile, check the dibs tests” launches the right session without a Claude turn. The model only chooses among the configured agents, accounts, models and project **names**; it never sees paths and never chooses permissions, so a hostile prompt can at worst select a different allowed option. A chosen agent, account, model or project is accepted only if its name appears in the prompt (small models otherwise fill every field with a plausible value), so say “use codex”, “work profile”, “opus” or the project's name literally. Fields you set in the JSON body or the `@sessioneer` line always win, a value the chosen agent cannot use is dropped, and a router that is off, slow or returns nonsense falls back to the defaults. The prompt is delivered unchanged. Pull a model first (`ollama pull qwen3:4b`). With `QUICK_PROMPT_ROUTER_URL` set to a loopback address in `host-agent/.env`, `host-agent/install.sh` installs and enables `sessioneer-ollama.service` (a user unit serving that address, keeping the model loaded for an hour, one model at a time); otherwise run `ollama serve` yourself, loopback only, since Ollama has no authentication; if the router is down, “use codex” is ignored and the default agent starts, which may spend Claude quota.

A second, separate call asks the same model whether the request is a **one-off** (a question, a lookup, a small calculation) or **long-running** (building, refactoring, debugging, research across many steps). A long-running prompt still starts its session and receives the prompt, but the endpoint returns the session link at once: no wait for an inline answer, and the session is never archived. A one-off behaves as described under Cleanup. The judgement only decides waiting and archiving; it never chooses permissions or the mode, which come from the defaults and your explicit fields. If the call fails or returns anything unexpected the prompt is treated as a one-off. Both calls are covered by an opt-in test against a real Ollama (`bash tests/run.sh --live`, `tests/test_quick_prompt_router_live.php`; it skips when Ollama is not running).

## Errors

An error says what happened, in the agent's own words after scrubbing:

```json
{"type":"error","code":"agent_failed","reason":"quota_exhausted","message":"Usage limit reached: You've hit your usage limit. Resets at 5pm (America/New_York).","detail":"You've hit your usage limit. Resets at 5pm (America/New_York)."}
```

`code` is the stable category (`agent_failed`, `delivery_failed`, `agent_unavailable`, ...). `reason` is one of `quota_exhausted`, `auth_failed`, `service_unavailable`, `model_unavailable`, `mode_not_applied`, `agent_crashed`, `host_unavailable` or `agent_error`, guessed from the agent's text. `account` (Claude only) is the profile the session ran on, or `default`; a quota or login message names it, e.g. “Usage limit reached (work account)”. `message` leads with the reason and is ready to show in a Shortcut. `detail` is the agent's own text with absolute paths, secrets and long token-like strings, e-mail addresses and control characters removed, cut to 240 characters. Unexpected exceptions inside Sessioneer never contribute text, only the generic message.

## Cleanup

A session this endpoint created is archived (stopped; its transcript stays in the Archived list and can be resumed) in two cases:

- **Errors:** an agent that fails, or a session that cannot take its prompt. The error carries no session link.
- **One-line answers:** the agent finished with a short, tool-free reply (the same test as returning an inline answer) and the session is idle. The response is `{"type":"answer","text":"...","archived":true}` with no link, and a retry with the same idempotency key returns that same answer.

The test is the outcome, not a prediction, so it works with the router off. A session still running, one waiting on an approval, a long or tool-using reply (these come back as `type: session`) and a continued session are never archived. Set `QUICK_PROMPT_ARCHIVE_ANSWERS=0` to keep answer sessions.

`session_id` is a **Sessioneer reference**, not necessarily the agent's conversation UUID. Explicit continuation currently accepts only a Quick Prompt-created Claude session with identical settings, canonical project path, account, and a live idle process still in its original permission mode. Other coding conversations, dormant sessions, busy/blocked sessions, and Codex continuation are refused. The existing Codex queue does not expose a sufficiently verifiable continuation permission boundary. Use a fresh Codex session instead.

Successful HTTP 200 responses are one of (an archived one-line answer has `"archived":true` and no link, see Cleanup):

```json
{"type":"answer","text":"The failing test is caused by...","session_url":"https://sessioneer.example/session.php?session=claude-headless-123","session_id":"claude-headless-123"}
```

```json
{"type":"session","status":"running","session_url":"https://sessioneer.example/session.php?session=claude-headless-123","session_id":"claude-headless-123"}
```

Session statuses include `running`, `blocked` (approval or human input), `completed` (long or tool-bearing output), `interrupted`, and `unknown` (delivery/state cannot be confirmed). Only a reliable terminal result for this submission may become an inline answer. Any tool-bearing output opens the session, conservatively. A partial assistant message never counts as completion.

Errors use appropriate 400/401/403/405/413/415/422/429/502/503 status codes and a predictable JSON body:

```json
{"type":"error","message":"Unable to start the agent","code":"agent_unavailable"}
```

A failure after session creation includes `session_id` and `session_url` when known; check that session before sending again. Rate limits include `Retry-After: 60`. Invalid Host headers remain rejected by the application's existing allowlist.

## Bounded waiting and retries

Dispatch has a 10-second web socket deadline. Completion waiting then lasts up to `QUICK_PROMPT_WAIT_SECONDS`. Probes run at one-second intervals, each bounded by the remaining wait and a maximum two seconds; this adds no indefinite transaction, lock, or socket wait. The persistent runtimes currently expose request/reply state probes rather than a completion subscription, so no new event broker is introduced. The agent continues when the HTTP wait ends or the client disconnects. Configure proxy timeouts above dispatch plus completion wait (for the default, at least 20 seconds).

Supply `Idempotency-Key: UUID` to make retries safe. Generate a key **once per user submission** and reuse it with the same body on network retry; use a new key for a new prompt. Host SQLite reservations prevent duplicate sessions and message delivery, even when the first caller times out. A reused key with a different normalized request returns `409 idempotency_conflict`. An unfinished reservation returns `409 dispatch_pending`: do not replace it with a new key until you have checked the dashboard. Without a key, retries are distinct submissions and may create duplicates.

Reservations and Quick Prompt session metadata live in `SIDECAR_DIR/quick-prompt.sqlite` and have no automatic expiration; keep this state if retry protection must survive reboot (the default sidecar directory may be runtime storage). A process crash between reservation and session creation remains uncertain and is never replayed automatically. Inline Claude results are a bounded in-memory manager snapshot: a manager restart or superseding dashboard turn returns a session link. Codex requires the native submission turn ID; uncorrelated queue delivery or additional dashboard turns likewise falls back to the session.

## Network safety

Use HTTPS on a LAN/VPN/private authenticated ingress. This token protects **only** Quick Prompt, not Sessioneer's dashboard or existing API. Never expose the broader application to the public Internet. If a separately secured ingress reaches this endpoint, its exact-path routing must deny all other application paths and methods; do not blindly forward `/` to Sessioneer. Opening session links still requires private network access. Keep `BIND_ADDR`, `ALLOWED_HOSTS`, and existing proxy boundaries configured normally; no forwarded IP/host trust or CORS support is added. Browser Origin submissions are rejected, and cookie authentication does not authorize this endpoint. Set an ingress body cap and connection/request limit as an additional deployment control. The built-in limiter applies to authenticated requests; unauthenticated traffic needs ingress protection against connection floods.

## curl

```bash
curl --fail-with-body 'https://sessioneer.example/api/quick-prompt' \
  -H 'Authorization: Bearer REPLACE_WITH_PRIVATE_TOKEN' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: REPLACE_WITH_ONE_SUBMISSION_UUID' \
  --data '{"prompt":"Review the latest failing tests","project":"dibs"}'
```

For Codex add `"agent":"codex"` and optionally `"model":"YOUR_CONFIGURED_MODEL"`; omit a Claude `profile` and use `"mode":"read-only"` if specifying mode.

## Exact iOS Shortcut actions

1. **Ask for Input**: type **Text**, prompt “What should Sessioneer do?”. Keyboard dictation is optional; a separate Dictate Text action is unnecessary.
2. **If** Provided Input **does not have any value**: **Stop This Shortcut** (called Stop and Output in some iOS versions). End If. Whitespace-only input is also rejected by the server.
3. **Generate UUID**. Keep this UUID for retries of this submission.
4. **Get Contents of URL**: URL `https://sessioneer.example/api/quick-prompt`; method **POST**. Headers: `Authorization` = `Bearer YOUR_PRIVATE_TOKEN`, `Content-Type` = `application/json`, `Idempotency-Key` = the generated UUID. Request Body **JSON**: add key `prompt`, type **Text**, value **Provided Input**. Optional Text keys are `agent`, `project`, `mode`, `model`, `profile`; normally omit `session_id` for a new session. Do not manually concatenate raw JSON with dictated input.
5. **Get Dictionary Value**: key `type`, dictionary **Contents of URL**.
6. **If** Dictionary Value **is** `answer`: **Get Dictionary Value** `text` from the original response; **Show Result** with that text.
7. **Otherwise**, **If** type **is** `session`: **Get Dictionary Value** `session_url` from the original response; **Open URLs** with that value.
8. **Otherwise**: **Get Dictionary Value** `message` from the original response; **Show Alert** with it, or “Unable to submit Quick Prompt” if absent. End both If blocks.

An HTTP/network failure can cause Get Contents of URL to stop the Shortcut before dictionary parsing; iOS behavior varies. Retain the submission UUID when manually retrying. This documentation describes manual configuration; no Shortcut is uploaded or installed.

## Temporary Shortcut preview

For JSON branching only (no agent calls), a separate development server can serve `tests/fixtures/quick_prompt_preview.php` on an unused LAN port:

```bash
QUICK_PROMPT_PREVIEW_BASE_URL=https://sessioneer.example \
  php -S YOUR_LAN_IP:18091 tests/fixtures/quick_prompt_preview.php
```

POST `http://YOUR_LAN_IP:18091/api/quick-prompt` with `{"prompt":"Shortcut test"}`. It always returns an `answer` and a preview message. Use only a placeholder Authorization value: this mock ignores it and is plain HTTP. The fixture is outside the public document root and never routed by the application. Stop this separate process when finished; it does not deploy Quick Prompt or change Sessioneer's running services.
