'use strict';
// Runs clients/ios/SessioneerReply.js against stand-ins for Scriptable's globals and a scripted API.
// No network, no device. Used by tests/test_scriptable_client.php.
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'clients', 'ios', 'SessioneerReply.js'), 'utf8');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
let failures = 0;
const check = (ok, message) => { console.log((ok ? '  PASS: ' : '  FAIL: ') + message); if (!ok) failures++; };

// `rows` and `alerts` are scripted per scenario: each table presentation or alert consumes one step.
async function run(scenario) {
  const log = { requests: [], notifications: [], output: null, tables: [], alerts: [] };
  const keychain = new Map(Object.entries(scenario.keychain || {}));
  const steps = { tables: [...(scenario.tables || [])], alerts: [...(scenario.alerts || [])] };

  class Alert {
    constructor() { this.fields = []; }
    addTextField(p) { this.fields.push(p); }
    addSecureTextField(p) { this.fields.push(p); }
    addAction() {}
    addCancelAction() {}
    async presentAlert() { this.step = steps.alerts.shift() || { button: -1 }; log.alerts.push(this.title); return this.step.button; }
    textFieldValue() { return this.step.text; }
  }
  class UITableRow {
    constructor() { this.texts = []; this.dismissOnSelect = true; }
    addText(title, subtitle) { this.texts.push(title); if (subtitle) this.texts.push(subtitle); }
  }
  class UITable {
    constructor() { this.rows = []; }
    addRow(r) { this.rows.push(r); }
    removeAllRows() { this.rows = []; }
    reload() {}
    async present() {
      const step = steps.tables.shift() || { select: [] };
      log.tables.push(this.rows.map((r) => r.texts[0]));
      for (const key of step.select) {
        const row = this.rows.find((r) => r.texts[0].includes(key));
        if (!row) throw new Error('row not found: ' + key + ' in ' + JSON.stringify(this.rows.map((r) => r.texts[0])));
        if (row.onSelect) row.onSelect();
        if (row.dismissOnSelect) break;
      }
    }
  }
  class Request {
    constructor(url) { this.url = url; this.headers = {}; }
    async loadJSON() {
      log.requests.push({ method: this.method, url: this.url, headers: this.headers, body: this.body ? JSON.parse(this.body) : null });
      const reply = scenario.api(this.method, this.url.replace(/^https:\/\/s\.example/, ''));
      if (reply instanceof Error) throw reply;
      return reply;
    }
  }
  class Notification { async schedule() { log.notifications.push(this.body); } }

  const globals = {
    Alert, UITable, UITableRow, Request, Notification,
    Keychain: {
      contains: (k) => keychain.has(k), get: (k) => keychain.get(k),
      set: (k, v) => keychain.set(k, v), remove: (k) => keychain.delete(k),
    },
    Script: { complete() {}, setShortcutOutput(v) { log.output = v; } },
    args: scenario.args || {},
  };
  await new AsyncFunction(...Object.keys(globals), source)(...Object.values(globals));
  return { log, keychain, leftover: steps };
}

const sessions = { ok: true, count: 2, menu: ['1. Deploy — NEEDS REPLY', '2. Docs — IDLE 5m'], ids: ['cc-a', 'cc-b'] };
const question = (extra) => ({ index: 0, question: 'Run tests?', select_mode: 'one', multi_select: false, allows_text: true, choices: ['1. Yes', '2. No', '0. Type my own answer'], choice_ids: ['1', '2', '0'], ...extra });
const configured = { 'sessioneer.base': 'https://s.example', 'sessioneer.token': 'tok' };

