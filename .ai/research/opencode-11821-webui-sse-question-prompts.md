---
topic: opencode-11821-webui-sse-question-prompts
updated: 2026-09-08
hash_algorithm: sha256
covers:
  - path: host-agent/opencode_sse_consumer.php
    hash: 43c7ae04408133e2ed9869db05e9b6712530289c011d6b1629578c7b7553f241
  - path: host-agent/lib/Services/OpenCodeQuestionService.php
    hash: 77b9fb2110e6251d0cffe2d9dd61c6ffb37c659edd8ab757f6e805efcbd537d1
  - path: host-agent/lib/Runtimes/HeadlessRuntime.php
    hash: 1b139b8ecf93af9cca981be50f2843e166d8c9ca5ee8e1f6977e4646727e7e84
  - path: host-agent/lib/Sessions.php
    hash: 6ef513440fe55e6755a4de1a375de05b8734dd75819e2fe0947b6746bd27a6f9
  - path: host-agent/lib/Services/OpenCodeTranscriptService.php
    hash: adbc56e681a8d3b7e2c2ce472c42b662c85e6571dc04b5f1f8ca4dc993427540
  - path: public/js/session.js
    hash: 38ae4737154724b112cc88c3d67aae765095439839a8cbf4d7d12ab48972d9ed
  - path: /home/andres/opencode-1.18.21/packages/app/src/context/server-sdk.tsx
    hash: 0114cb5f67cdea370d9e7d40c7db0bbba876be43f1aceaa62a5aac48b86c2b91
  - path: /home/andres/opencode-1.18.21/packages/app/src/utils/server-protocol.ts
    hash: 35a1cbc6f31381a47d16491ceece0ea8498e97529b233582f2deec35c20c2493
  - path: /home/andres/opencode-1.18.21/packages/app/src/utils/server-compat.ts
    hash: 905d18016de73128cd2c143a7486efc15b768f3d3345ffbed4bc13367ef9b80e
  - path: /home/andres/opencode-1.18.21/packages/app/src/context/server-session.ts
    hash: f9aaee1e0528a6de031ee485d2efb283f4a2cb19e4bc3cd7b7c5b8b3beb62978
  - path: /home/andres/opencode-1.18.21/packages/app/src/pages/session/composer/session-question-dock.tsx
    hash: a03dff2425df40900345af9e75938ac9f18bf8ab1efa1e2ef3d4b94f8e401612
  - path: /home/andres/opencode-1.18.21/packages/protocol/src/groups/question.ts
    hash: 2824bc41c614be6b750480ec9415e82089c2b8f503d3a4143e7e414ed6da539c
  - path: /home/andres/opencode-1.18.21/packages/opencode/src/question/index.ts
    hash: 7f1aa951067be95f020c508b5f8cf61446047019f8ffd31c2372eb551cc22f52
---

# OpenCode 1.18.21 WebUI, server events, and Sessioneer question prompts

## Implementation follow-up (2026-09-08)

The abandoned integration is now implemented. **Acceptance of these changes is conditional on a future real OpenCode end-to-end test once quota is available**, covering question delivery, option/custom/multi-select replies, tool continuation, and prompt clearing. The historical findings and remaining-issues list below describe the state at initial recovery, before these fixes.

- The persistent consumer reconciles exact canonical session directories, parses bounded SSE frames, reconnects, cleans up child processes, and attempts scoped pending-question recovery after connection. Only tracked headless OpenCode sessions are subscribed.
- Stored SSE questions survive polling. Reply/reject clearing is atomic and request-specific. Answers accept option labels, custom text, and multiple selections, with strict v2 empty-204 handling and scoped v1 JSON-true fallback only for definitive unsupported routes.
- Both initial and polled transcript cards use actionable controls. Browser handlers exclude the sidebar's independently handled forms, preventing duplicate submissions.
- Added the consumer systemd unit and installer wiring. The new unit alone was installed and enabled locally; the existing OpenCode server was not restarted. Read-only enumeration succeeded (50 sessions), but there were zero tracked headless sidecars at verification, so the consumer was running without subscriptions. A live event connection and model continuation remain unverified.
- Synthetic parser, daemon/reconnect, synchronization, reply-contract, transcript, and real-browser AJAX tests passed. The full suite exposed a sidebar duplicate-handler regression; after fixing it, the complete browser subset passed. PHPStan passed. JavaScript typechecking retains five pre-existing errors involving bootstrap `agentSessionId` declarations and sidebar `EventTarget.closest`.
- Model-driven end-to-end verification remains outstanding because OpenCode quota is exhausted. The synthetic browser tests use a canned host agent; daemon and reply HTTP tests independently exercise their real implementations against fixtures.

