# iOS Shortcuts API

Answer a blocked prompt or send a message from an Apple Shortcut, without opening the web app.
The Shortcut calls a small bearer-token JSON API (`ShortcutsController`, `ShortcutsService`).
Every read and write goes through the same host-agent actions the dashboard uses (`list`,
`session_detail`, `answer_prompt`, `answer_prompt_with_text`, `answer_multi_question`,
`send_message`), so an answer sent from a Shortcut behaves like one sent from the web UI.

This is a Shortcut, not an app. It runs when you start it (Siri, Home Screen, Back Tap, Action
Button). iOS cannot run it in the background when a push notification arrives.

## Setup

1. Generate a token: `openssl rand -hex 32`. Use a different token from `QUICK_PROMPT_API_TOKEN`.
2. Add it to the real `.env`: `SHORTCUTS_API_TOKEN=<token>`. Optional: `SHORTCUTS_RATE_LIMIT`
   (requests per minute, default 60).
3. Run `docker compose up -d` so the container reads the new variables.
4. Confirm `curl -i https://YOUR-HOST/api/shortcuts/v1/sessions` returns `401`, and the same
   call with `-H "Authorization: Bearer <token>"` returns `200`.

An empty or short (under 32 characters) token disables the API (`503 not_configured`).

**Rotate or revoke:** change `SHORTCUTS_API_TOKEN` in `.env` and run `docker compose up -d`. The old
token stops working immediately. To revoke without issuing a new one, set it to empty.
Update the token file (see below) after rotating.

Serve the API over HTTPS only (your Traefik/Cloudflare route). The API sends no cookies and
rejects any request that carries an `Origin` header.

## API reference

Base path: `/api/shortcuts/v1`. Every request needs `Authorization: Bearer <token>`. POST bodies
are JSON with `Content-Type: application/json`. Ids go in the query string or body because the
router matches exact paths.

Every response is a JSON object with `"ok": true|false`. A failure has `code` and `message`
(and never contains transcript text, paths or the token). A success never has a `code` key, so a
Shortcut can test failure with "`code` has any value".

| Method and path | Purpose |
| --- | --- |
| `GET /sessions` | Menu of live sessions, attention first |
| `GET /session?id=<id>` | One session with its pending prompt |
| `POST /messages` | `{"session": "<id>", "text": "..."}` |
| `POST /answers` | Answer the pending prompt |

### `GET /sessions`

```json
{
  "ok": true,
  "count": 2,
  "menu": ["1. Deploy fix — Needs reply", "2. Docs — Idle"],
  "ids": ["claude-headless-20261113-1", "cc-docs"],
  "sessions": [
    {"id": "claude-headless-20261113-1", "display_name": "1. Deploy fix — Needs reply",
     "status": "needs_reply", "can_message": false, "pending_prompt_count": 1}
  ]
}
```

Order: `needs_reply`, then `working`, then `idle`; newest activity first within each. `id` is the
stable session reference (never rely on names being unique). Only live sessions are listed.

### `GET /session?id=<id>`

```json
{
  "ok": true, "id": "cc-x", "display_name": "Deploy fix — Needs reply",
  "status": "needs_reply", "can_message": false, "has_prompt": true,
  "prompt_id": "3fa9c0d1e2b4", "prompt_kind": "single", "question_count": 1,
  "questions": [
    {"index": 0, "question": "Run the tests?", "select_mode": "one", "multi_select": false,
     "allows_text": true,
     "choices": ["1. Yes", "2. No", "0. Type my own answer"],
     "choice_ids": ["1", "2", "0"]}
  ]
}
```

- `prompt_kind`: `none`, `single` (one question) or `multi` (several questions answered together).
- `prompt_id` identifies this exact prompt. Send it back with the answer.
- `choices` entries begin with their number. Splitting a chosen label at `.` and taking the first
  item gives the choice id. `0` is the typed-answer entry: it is never a valid `choice`, send
  `text` instead.
- `select_mode` is `many` for a multi-select question and `one` otherwise.

### `POST /answers`

Send `session`, `prompt_id`, and exactly one of:

| Field | Use |
| --- | --- |
| `choice` | `"2"`, an id from `choice_ids` (single prompt) |
| `text` | A typed answer (only when `allows_text`) |
| `answers` | `multi` prompts: one string per question, in order. `"2"` for one pick, `"1,3"` for a multi-select, `"text:my answer"` for a typed answer |

Success: `{"ok": true, "message": "Answer sent"}`.

### `POST /messages`

