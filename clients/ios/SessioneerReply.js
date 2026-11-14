// Sessioneer Reply: answer a blocked prompt or message a session from an iPhone, using Scriptable.
// Nothing to edit here. The first run asks for your Sessioneer address, API token and (optional) Cloudflare token, and keeps
// them in the iOS Keychain. See docs/ios-scriptable.md.
//
// Run it with a parameter to change behaviour:
//   reset            forget the saved address and token, then ask again
//   <session id>     skip the session menu (also: scriptable:///run?scriptName=...&session=<id>)

// Optional: your address (not a secret), for example 'https://sessioneer.example.com'. When set, it is never asked for.
const BASE_URL = '';

const KEYS = { base: 'sessioneer.base', token: 'sessioneer.token', cfId: 'sessioneer.cf_id', cfSecret: 'sessioneer.cf_secret', cfAsked: 'sessioneer.cf_asked' };
const API = '/api/shortcuts/v1';

class ApiError extends Error {}

async function ask(title, message, placeholder, secure) {
  const alert = new Alert();
  alert.title = title;
  alert.message = message;
  if (secure) alert.addSecureTextField(placeholder, '');
  else alert.addTextField(placeholder, '');
  alert.addAction('OK');
  alert.addCancelAction('Cancel');
  if ((await alert.presentAlert()) === -1) return null;
  return alert.textFieldValue(0).trim();
}

// Each answer is saved the moment it is given, so an interrupted setup resumes where it stopped.
async function loadConfig(reset) {
  if (reset) Object.values(KEYS).forEach((key) => { if (Keychain.contains(key)) Keychain.remove(key); });
  if (BASE_URL) Keychain.set(KEYS.base, BASE_URL.replace(/\/+$/, ''));
  if (!Keychain.contains(KEYS.base)) {
    const base = await ask('Sessioneer address', 'For example https://sessioneer.example.com (no trailing slash).', 'https://', false);
    if (!base) return null;
    Keychain.set(KEYS.base, base.replace(/\/+$/, ''));
  }
  if (!Keychain.contains(KEYS.token)) {
    const token = await ask('API token', 'The value of SHORTCUTS_API_TOKEN.', 'token', true);
    if (!token) return null;
    Keychain.set(KEYS.token, token);
  }
  if (!Keychain.contains(KEYS.cfAsked)) {
    const cfId = await ask('Cloudflare Access client id', 'Only if the address is behind Cloudflare Access. Leave empty otherwise.', 'client id', false);
    if (cfId === null) return null;
    if (cfId) {
      const cfSecret = await ask('Cloudflare Access client secret', 'The matching secret.', 'secret', true);
      if (!cfSecret) return null;
      Keychain.set(KEYS.cfId, cfId);
      Keychain.set(KEYS.cfSecret, cfSecret);
    }
    Keychain.set(KEYS.cfAsked, '1');
  }
  return {
    base: Keychain.get(KEYS.base),
    token: Keychain.get(KEYS.token),
    cfId: Keychain.contains(KEYS.cfId) ? Keychain.get(KEYS.cfId) : '',
    cfSecret: Keychain.contains(KEYS.cfSecret) ? Keychain.get(KEYS.cfSecret) : '',
  };
}

// One request. A reply that is not Sessioneer's JSON (a Cloudflare login page, a proxy error) is
// reported as such; Sessioneer's own failures carry a short message that is safe to show.
async function call(cfg, method, path, body) {
  const request = new Request(cfg.base + API + path);
  request.method = method;
  request.headers = { Authorization: 'Bearer ' + cfg.token, Accept: 'application/json' };
  if (cfg.cfId && cfg.cfSecret) {
    request.headers['CF-Access-Client-Id'] = cfg.cfId;
    request.headers['CF-Access-Client-Secret'] = cfg.cfSecret;
  }
  if (body !== undefined) {
    request.headers['Content-Type'] = 'application/json';
    request.body = JSON.stringify(body);
  }
  let json;
  try {
    json = await request.loadJSON();
  } catch (e) {
    throw new ApiError('Could not reach Sessioneer (offline, or blocked before the API).');
  }
  if (!json || json.ok !== true) throw new ApiError((json && json.message) || 'Request failed');
  return json;
}

// A tappable list. Resolves with the chosen index, or -1 when dismissed. `header` is shown on top.
async function chooseOne(header, labels, tall) {
  let chosen = -1;
  const table = new UITable();
  table.showSeparators = true;
  const head = new UITableRow();
  head.isHeader = true;
  head.addText(header);
  table.addRow(head);
  labels.forEach((label, index) => {
    const row = new UITableRow();
    row.height = tall ? 64 : 48;
    row.addText(label);
    row.onSelect = () => { chosen = index; };
    table.addRow(row);
  });
  await table.present(false);
  return chosen;
}