`CLAUDE.md` and feature documentation now reflect the directory-scoped event findings. The older guidance warning at the end of this document is retained as historical context.

## Scope and provenance

Recovered on 2026-09-07 from the local OpenCode session **“Change directory to sessioneer”**, ID `ses_f88a38dd0ffeLy13kKaJ4WJT5k`, stored in `~/.local/share/opencode/opencode.db` (`session`, `message`, `part` tables, read-only access). Recorded version: **1.18.21**. Session timestamps: 2026-09-06 15:35:42 UTC through 2026-09-07 02:38:26 UTC. Its stored directory is **`/home/andres/www`**, even though its shell work moved into `/home/andres/www/sessioneer`.

This is a research/handoff record, not a declaration that question answering is fixed. It distinguishes recorded live evidence, inspected source, and work still needed. No live questions, replies, server restarts, or deployment were performed to produce this document. Source cross-checks used the local `/home/andres/opencode-1.18.21` tree and Sessioneer's working tree at base commit `ef10b23ab5c2bbe9bf8a23c5489a38964e9373e5`, including pre-existing uncommitted changes. Hashes above identify the inspected snapshots; recheck conclusions when those files change. A directory/version label alone does not prove the source is byte-for-byte identical to the installed binary.

The session's early assertions about impossible HTTP answering, invisible questions, and the WebUI always using v2 were subsequently contradicted or remained unproven. Do not copy those assertions as established architecture.

## Strongest live result: exact-directory v1 SSE works

The session ran simultaneous listeners against `http://localhost:4096/event` and `/api/event`, both with `Accept: text/event-stream` and `x-opencode-directory: %2Fhome%2Fandres%2Fwww`.

| Probe | Recorded result | What it establishes |
|---|---|---|
| v1 `GET /event`, exact session directory | `question.asked`, `session.status`, `message.updated`, `message.part.updated`, `session.diff`, `session.updated`, connected/heartbeat | A plain curl-backed consumer can receive the question request and its ID. |
| v2 `GET /api/event`, same test | Two `message.part.updated`, one `server.connected`, no captured question | That tested connection did not deliver the question; cause was not established. |
| v1 `/event`, no directory header | Connected and heartbeat only in consumer logs | Connectivity alone does not verify useful subscription scope. |
| v1 `/event`, header for `/home/andres` | Connected and heartbeat only while the `/home/andres/www` question ran | A parent directory was not a recursive subscription in these tests. |
| `GET /question` | `[]` throughout recorded question probes | Those calls did not recover the request; not proof that correctly scoped listing is impossible. |
| `GET /api/session/{sessionID}/question` and `/api/question/request` | `{"data":[]}` during recorded probes | Empty results are observed, but cross-protocol/state/scoping causes remain unresolved. |

The successful payload, with unrelated identifiers abbreviated:

```json
{
  "id": "evt_...",
  "type": "question.asked",
  "properties": {
    "id": "que_079359e66001g2TJHgEYsHXBue",
    "sessionID": "ses_f88a38dd0ffeLy13kKaJ4WJT5k",
    "questions": [{
      "question": "Do you prefer altas or oranges, or neither?",
      "header": "Fruit preference",
      "options": [
        {"label": "Altas", "description": "You prefer altas."},
        {"label": "Oranges", "description": "You prefer oranges."},
        {"label": "Neither", "description": "You prefer neither."}
      ]
    }],
    "tool": {"messageID": "msg_...", "callID": "chatcmpl-tool-..."}
  }
}
```

