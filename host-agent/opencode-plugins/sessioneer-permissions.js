/**
 * Sessioneer OpenCode plugin: bridge OpenCode's permission.ask hook into Claude
 * Session Manager.
 *
 * OpenCode has NO queryable server-side state for a pending permission (the
 * permission lives in-memory in the session's own process; GET /permission,
 * /api/permission/request and /api/session/:id/permission all return empty,
 * and the DB stores no permission records). The
 * pane can show a STALE dialog while a session is actively working, so a
 * footer heuristic false-positives. The authoritative signal is this plugin
 * hook: opencode fires permission.ask precisely when a permission is pending.
 *
 * It mirrors Claude Code's PermissionRequest hook -> SessionStatusStore
 * pattern: record the pending permission into a JSON store the host-agent
 * reads, and answer from an intent the host-agent writes back (applied
 * in-process, in the same process that owns the permission).
 *
 * Store: one JSON file per ses_* id under OPENCODE_PERMISSION_DIR (default
 * /run/user/<uid>/sessioneer-sessions/opencode-permissions/), same dir the PHP
 * host-agent's PermissionStore reads/writes.
 *
 *   { "permission": {<Permission>|null}, "intent": "allow"|"deny"|null }
 *
 * This is a global plugin (installed by Sessioneer's install.sh to
 * ~/.config/opencode/plugins/) so it loads for every oc-* session regardless
 * of cwd. It is pure-observe (never blocks the permission itself) except for
 * answering when Sessioneer has recorded an intent.
 */

import { mkdirSync, readFileSync, writeFileSync, renameSync, unlinkSync, existsSync } from "node:fs"
import { dirname, join } from "node:path"
import { tmpdir } from "node:os"

const SESSION_ID_PATTERN = /^ses_[A-Za-z0-9]+$/

function storeDir() {
  // OPENCODE_PERMISSION_DIR env override (tests / custom layouts), else the
  // same default the PHP Config::opencode_permission_dir() uses.
  if (process.env.OPENCODE_PERMISSION_DIR) return process.env.OPENCODE_PERMISSION_DIR
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

// Map a permission.asked event payload into the canonical Permission shape the
// PHP host-agent's PermissionStore reads (sessionID-keyed record; the pane is
// the authoritative on-screen source for the option set, this record only
// corroborates "a permission is pending"). Payload shape (from the opencode
// bundle): { id, sessionID, permission, patterns, metadata, always, tool }.
// `permission` is the action/tool key (e.g. "bash", "read",
// "external_directory"); `patterns` is an array of {permission, pattern}
// (or a plain string array); `metadata` carries details like the command.
function toPermissionRecord(p) {
  if (!p) return null
  const patterns = Array.isArray(p.patterns)
    ? p.patterns.map((x) => (typeof x === "string" ? x : x?.pattern ?? x?.permission ?? "")).filter(Boolean)
    : []
  const metadata = p.metadata && typeof p.metadata === "object" ? p.metadata : {}
  // Build a human title: the tool/action plus a short detail from metadata.
  const permKey = typeof p.permission === "string" ? p.permission : "permission"
  const detail = metadata?.command || metadata?.filePath || metadata?.description || patterns[0] || ""
  return {
    id: String(p.id ?? `per_${Date.now()}`),
    type: typeof p.permission === "string" ? p.permission : "",
    sessionID: p.sessionID,
    pattern: patterns.length === 1 ? patterns[0] : patterns,
    title: detail ? `${permKey}: ${detail}` : permKey,
    metadata: { ...metadata, message: detail || permKey },
    time: { created: Date.now() },
  }
}

export const SessioneerPermissionsPlugin = async ({ project, directory }) => {
  mkdirSync(storeDir(), { recursive: true, mode: 0o700 })
  // Heartbeat: prove the plugin actually runs (opencode registers it in debug
  // config but there was no log/event evidence it fires — this file's presence
  // is the cheapest way to confirm invocation from outside).
  writeFileSync(join(storeDir(), "_sessioneer-heartbeat.txt"), `init ${new Date().toISOString()}\n`, { flag: "a", mode: 0o600 })

  return {
    // Authoritative pending-permission signal. Record it (so the host-agent
    // can surface the blocked prompt), then decide whether to auto-answer.
    "permission.ask": async (input, output) => {
      const sessionId = input?.sessionID
      if (!sessionId || !SESSION_ID_PATTERN.test(sessionId)) return

      writeFileSync(join(storeDir(), "_sessioneer-heartbeat.txt"), `permission.ask ${sessionId} ${input?.title} ${new Date().toISOString()}\n`, { flag: "a", mode: 0o600 })

      const record = readRecord(sessionId)
      record.permission = input

      // If Sessioneer has already staged an answer intent, consume it and respond -
      // this is how the host-agent answers a permission it surfaced.
      if (record.intent === "allow" || record.intent === "deny") {
        output.status = record.intent
        writeRecord(sessionId, { permission: null, intent: null })
        return
      }

      // Otherwise record the pending permission and defer to the TUI (ask).
      writeRecord(sessionId, record)
      output.status = "ask"
    },

    event: async ({ event }) => {
      const type = event?.type
      writeFileSync(join(storeDir(), "_sessioneer-heartbeat.txt"), `event ${type} ${event?.properties?.sessionID ?? ""} ${new Date().toISOString()}\n`, { flag: "a", mode: 0o600 })

      // permission.asked: a permission is genuinely pending right now. This is
      // the authoritative "blocked" signal (the plugin permission.ask HOOK is
      // dormant in opencode 1.18.21 but the permission.asked EVENT fires on
      // the bus). Record it so the host-agent can surface the prompt. Payload
      // shape (from the opencode bundle): { id, sessionID, permission,
      // patterns, metadata, always, tool }.
      if (type === "permission.asked") {
        const sessionId = event?.properties?.sessionID
        if (sessionId && SESSION_ID_PATTERN.test(sessionId)) {
          const record = readRecord(sessionId)
          record.permission = toPermissionRecord(event.properties)
          writeRecord(sessionId, record)
        }
        return
      }

      // permission.replied carries { sessionID, permissionID, response } -
      // the block is resolved, so clear the record.
      if (type === "permission.replied") {
        const sessionId = event?.properties?.sessionID
        if (sessionId && SESSION_ID_PATTERN.test(sessionId)) {
          clearRecord(sessionId)
        }
        return
      }

      // permission.updated carries the full Permission (with sessionID) -
      // refresh the recorded record to match the live object.
      if (type === "permission.updated") {
        const sessionId = event?.properties?.sessionID
        if (sessionId && SESSION_ID_PATTERN.test(sessionId)) {
          const record = readRecord(sessionId)
          record.permission = event.properties
          writeRecord(sessionId, record)
        }
      }
    },
  }
}