`{"session": "<id>", "text": "hello"}` (1–8000 bytes). Success: `{"ok": true, "message": "Message sent"}`.
Refused with `409 prompt_pending` while the session waits on a prompt.

### Status codes

| Code | Meaning |
| --- | --- |
| 200 | Done |
| 400 | Malformed JSON |
| 401 | Missing, wrong or revoked token |
| 403 | Request carried an `Origin` header |
| 404 | Unknown session |
| 405 / 413 / 415 | Wrong verb, body too large, not JSON |
| 409 | Prompt already answered, changed, or session blocked (`no_pending_prompt`, `prompt_changed`, `prompt_pending`) |
| 422 | Invalid input (`invalid_session`, `invalid_prompt`, `invalid_choice`, `invalid_text`, `invalid_answer`, `text_not_supported`) |
| 429 | Rate limited (`Retry-After: 60`) |
| 502 | The session did not accept it |
| 503 | Not configured, or host agent unavailable |

A second submission of the same answer returns `409 no_pending_prompt` because the prompt is gone.

Authorization: Sessioneer is single-user, so any valid token can reach every live session.
Each write re-reads the session first, so a made-up session id gets `404` and an answer for a prompt
that is no longer the current one gets `409`.

## Building the Shortcut "Sessioneer Reply"

Names below match the iOS Shortcuts app (iOS 17 and later). A Shortcut cannot catch a network
error; if the phone is offline, **Get Contents of URL** fails and Shortcuts shows its own error.
A rejected request (wrong token, stale prompt) returns normally with a `code` key, which the
steps below check.

I did not generate an importable `.shortcut` file: recent iOS versions require a signed file and I
cannot validate one from here. Build it by hand (about 25 actions).

### A. Credentials

1. In the Files app, create `iCloud Drive/Shortcuts/sessioneer-token.txt` holding only the token.
   Keeping it in a file means the token is not inside the Shortcut when you share or export it.
2. In the Shortcut: **Get File** (Service **iCloud Drive**, Path `Shortcuts/sessioneer-token.txt`,
   Error If Not Found on) → **Get Text from Input** → **Set Variable** `Token`.
3. **Text** `https://YOUR-HOST` → **Set Variable** `Base`.

If you prefer, put the token straight into a **Text** action instead. Then never share or export the
Shortcut, because the token travels with it.

### B. Pick a session

4. **Get Contents of URL**: URL `Base/api/shortcuts/v1/sessions`, Method **GET**, Headers:
   `Authorization` = `Bearer ` followed by the `Token` variable. **Set Variable** `Sessions`.
5. **Get Dictionary Value** `code` from `Sessions`. **If** that value **has any value**:
   **Get Dictionary Value** `message` from `Sessions` → **Show Alert** → **Stop This Shortcut**. **End If**.
6. **Get Dictionary Value** `menu` from `Sessions` → **Choose from List** (Prompt `Session`).
   **Set Variable** `Picked`.
7. **Split Text** `Picked` by **Custom** `.` → **Get Item from List** (First Item) → **Set Variable** `N`.
8. **Get Dictionary Value** `ids` from `Sessions` → **Get Item from List** (**Item At Index**,
   Index = `N`) → **Set Variable** `SessionID`.

Mapping strategy: the menu labels start with a unique number, and `ids` lists the session ids in the
same order. The number you picked is the position in `ids`, so labels never need to be unique.

### C. Read the session

9. **Get Contents of URL**: URL `Base/api/shortcuts/v1/session?id=` + `SessionID`, Method **GET**, same
   `Authorization` header. **Set Variable** `Detail`.
10. Repeat the error check from step 5 with `Detail`.
11. **Get Dictionary Value** `prompt_kind` from `Detail` → **Set Variable** `Kind`.

### D. No prompt: send a message

12. **If** `Kind` **is** `none`:
    1. **Ask for Input** (Text, Prompt `Message`) → **Set Variable** `Msg`.
    2. **Get Contents of URL**: URL `Base/api/shortcuts/v1/messages`, Method **POST**, Headers
       `Authorization` as above, Request Body **JSON** with `session` (Text) = `SessionID`,
       `text` (Text) = `Msg`. **Set Variable** `Result`.
    3. Go to step G.

### E. Single prompt