(async () => {
  console.log('first run stores credentials in the keychain');
  let r = await run({ alerts: [{ button: 0, text: 'https://s.example/' }, { button: 0, text: 'secret-token' }, { button: 0, text: '' }], api: () => ({ ok: false, message: 'x' }), tables: [] });
  check(r.keychain.get('sessioneer.base') === 'https://s.example' && r.keychain.get('sessioneer.token') === 'secret-token', 'address (trailing slash trimmed) and token saved');
  check(!r.keychain.has('sessioneer.cf_id'), 'no Cloudflare headers when left empty');

  console.log('cancelling setup stores nothing');
  r = await run({ alerts: [{ button: -1 }], api: () => { throw new Error('no call expected'); } });
  check(r.keychain.size === 0 && r.log.requests.length === 0, 'nothing saved, nothing sent');

  console.log('single prompt: a predefined choice');
  r = await run({
    keychain: configured,
    tables: [{ select: ['NEEDS REPLY'] }, { select: ['2. No'] }],
    api: (m, u) => u.endsWith('/sessions') ? sessions
      : u.includes('/session?id=cc-a') ? { ok: true, id: 'cc-a', display_name: 'Deploy', prompt_kind: 'single', prompt_id: 'abc123abc123', questions: [question()] }
      : { ok: true, message: 'Answer sent' },
  });
  let post = r.log.requests[2];
  check(post.method === 'POST' && post.url.endsWith('/answers') && JSON.stringify(post.body) === JSON.stringify({ session: 'cc-a', prompt_id: 'abc123abc123', choice: '2' }), 'posts the choice id with session and prompt id');
  check(post.headers.Authorization === 'Bearer tok', 'sends the bearer token');
  check(r.log.notifications[0] === 'Answer sent', 'shows the result as a notification');

  console.log('single prompt: typed answer');
  r = await run({
    keychain: configured, args: { shortcutParameter: 'cc-a' },
    tables: [{ select: ['Type my own'] }], alerts: [{ button: 0, text: 'use sqlite' }],
    api: (m, u) => u.includes('/session?id=cc-a') ? { ok: true, id: 'cc-a', display_name: 'Deploy', prompt_kind: 'single', prompt_id: 'abc123abc123', questions: [question()] } : { ok: true, message: 'Answer sent' },
  });
  check(!r.log.requests.some((q) => q.url.endsWith('/sessions')), 'a session id parameter skips the menu');
  check(JSON.stringify(r.log.requests[1].body) === JSON.stringify({ session: 'cc-a', prompt_id: 'abc123abc123', text: 'use sqlite' }), 'posts text instead of a choice');

  console.log('multi-question prompt with a multi-select and a typed answer');
  r = await run({
    keychain: configured, args: { shortcutParameter: 'cc-a' },
    tables: [{ select: ['MySQL'] }, { select: ['Redis', 'Cache', 'Done'] }],
    api: (m, u) => u.includes('/session?id=') ? { ok: true, id: 'cc-a', display_name: 'Asking', prompt_kind: 'multi', prompt_id: 'abc123abc123', questions: [
      question({ question: 'Which DB?', allows_text: false, choices: ['1. MySQL', '2. SQLite'], choice_ids: ['1', '2'] }),
      question({ question: 'Extras?', select_mode: 'many', multi_select: true, allows_text: false, choices: ['1. Redis', '2. Queue', '3. Cache'], choice_ids: ['1', '2', '3'] }),
    ] } : { ok: true, message: 'Answer sent' },
  });
  check(JSON.stringify(r.log.requests[1].body.answers) === JSON.stringify(['1', '1,3']), 'answers list: one id, then comma-joined multi-select ids');

  console.log('multi-select with nothing ticked does not submit');
  r = await run({
    keychain: configured, args: { shortcutParameter: 'cc-a' }, tables: [{ select: ['Done'] }],
    api: (m, u) => u.includes('/session?id=') ? { ok: true, id: 'cc-a', display_name: 'Asking', prompt_kind: 'multi', prompt_id: 'abc123abc123', questions: [
      question({ question: 'Extras?', select_mode: 'many', multi_select: true, allows_text: false, choices: ['1. Redis'], choice_ids: ['1'] }), question()] } : { ok: true, message: 'x' },
  });
  check(r.log.requests.length === 1, 'no answer is posted');

  console.log('message flow shows recent messages and sends the text');
  r = await run({
    keychain: configured, args: { shortcutParameter: 'cc-b' },
    tables: [{ select: ['Write a message'] }], alerts: [{ button: 0, text: 'hello there' }],
    api: (m, u) => u.includes('/session?id=') ? { ok: true, id: 'cc-b', display_name: 'Docs — IDLE 5m', prompt_kind: 'none', recent_text: 'YOU: hi\n\nAGENT: done' } : { ok: true, message: 'Message sent' },
  });
  check(r.log.tables[0].length === 4 && r.log.tables[0][2] === 'YOU: hi' && r.log.tables[0][3] === 'AGENT: done', 'header, write row, then each recent message as its own row');
  check(JSON.stringify(r.log.requests[1].body) === JSON.stringify({ session: 'cc-b', text: 'hello there' }), 'posts the message');
  check(r.log.output === 'Message sent', 'returns the result to a calling Shortcut');

  console.log('dismissing the message screen sends nothing');
  r = await run({ keychain: configured, args: { shortcutParameter: 'cc-b' }, tables: [{ select: [] }],
    api: () => ({ ok: true, id: 'cc-b', display_name: 'Docs', prompt_kind: 'none', recent_text: '' }) });
  check(r.log.requests.length === 1, 'only the read happened');

  console.log('failures are shown, not thrown');
  r = await run({ keychain: configured, args: { shortcutParameter: 'cc-a' },
    api: () => ({ ok: false, code: 'prompt_changed', message: 'That prompt is no longer waiting (already answered?)' }) });
  check(r.log.notifications[0] === 'That prompt is no longer waiting (already answered?)', 'API error message reaches the user');
  r = await run({ keychain: configured, args: { shortcutParameter: 'cc-a' }, api: () => new Error('offline') });
  check(/Could not reach Sessioneer/.test(r.log.notifications[0]), 'a network or Cloudflare failure gets a plain message');

  console.log('Cloudflare service token headers');
  r = await run({ keychain: { ...configured, 'sessioneer.cf_id': 'id1', 'sessioneer.cf_secret': 'sec1' }, args: { shortcutParameter: 'cc-b' }, api: () => ({ ok: false, message: 'x' }) });
  check(r.log.requests[0].headers['CF-Access-Client-Id'] === 'id1' && r.log.requests[0].headers['CF-Access-Client-Secret'] === 'sec1', 'sent when stored');

  console.log('reset clears saved values');
  r = await run({ keychain: configured, args: { shortcutParameter: 'reset' }, alerts: [{ button: -1 }], api: () => { throw new Error('no call'); } });
  check(r.keychain.size === 0, 'keychain emptied, setup asked again');

  process.exit(failures > 0 ? 1 : 0);
})().catch((e) => { console.log('  FAIL: harness error ' + (e && e.stack || e)); process.exit(1); });
