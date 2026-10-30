/**
 * Sessioneer OpenCode plugin: mirror OpenCode's pending permission into the
 * store the host-agent reads.
 *
 * OpenCode v2 serves pending permission requests from
 * GET /api/permission/request, but that list is directory-scoped: an unscoped
 * read only reports the server's own working directory. The host-agent asks
 * once per live session directory (OpenCodeServeClient::pending_permissions()),
 * so the API is the primary source. This plugin is the fallback for a prompt
 * the API list does not expose, and it is the only place that can answer a
 * permission in-process from an intent staged by the host-agent.
 *
 * v2 plugin shape: a default export with an `id` and `setup(ctx)`. Hooks are
 * registered on their domain (`ctx.permission.hook("evaluate", ...)`), and
 * server events arrive through `ctx.event.subscribe(...)`, an async iterable.
 * A setup-returned hook map is V1 and is ignored by v2.
 *
 * Store: one JSON file per ses_* id under OPENCODE_PERMISSION_DIR (default
 * $SIDECAR_DIR/opencode-permissions, else
 * /run/user/<uid>/sessioneer-sessions/opencode-permissions), resolved the same
 * way as the PHP Config::opencode_permission_dir() so both sides agree when the
 * server and the host-agent see the same environment.
 *
 *   { "permission": {<Permission>|null}, "intent": "allow"|"deny"|null }
 *
 * Installed by Sessioneer's install.sh to ~/.config/opencode/plugins/ so it
 * loads for every oc-* session regardless of cwd. It is pure-observe (never
 * changes an effect unless Sessioneer staged an intent).
 */

import { mkdirSync, readFileSync, writeFileSync, renameSync, unlinkSync, existsSync } from "node:fs"
import { dirname, join } from "node:path"

const SESSION_ID_PATTERN = /^ses_[A-Za-z0-9]+$/

function storeDir() {
  if (process.env.OPENCODE_PERMISSION_DIR) return process.env.OPENCODE_PERMISSION_DIR
  if (process.env.SIDECAR_DIR) return join(process.env.SIDECAR_DIR, "opencode-permissions")
  const uid = typeof process.getuid === "function" ? process.getuid() : 1000
  return `/run/user/${uid}/sessioneer-sessions/opencode-permissions`
}

function fileFor(sessionId) {
  return join(storeDir(), `${sessionId}.json`)
}

function readRecord(sessionId) {
  const path = fileFor(sessionId)
  if (!existsSync(path)) return { permission: null, intent: null }
  try {
    const data = JSON.parse(readFileSync(path, "utf8"))
    return {
      permission: data && data.permission ? data.permission : null,
      intent: data && typeof data.intent === "string" ? data.intent : null,
    }
  } catch {
    return { permission: null, intent: null }
  }
}

// Atomic write (tmp + rename) so a concurrent PHP read never sees half a file.
function writeRecord(sessionId, record) {
  const path = fileFor(sessionId)
  mkdirSync(dirname(path), { recursive: true, mode: 0o700 })
  const tmp = `${path}.tmp.${Math.random().toString(16).slice(2)}`
  writeFileSync(tmp, JSON.stringify(record, null, 2), { mode: 0o600 })
  renameSync(tmp, path)
}

function clearRecord(sessionId) {
  try {
    unlinkSync(fileFor(sessionId))
  } catch {
    // not present - fine
  }
}

function heartbeat(line) {
  try {
    mkdirSync(storeDir(), { recursive: true, mode: 0o700 })
    writeFileSync(join(storeDir(), "_sessioneer-heartbeat.txt"), `${line} ${new Date().toISOString()}\n`, { flag: "a", mode: 0o600 })
  } catch {
    // a missing store directory must never break a permission decision
  }
}

// Canonical Permission shape the PHP host-agent's PermissionStore reads:
// { id, type, sessionID, pattern, patterns, title, metadata, time }.
// v2 sources: the evaluate hook event {sessionID, agent, action, resources,
// source, effect} (no request id yet) and the permission.asked event data
// {id, sessionID, action, resources, save, source}. `action` is the tool key
// (e.g. "read", "shell"); `resources` are the patterns being asked about.
function toPermissionRecord(p) {
  if (!p) return null
  const resources = Array.isArray(p.resources)
    ? p.resources.filter((x) => typeof x === "string" && x !== "")
    : []
  const action = typeof p.action === "string" && p.action !== "" ? p.action : "permission"
  const detail = resources[0] || (typeof p.message === "string" ? p.message : "")
  return {
    id: typeof p.id === "string" ? p.id : null,
    type: action,
    sessionID: p.sessionID,
    pattern: resources.length === 1 ? resources[0] : resources,
    patterns: resources,
    title: detail ? `${action}: ${detail}` : action,
    metadata: { patterns: resources },
    time: { created: Date.now() },
  }
}

export default {
  id: "sessioneer.permissions",
  async setup(ctx) {
    const instanceDirectory = ctx.location && typeof ctx.location.directory === "string" ? ctx.location.directory : null

    heartbeat("init")

    await ctx.permission.hook("evaluate", (event) => {
      const sessionId = event && event.sessionID
      if (!sessionId || !SESSION_ID_PATTERN.test(sessionId)) return

      const record = readRecord(sessionId)

      // An intent staged by Sessioneer answers the prompt in the process that
      // owns it. Only an "ask" may be overridden: turning an already-allowed
      // action into a deny would be a silent policy change.
      if (event.effect === "ask" && (record.intent === "allow" || record.intent === "deny")) {
        event.effect = record.intent
        clearRecord(sessionId)
        heartbeat(`intent ${record.intent} ${sessionId}`)
        return
      }

      if (event.effect === "ask") {
        record.permission = toPermissionRecord(event)
        writeRecord(sessionId, record)
        heartbeat(`evaluate ask ${sessionId}`)
      }
    })

    const controller = new AbortController()

    void (async () => {
      for await (const event of ctx.event.subscribe({
        types: ["permission.asked", "permission.updated", "permission.replied"],
        signal: controller.signal,
      })) {
        // Every location's plugin instance sees the same server event stream;
        // act only on events from this instance's own directory.
        const eventDirectory = event && event.location && typeof event.location.directory === "string" ? event.location.directory : null
        if (eventDirectory !== null && instanceDirectory !== null && eventDirectory !== instanceDirectory) continue

        const data = event && event.data ? event.data : null
        const sessionId = data && data.sessionID
        if (!sessionId || !SESSION_ID_PATTERN.test(sessionId)) continue

        if (event.type === "permission.asked" || event.type === "permission.updated") {
          const record = readRecord(sessionId)
          record.permission = toPermissionRecord(data)
          writeRecord(sessionId, record)
          heartbeat(`${event.type} ${sessionId}`)
        } else if (event.type === "permission.replied") {
          clearRecord(sessionId)
          heartbeat(`replied ${sessionId}`)
        }
      }
    })().catch(() => {
      // The subscription ends when the plugin unloads; nothing to report.
    })

    return () => controller.abort()
  },
}