// Tick several rows, then tap Done. Resolves with the ticked indexes, or null when dismissed.
async function chooseMany(header, labels) {
  const ticked = new Set();
  let finished = false;
  const table = new UITable();
  table.showSeparators = true;
  const draw = () => {
    table.removeAllRows();
    const head = new UITableRow();
    head.isHeader = true;
    head.addText(header);
    table.addRow(head);
    labels.forEach((label, index) => {
      const row = new UITableRow();
      row.height = 48;
      row.dismissOnSelect = false;
      row.addText((ticked.has(index) ? '☑︎  ' : '☐  ') + label);
      row.onSelect = () => {
        if (ticked.has(index)) ticked.delete(index); else ticked.add(index);
        draw();
        table.reload();
      };
      table.addRow(row);
    });
    const done = new UITableRow();
    done.height = 56;
    done.addText('Done', ticked.size === 0 ? 'Pick at least one' : ticked.size + ' selected');
    done.dismissOnSelect = ticked.size > 0;
    done.onSelect = () => { finished = ticked.size > 0; };
    table.addRow(done);
  };
  draw();
  await table.present(false);
  return finished ? Array.from(ticked).sort((a, b) => a - b) : null;
}

async function typeText(title, message) {
  const text = await ask(title, message, 'Your answer', false);
  return text === null || text === '' ? null : text;
}

async function pickSession(cfg) {
  const list = await call(cfg, 'GET', '/sessions');
  if (list.count === 0) throw new ApiError('No live sessions.');
  const index = await chooseOne('Sessioneer', list.menu, false);
  return index === -1 ? null : list.ids[index];
}

// Recent messages sit below a "write a message" row, so long context scrolls instead of pushing the field away.
async function messageFlow(cfg, detail) {
  const parts = detail.recent_text ? detail.recent_text.split('\n\n') : [];
  let write = false;
  const table = new UITable();
  table.showSeparators = true;
  const head = new UITableRow();
  head.isHeader = true;
  head.addText(detail.display_name);
  table.addRow(head);
  const compose = new UITableRow();
  compose.height = 56;
  compose.addText('✎  Write a message');
  compose.onSelect = () => { write = true; };
  table.addRow(compose);
  parts.forEach((part) => {
    const row = new UITableRow();
    row.height = Math.min(240, 36 + Math.ceil(part.length / 38) * 20);
    row.addText(part);
    table.addRow(row);
  });
  await table.present(false);
  if (!write) return null;
  const text = await typeText('Message', detail.display_name);
  if (text === null) return null;
  return call(cfg, 'POST', '/messages', { session: detail.id, text });
}

// One question to its answer string for the API ("2", "1,3" or "text:..."), or null when cancelled.
async function answerQuestion(question) {
  const ids = question.choice_ids;
  if (question.select_mode === 'many') {
    const picked = await chooseMany(question.question, question.choices);
    return picked === null ? null : picked.map((i) => ids[i]).join(',');
  }
  const index = await chooseOne(question.question, question.choices, true);
  if (index === -1) return null;
  if (ids[index] !== '0') return ids[index];
  const text = await typeText('Your answer', question.question);
  return text === null ? null : 'text:' + text;
}

async function promptFlow(cfg, detail) {
  const base = { session: detail.id, prompt_id: detail.prompt_id };
  if (detail.prompt_kind === 'single') {
    const answer = await answerQuestion(detail.questions[0]);
    if (answer === null) return null;
    return call(cfg, 'POST', '/answers', answer.startsWith('text:')
      ? { ...base, text: answer.slice(5) }
      : { ...base, choice: answer });
  }
  const answers = [];
  for (const question of detail.questions) {
    const answer = await answerQuestion(question);
    if (answer === null) return null;
    answers.push(answer);
  }
  return call(cfg, 'POST', '/answers', { ...base, answers });
}

async function finish(message) {
  const note = new Notification();
  note.title = 'Sessioneer';
  note.body = message;
  await note.schedule();
  Script.setShortcutOutput(message);
}

async function main() {
  const parameter = String(args.shortcutParameter || '').trim();
  const query = args.queryParameters || {};
  const cfg = await loadConfig(parameter === 'reset' || query.reset === '1');
  if (cfg === null) return;
  try {
    const presetId = query.session || (parameter && parameter !== 'reset' ? parameter : '');
    const id = presetId || (await pickSession(cfg));
    if (!id) return;
    const detail = await call(cfg, 'GET', '/session?id=' + encodeURIComponent(id));
    const result = detail.prompt_kind === 'none' ? await messageFlow(cfg, detail) : await promptFlow(cfg, detail);
    if (result) await finish(result.message);
  } catch (e) {
    if (!(e instanceof ApiError)) throw e;
    await finish(e.message);
  }
}

await main();
Script.complete();