13. **Otherwise If** `Kind` **is** `single`:
    1. **Get Dictionary Value** `questions` from `Detail` → **Get Item from List** (First Item) →
       **Set Variable** `Q`.
    2. **Get Dictionary Value** `question` from `Q` → **Set Variable** `Question`.
    3. **Get Dictionary Value** `choices` from `Q` → **Choose from List** (Prompt = `Question`) →
       **Split Text** by `.` → **Get Item from List** (First Item) → **Set Variable** `Choice`.
    4. **If** `Choice` **is** `0`: **Ask for Input** (Prompt = `Question`) → **Set Variable** `Typed`;
       **Get Contents of URL** POST `Base/api/shortcuts/v1/answers`, JSON: `session` = `SessionID`,
       `prompt_id` = (**Get Dictionary Value** `prompt_id` from `Detail`), `text` = `Typed`.
       **Otherwise**: same request with `choice` = `Choice` instead of `text`.
       **Set Variable** `Result` in both branches.

### F. Several questions (`Kind` is `multi`)

14. **Otherwise** (`Kind` is `multi`): **Set Variable** `Answers` to an empty **List**.
    **Get Dictionary Value** `questions` from `Detail` → **Repeat with Each**:
    1. **Get Dictionary Value** `question`, `choices` and `select_mode` from `Repeat Item`.
    2. **If** `select_mode` **is** `many`: **Choose from List** with **Select Multiple** on →
       **Repeat with Each** picked item: **Split Text** by `.` → First Item → **Add to Variable** `Nums`;
       then **Combine Text** `Nums` with **Custom** `,` → **Add to Variable** `Answers`; clear `Nums`.
    3. **Otherwise**: **Choose from List** → **Split Text** by `.` → First Item. **If** it is `0`:
       **Ask for Input** → **Text** `text:` + input → **Add to Variable** `Answers`. **Otherwise**
       **Add to Variable** `Answers` with the number.
    4. **End Repeat**, then **Get Contents of URL** POST `Base/api/shortcuts/v1/answers`, JSON:
       `session`, `prompt_id`, and `answers` as a **List** field = `Answers`. **Set Variable** `Result`.

Most prompts are a plain approve/deny or a single question, so build sections A–E first and add F
later if you need it.

### G. Report the result and end

15. After the **End If** that closes sections D–F, **Get Dictionary Value** `message` from `Result` →
    **Show Notification** (Title `Sessioneer`, Body = that message). One notification covers success
    and failure, because both carry a short `message`: `Answer sent`, `Message sent`, or
    `That prompt is no longer waiting (already answered?)`.

Name the Shortcut `Sessioneer Reply`. In the Shortcut's settings, turn off **Show in Share Sheet**.

### Launching it fast

- **Siri:** say "Sessioneer Reply".
- **Home Screen:** Shortcut menu → **Add to Home Screen**.
- **Action Button** (iPhone 15 Pro and later): Settings → **Action Button** → **Shortcut** → choose it.
- **Back Tap:** Settings → **Accessibility** → **Touch** → **Back Tap** → **Double Tap** → choose it.
- **Control Center / Lock Screen:** add the **Run Shortcut** control (iOS 18+).

## Notification deep link (not supported)

The service worker opens a notification's `url` with `clients.openWindow()`. iOS does not let a web
push tap open a custom URL scheme such as `shortcuts://run-shortcut?name=Sessioneer%20Reply` from a
service worker, and I could not verify it from here. The push behaviour is unchanged: tapping a
notification opens the session page. After seeing a push, launch the Shortcut with Back Tap or the
Action Button. Skipping session selection would need that link to carry a session id, so it is not built.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `401 unauthorized` | Token missing, wrong or rotated. Update `sessioneer-token.txt`. |
| `503 not_configured` | `SHORTCUTS_API_TOKEN` is empty or under 32 characters, or the container was not recreated. |
| `421` / "Host not allowed" | Add your host to `ALLOWED_HOSTS` in `.env`. |
| `409 prompt_changed` / `no_pending_prompt` | Someone answered first, or the prompt moved on. Run the Shortcut again. |
| `409 prompt_pending` | The session needs an answer, not a message. |
| `429 rate_limited` | More than the per-minute limit. Wait a minute. |
| Shortcut fails with a network error | Offline or the host is unreachable. Shortcuts cannot catch this. |
| Menu is empty | No live sessions (archived ones are not listed). |

## Security notes

- The token is the only credential. Anyone holding it can read session titles and prompt questions
  and answer or message any live session. Treat it like a password.
- Shortcuts stores what you type into its actions in the Shortcut itself. Sharing or exporting it
  exposes an embedded token. Use the token file in section A and do not share the Shortcut.
- iCloud Drive syncs the token file to your other Apple devices.
- Never put the token in a URL; it is sent only in the `Authorization` header, and the app logs no
  headers.
- Rate limit: `SHORTCUTS_RATE_LIMIT` requests per minute across all callers.