The reply request ID is **`properties.id`**, not the envelope event ID, message ID, or tool call ID. The observed prefix was **`que_`**, not the earlier summaries' `queue_`; treat it as opaque.

Read-only reproduction of the successful subscription shape (bounded to 30 seconds; curl timeout at the end is expected):

```bash
curl --silent --show-error --no-buffer --max-time 30 \
  --header 'Accept: text/event-stream' \
  --header 'x-opencode-directory: %2Fhome%2Fandres%2Fwww' \
  http://localhost:4096/event
```

Substitute the configured server URL and URL-encoded **OpenCode session directory**, not shell `pwd`, project root by assumption, or `Config::home_root()`. Start listening before the question is asked. No replay/backfill guarantee was verified.

## How the WebUI actually talks to the server

The application UI source is **`packages/app`**. `packages/web` was a documentation detour. Useful source paths below are relative to `/home/andres/opencode-1.18.21`.

1. **Server and protocol selection:** `packages/app/src/context/server-sdk.tsx` constructs clients from the selected connection's `server.http.url`. `utils/server-protocol.ts` probes `/global/health` first: JSON `healthy: true` selects v1. Otherwise `/api/health` with numeric `pid` selects v2; `healthy: true` selects v1; fallback is v2. Thus WebUI version and SDK import path do not identify the actual wire protocol.
2. **Event transport:** `server-sdk.tsx` branches between `eventSdk.global.event(...).stream` for v1 and `eventApi.event.subscribe(...)` for v2. The generated SDK maps the former to **`/global/event`**, not `/event`; the latter is `/api/event`. The successful exact-directory `/event` probe is a separate proven integration route. The original session did not prove the browser's selected branch with a network capture.
3. **Event normalization:** legacy global events have `{directory, payload}`; newer events use `location.directory` and `data`. `adaptServerEvent()` maps `question.v2.asked/replied/rejected` to `question.asked/replied/rejected`, moving `data` into `properties`. Inspect full envelopes and both naming families; filtering only legacy names can miss v2 events.
4. **Dispatch and lifecycle:** the WebUI emits normalized events by directory, batches updates around a 16 ms frame, coalesces selected message updates, and reconnects after 250 ms with abort-controlled cleanup. Question lifecycle events must retain their ordering and identity; they are not interchangeable with transcript deltas.
5. **Question state:** `context/server-session.ts` stores `QuestionRequest[]` by session ID. Asked events insert or reconcile by request ID; replied/rejected events remove the matching request. It does not indiscriminately clear every question in a session.
6. **Question UI:** `pages/session/composer/session-composer-region.tsx` mounts `SessionQuestionDock` from the controller's question request. `session-question-dock.tsx` manages per-question answers, multi-select, custom text, progress, and cached draft selections keyed by server scope and request ID. It calls `sdk().api.question.reply({sessionID, requestID: request.id, answers})` or reject.
7. **Compatibility changes the HTTP call:** `utils/server-compat.ts` selects its implementation from the detected protocol. Its v1 question reply/reject methods forward to `legacy().question.reply/reject`, rather than unconditionally calling the v2 session routes. The legacy SDK exposes `/question/{requestID}/reply` and `/question/{requestID}/reject`. Scoped legacy clients must resolve the same instance directory as the pending question.

Therefore neither “the WebUI always subscribes to `/api/event` with a directory header” nor “it always replies through the v2 endpoint” is a safe conclusion. The directory header was experimentally necessary for the successful **instance `/event`** probe. The global WebUI stream has its own envelope and protocol selection. No websocket-only/private answer mechanism was demonstrated.

## Server question lifecycle and answer contract

`packages/opencode/src/tool/question.ts` calls `Question.Service.ask()` with session ID, prompts, and optional `{messageID, callID}`. `src/question/index.ts` uses **instance-scoped in-memory state**: `ask()` allocates a question ID and deferred result, inserts a pending entry, publishes Asked, and waits. Reply removes the entry, publishes Replied, and resolves the deferred with answers. Reject removes it, publishes Rejected, and fails the deferred. Finalization clears pending entries; `list()` returns entries from that instance's pending map.

This explains why database transcript detection and live answerability are separate. The DB tool part contains question input, call ID, status, and eventually output/answer metadata; it does not supply the observed live request ID. A persisted pending-looking tool part is insufficient evidence that the server still owns an answerable request. Empty listing results can also reflect instance scope: the session did not complete a correctly scoped v1 list/reply comparison after discovering the exact-directory requirement.

Answers are strings grouped by question in original order:

```json
{"answers": [["Altas"], ["Option A", "Option C"], ["My own answer"]]}
```

Send option **labels**, not displayed numeric indexes. Preserve multi-select arrays and free-text. The model's completed tool result includes formatted answer text and `metadata.answers`; prefer structured metadata where available.

| Protocol | Reply route | Success contract in inspected source |
|---|---|---|
| v1 | `POST /question/{requestID}/reply` | Legacy handler returns JSON boolean `true`. |
| v2 | `POST /api/session/{sessionID}/question/{requestID}/reply` | Protocol declares `HttpApiSchema.NoContent`, not JSON `true`. |

Both have corresponding `/reject` routes. Verify status, content type, error body, instance scope, and actual tool continuation when validating either route. Sessioneer's convention still prefers functioning v2 APIs; a demonstrated v1 event fallback does not justify switching session enumeration back to v1.

## What the original Sessioneer work achieved and where it stopped

User requirements were to reuse the existing Claude-style prompt component, allow answering inside the question card, include “Type something,” and keep AJAX handling generic. The user explicitly rejected reverting to display-only cards and later authorized the SSE consumer. The last substantive continuation chose one connection per unique active session directory, then systemd/install integration and tests. Those tasks were still pending at the recorded end.

The recorded UI investigation found that submit delegation was confined to `blockedSection`: transcript forms escaped AJAX interception and navigated to the JSON response. It broadened delegation to `document`, made pending UI target the form's card, and threaded session/CSRF context into live transcript rendering. The user then confirmed AJAX and custom-input interaction worked, but both answer paths still failed with **“Rejected: no prompt is currently pending on this session.”** Answers reported later in the transcript were supplied through the OpenCode WebUI; they are not evidence of successful Sessioneer replies.

Relevant current files:

- `host-agent/lib/Services/OpenCodeTranscriptService.php`: question tool → structured transcript block, options/custom answer presentation, completed-answer extraction.
- `src/lib/Views/TranscriptView.php`, `src/partials/transcript/block.php`, `src/partials/pages/session.php`, `public/js/session.js`: server render, live context, mirrored poll-time render and generic answer handling.
- `host-agent/lib/Runtimes/HeadlessRuntime.php`, `host-agent/lib/Runtimes/OpenCodeServeClient.php`, `host-agent/lib/Services/OpenCodeQuestionService.php`: prompt validation, option/text normalization, HTTP reply.
- `host-agent/lib/Sessions.php`: headless synchronization, blocked-state read/write, prompt fields exposed to the browser.
- `host-agent/opencode_sse_consumer.php`: untracked prototype at documentation time. Uses curl against `/event`, parses `data:` lines, writes `SessionStatusStore` on asked, clears on replied/rejected, retries after three seconds. Still subscribes only to `Config::home_root()`.
- `host-agent/lib/Stores/SessionStatusStore.php`: shared status persistence used by request-per-process host-agent and browser polling.

The proposed flow is:

```text
OpenCode server -- directory-scoped SSE --> persistent host consumer
                                               |
                                               v
                                        SessionStatusStore
                                               |
Sessioneer browser <-- PHP/host-agent polling --+
       |
       +-- existing AJAX answer action --> runtime/question service
                                               |
                                               +-- HTTP reply --> OpenCode pending deferred
```

Keep this consumer host-native and persistent; the container UI talks to the host agent over the existing UNIX socket. The per-connection host-agent PHP process is not a suitable lifetime for the subscription.

## Remaining integration issues, cross-checked while documenting

These are source-review findings and next-work guidance, not fixes made by this documentation task:

1. **Directory coverage:** the prototype's home-root subscription reproduces the known non-delivery case. Discover actual session directories, deduplicate them, reconcile additions/removals, and reconnect independently. Use canonical v2 `/api/session` enumeration where available (existing project rule), rather than the original session's proposed v1 `/session` list. Alternatively investigate the WebUI's v1 `/global/event` multiplexed stream; its existence is source-confirmed, delivery here is not live-verified.
2. **Sync overwrites events:** `sessioneer_headless_sync()` in `Sessions.php` unconditionally writes `blocked => null` for enumerated sessions before rebuilding prompts from list endpoints. This can erase a valid SSE request ID when those lists are empty. Define ownership/reconciliation of event-fed question state before claiming SSE-as-hooks works.
3. **Answer guard still precedes fallback:** `HeadlessRuntime::answer_prompt()` obtains `pending_prompt()` via the client's live lookup and rejects null before reaching `OpenCodeQuestionService::answer()`'s new status-store fallback. The stored request must participate in authoritative validation at the entry point too.
4. **Reply response mismatch:** `OpenCodeQuestionService::answer()` now posts to v2 but still accepts only `json_decode(stdout) === true`. The v2 contract is no-content, so successful v2 replies can be reported as failures. Capture/check HTTP status with protocol-specific handling. Whether that v2 route resolves the v1-observed pending request remains unverified.
5. **Request-specific clearing:** the prototype clears by session ID alone and sets idle, without comparing resolved `requestID` with the stored request. A late completion can erase a newer prompt. Preserve request identity, distinguish question from permission state, and allow resumed work status to arrive without pretending reply implies idle.
6. **Normalization and daemon robustness:** the parser assumes each `data:` line is standalone JSON and a top-level legacy `type`; its `payload` fallback does not unwrap a global event's nested type. Add explicit envelope normalization if using global/v2 streams, proper SSE frame handling, malformed-input behavior, bounded buffering, stderr draining, child reaping, shutdown and reconnect handling. Track only intended sessions. The prototype has no verified missed-event recovery.
7. **Installation and tests:** no consumer systemd unit or installer wiring was present. The original session's final todo also left event/status/reply tests and the full validation pass open. Earlier passing transcript/UI tests and PHP lint predate the final SSE edits and did not verify a complete real answer cycle.

Acceptance should exercise a synthetic server through the browser: question appears, option and custom answers go to the correct request, model/tool continuation is observed, prompt clears in both UI locations, and later polls do not resurrect or erase the wrong prompt. Include multiple questions/selections, simultaneous directories, externally answered/rejected prompts, duplicate/out-of-order events, missing/stale request IDs, wrong scope, disconnect/reconnect, malformed events, and non-success reply responses. Test no-content v2 success separately from legacy JSON success. Respect the existing SSR/JS rendering mirror and copy/search attributes.

## Evidence anchors and stale guidance

Stable source-session part IDs for retrieving the original evidence from `part.data`:

- `prt_07937b7780011dghM6RjgG1b5z`: summary identifying the successful v1 capture.
- `prt_079381235001SHJ7Ncd41Jz2Aw`: request ID/payload conclusion immediately after its extraction.
- `prt_079b30f2a0013A33E3btH52HRw`: exact-directory finding and multi-directory design choice.
- `prt_079baba84001de74mZR10xqZD1`: final continuation describing unfinished multi-directory/systemd/tests work.

The underlying tool outputs include `/tmp/opencode/v1_out.log` and `/tmp/opencode/v2b_out.log` captures; those temporary paths are historical, not durable dependencies. The persisted tool output contains the complete successful payload reproduced above.

**Repository guidance requiring care:** `CLAUDE.md` currently says `/event` emits only connected/heartbeat in 1.18.21. The exact-directory v1 capture contradicts that blanket statement. Its report about per-session v2 HTML should likewise be treated as an observation about a tested route/version, not the entire event capability. This document records the correction without changing unrelated instructions or the unfinished implementation. The prototype and question-service docblocks also overstate “list endpoints never work” and “WebUI uses the same endpoint”; the scoped/protocol distinctions above take precedence when resuming the investigation.
