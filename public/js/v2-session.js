/* Sessioneer v2 session page, core: transcript, live polling, blocked prompts,
   header and the message footer. Plain ES5 on purpose (see CLAUDE.md). The
   sheets (controls, details, files, search, viewers) live in
   v2-session-sheets.js and use the small API this file publishes on V2S.api. */
(function () {
  'use strict';

  var boot = window.V2S;
  var shell = document.getElementById('v2s');
  if (!boot || !shell) { return; }

  // markdown.js (shared with the classic pages) calls this global.
  window.escapeHtml = function (s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };
  var esc = window.escapeHtml;

  var session = boot.session;
  var csrf = boot.csrfToken;
  var detail = boot.detail || {};

  // ---- storage (a private-mode Safari throws on access) ----
  function load(key, fallback) {
    try { var v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; }
  }
  function save(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
  }
  function drop(key) {
    try { localStorage.removeItem(key); } catch (e) {}
  }

  var prefs = {
    reasoning: load('v2s-reasoning', '0') === '1',
    subagent: load('v2s-subagent', '1') === '1',
    confirm: load('v2s-confirm', '0') === '1',
    pollMs: parseInt(load('v2-poll', '3000'), 10) || 3000
  };

  // ---- dom + small helpers ----
  function $(id) { return document.getElementById(id); }
  function closest(el, sel) {
    while (el && el.nodeType === 1) { if (el.matches(sel)) { return el; } el = el.parentNode; }
    return null;
  }
  var scroller = $('v2s-scroll');
  var listEl = $('v2s-list');
  var thinkingEl = $('v2s-thinking');
  var errorEl = $('v2s-error');
  var promptEl = $('v2s-prompt');
  var textEl = $('v2s-text');
  var sendBtn = $('v2s-send');
  var stopBtn = $('v2s-stop');

  var toastTimer = null;
  function toast(message, bad) {
    var el = $('v2-toast');
    el.textContent = message;
    el.className = 'v2-toast' + (bad ? ' is-bad' : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, bad ? 6000 : 2500);
  }

  function parse(r) {
    return r.json().catch(function () { return { ok: false, message: 'Unexpected response (' + r.status + ')' }; });
  }
  function post(url, params) {
    var body = new URLSearchParams();
    body.append('csrf_token', csrf);
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (Array.isArray(v)) { v.forEach(function (item) { body.append(k + '[]', item); }); } else { body.append(k, v); }
    });
    return fetch(url, {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
    }).then(parse);
  }
  function getJson(url, signal) {
    return fetch(url, { credentials: 'same-origin', signal: signal }).then(parse);
  }
  function qs(params) {
    return Object.keys(params).filter(function (k) { return params[k] !== null && params[k] !== undefined; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
  }

  function copyText(text, doneMessage) {
    var done = function () { toast(doneMessage || 'Copied'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { toast('Copy failed', true); });
      return;
    }
    // Plain-HTTP LAN origin: the async clipboard API is unavailable there.
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;left:-9999px';
    document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, text.length);
    try { document.execCommand('copy') ? done() : toast('Copy failed', true); } catch (e) { toast('Copy failed', true); }
    document.body.removeChild(ta);
  }

  function relTime(iso) {
    var ms = iso ? Date.parse(iso) : NaN;
    if (isNaN(ms)) { return ''; }
    var diff = Math.floor(Date.now() / 1000 - ms / 1000);
    if (diff < 60) { return 'just now'; }
    if (diff < 3600) { return Math.floor(diff / 60) + ' min ago'; }
    if (diff < 86400) { var h = Math.floor(diff / 3600); return h + ' hr' + (h > 1 ? 's' : '') + ' ago'; }
    var d = Math.floor(diff / 86400);
    return d + ' day' + (d > 1 ? 's' : '') + ' ago';
  }
  function fileSize(bytes) {
    if (bytes < 1024) { return bytes + ' B'; }
    if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  // ---- text of each copyable block, kept here instead of in the DOM ----
  var raws = [];
  function rawRef(text) { raws.push(String(text == null ? '' : text)); return raws.length - 1; }

  // ======================================================================
  // transcript rendering
  // ======================================================================

  function summary(text) {
    var trimmed = String(text).trim();
    var first = trimmed.split('\n', 1)[0];
    var cut = first.length > 90 ? first.slice(0, 90) + '…' : first;
    return cut + (trimmed.length > cut.length ? ' …' : '');
  }

  function prettyJson(text) {
    var t = String(text).trim();
    if (!t || (t.charAt(0) !== '{' && t.charAt(0) !== '[')) { return text; }
    try {
      var parsed = JSON.parse(t);
      if (parsed && typeof parsed === 'object') { return JSON.stringify(parsed, null, 2); }
    } catch (e) { /* not JSON */ }
    return text;
  }

  function relativize(path, cwd) {
    if (!cwd) { return path; }
    var prefix = cwd.replace(/\/+$/, '') + '/';
    return path.indexOf(prefix) === 0 ? path.slice(prefix.length) : path;
  }

  function codeBlock(text, tag) {
    var ref = rawRef(text);
    return '<div class="v2s-code">' + (tag ? '<div class="v2s-code-tag">' + esc(tag) + '</div>' : '')
      + '<pre>' + esc(text) + '</pre>'
      + '<div class="v2s-code-actions"><button type="button" class="v2s-link" data-act="copy" data-raw="' + ref + '">Copy</button>'
      + '<button type="button" class="v2s-link" data-act="fullscreen" data-raw="' + ref + '" data-title="' + esc(tag || 'Output') + '">Full screen</button></div></div>';
  }

  function mediaHtml(block, line) {
    var out = '';
    if (block.image) {
      var src = 'data:' + esc(block.image.media_type) + ';base64,' + esc(block.image.data);
      out += '<img class="v2s-thumb" data-act="image" src="' + src + '" data-full="' + src + '" loading="lazy" alt="Image">';
    }
    (block.attachments || []).forEach(function (a) {
      var url = '/session_attachment.php?' + qs({ session: session, line: line, file_uuid: a.file_uuid });
      if (a.isImage) {
        out += '<span><img class="v2s-thumb" data-act="image" src="' + esc(url) + '" data-full="' + esc(url) + '" loading="lazy" alt="' + esc(a.filename) + '"></span>';
      } else {
        out += '<a class="v2s-file" href="' + esc(url) + '" download="' + esc(a.filename) + '"><span aria-hidden="true">&#8681;</span><span>' + esc(a.filename) + '</span><span class="v2-muted">' + esc(fileSize(a.size || 0)) + '</span></a>';
      }
    });
    return out ? '<div class="v2s-media">' + out + '</div>' : '';
  }

  // ---- answered questions as Question / Answer rows ----
  var ANSWERED_PREFIX = 'User has answered your questions:';
  var ANSWERS_KEY = 'v2s-answers:' + session;
  var ANSWERS_KEEP = 12;

  function storedAnswers() {
    try { var v = JSON.parse(load(ANSWERS_KEY, '[]')); return Array.isArray(v) ? v : []; } catch (e) { return []; }
  }
  // The exact pairs this browser sent beat the regex, which cannot tell a quote inside an answer from the closing one.
  function rememberAnswers(pairs) {
    var all = storedAnswers();
    all.push(pairs);
    save(ANSWERS_KEY, JSON.stringify(all.slice(-ANSWERS_KEEP)));
  }
  // Claude Code and OpenCode write: User has answered your questions: "Q"="A", "Q2"="B". You can now continue ...
  function parseAnswered(text) {
    var t = String(text == null ? '' : text).trim();
    if (t.indexOf(ANSWERED_PREFIX) !== 0) { return null; }
    var known = storedAnswers().filter(function (pairs) {
      return pairs.length && pairs.every(function (p) { return t.indexOf(p.q) !== -1 && t.indexOf(p.a) !== -1; });
    })[0];
    if (known) { return known; }
    var re = /"([\s\S]*?)"="([\s\S]*?)"(?=, "|\.\s*You can now continue|\s*$)/g;
    var pairs = [];
    var m;
    while ((m = re.exec(t.slice(ANSWERED_PREFIX.length))) !== null) { pairs.push({ q: m[1], a: m[2] }); }
    return pairs.length ? pairs : null;
  }
  function answerRows(pairs) {
    return '<div class="v2s-qa">' + pairs.map(function (p) {
      return '<div class="v2s-qa-row"><span class="v2s-label">Question</span><p>' + esc(p.q) + '</p><span class="v2s-label">Answer</span><p class="v2s-qa-a">' + esc(p.a) + '</p></div>';
    }).join('') + '</div>';
  }

  function textBlock(text) {
    return '<div class="v2s-text">' + renderMarkdown(text) + '</div>'
      + '<div class="v2s-actions"><button type="button" class="v2s-link" data-act="copy" data-raw="' + rawRef(text) + '">Copy</button></div>';
  }

  function renderBlock(block, line, ctx) {
    var text = block.text == null ? '' : String(block.text);
    var media = mediaHtml(block, line);
    switch (block.kind) {
      case 'text':
        return '<div data-line="' + line + '">' + textBlock(text) + '</div>';
      case 'thinking':
        return '<div class="v2s-think" data-line="' + line + '"><span class="v2s-label">Reasoning</span><div class="v2s-text">' + renderMarkdown(text) + '</div></div>';
      case 'plan':
        return '<div data-line="' + line + '"><span class="v2s-label">Plan</span>' + textBlock(text) + '</div>';
      case 'tool_use':
        return '<div data-line="' + line + '">' + (block.description ? '<p class="v2s-desc">' + esc(block.description) + '</p>' : '')
          + codeBlock(text, block.tool_name || 'Call') + '</div>' + media;
      case 'tool_result':
        var answered = parseAnswered(text);
        if (answered) { return '<div data-line="' + line + '">' + answerRows(answered) + '</div>' + media; }
        return '<div data-line="' + line + '">'
          + (block.agent_type != null ? '<div class="v2s-text">' + renderMarkdown(text) + '</div>' : codeBlock(prettyJson(text), 'Output'))
          + '</div>' + media;
      case 'task_notification':
        var status = block.status === 'failed' ? 'Subagent failed' : (block.status == null || block.status === 'completed' ? 'Subagent finished' : 'Subagent ' + block.status);
        return '<div data-line="' + line + '"><p class="v2s-desc">' + esc(block.summary != null ? status + ': ' + block.summary : status) + '</p>'
          + '<div class="v2s-text">' + renderMarkdown(text) + '</div></div>';
      case 'image':
        return media || (text ? '<p class="v2-muted v2-small" data-line="' + line + '">' + esc(text) + '</p>' : '');
      case 'question':
        return questionBlock(block, line);
      default:
        return text ? '<p class="v2-muted v2-small" data-line="' + line + '">' + esc(text) + '</p>' : '';
    }
  }

  function entryKind(entry) {
    var blocks = entry.blocks || [];
    function has(kind) { return blocks.some(function (b) { return b.kind === kind; }); }
    var hasText = has('text');
    var planStatus = null;
    blocks.forEach(function (b) { if (b.plan_status != null) { planStatus = b.plan_status; } });
    var isSub = blocks.some(function (b) { return b.agent_type != null; });
    if (!hasText && planStatus != null) { return planStatus === 'approved' ? 'plan_approved' : 'plan_rejected'; }
    if (!hasText && has('plan')) { return 'plan'; }
    if (!hasText && has('question')) { return 'question'; }
    if (!hasText && isSub) { return has('tool_use') ? 'sub_call' : 'sub_result'; }
    if (!hasText && has('task_notification')) { return 'sub_result'; }
    if (!hasText && has('thinking')) { return 'thinking'; }
    if (!hasText && has('tool_use')) { return 'tool_use'; }
    if (!hasText && has('tool_result')) { return 'tool_result'; }
    if (entry.role === 'assistant' || entry.role === 'user') { return entry.role; }
    return 'system';
  }

  var KIND_LABEL = {
    plan: 'Plan', plan_approved: 'Plan approved', plan_rejected: 'Plan rejected', question: 'Question',
    sub_call: 'Subagent call', sub_result: 'Subagent report', tool_use: 'Tool call', tool_result: 'Tool output', system: 'System'
  };

  function hasMedia(entry) {
    return (entry.blocks || []).some(function (b) { return !!b.image || (b.attachments && b.attachments.length > 0); });
  }

  function lineAttr(entries) {
    return entries.filter(function (e) { return e; }).map(function (e) { return e.line; }).join(' ');
  }

  function metaHtml(entry, label) {
    var t = relTime(entry.timestamp);
    return '<div class="v2s-meta">' + (label ? '<span class="v2s-label">' + esc(label) + '</span>' : '')
      + (t ? '<span title="' + esc(entry.timestamp || '') + '">' + esc(t) + '</span>' : '') + '</div>';
  }

  function renderEntry(entry) {
    var kind = entryKind(entry);
    var isSub = kind === 'sub_call' || kind === 'sub_result';
    var blocksHtml = (entry.blocks || []).map(function (b) { return renderBlock(b, entry.line, { sub: isSub }); }).join('');
    var el = document.createElement('div');
    var cls = 'v2s-entry';
    var label = '';

    if (kind === 'user') { cls += ' v2s-user'; }
    else if (kind === 'assistant') { cls += ' v2s-assistant'; }
    else if (kind === 'thinking') { cls += ' v2s-assistant'; }
    else {
      cls += ' v2s-card';
      if (kind.indexOf('plan') === 0 || kind === 'question') { cls += ' v2s-plan'; }
      if (isSub) { cls += ' v2s-sub-card'; if (!hasMedia(entry)) { cls += ' v2s-sub-only'; } }
      label = KIND_LABEL[kind] || '';
    }
    if (kind === 'assistant') { label = ''; }

    el.className = cls;
    el.setAttribute('data-lines', String(entry.line));
    el.innerHTML = metaHtml(entry, label) + blocksHtml;
    if (kind === 'user' || kind === 'assistant') { el.setAttribute('data-msg', kind); }

    return el;
  }

  function groupable(entry) {
    var k = entryKind(entry);
    return (k === 'tool_use' || k === 'tool_result') && !hasMedia(entry);
  }

  function firstTextBlock(entry) {
    if (!entry) { return null; }
    return (entry.blocks || []).filter(function (b) { return !!b.text; })[0] || null;
  }

  function toolSummary(callEntry, resultEntry) {
    var c = firstTextBlock(callEntry);
    var cwd = detail.workdir || null;
    if (c) {
      var named = /^tool: (\S+)/.exec(c.text) || /^(\w+)\(/.exec(c.text);
      var name = c.tool_name || (named ? named[1] : 'Tool');
      if (c.tool_name && c.file_path) { return { head: c.description || name, line: name + ' ' + relativize(c.file_path, cwd) }; }
      if (c.command) { return { head: c.description || name, line: summary(c.command) }; }
      return { head: c.description || name, line: summary(named ? c.text.replace(/^tool: \S+\s*-?\s*/, '').replace(/^\w+\((.*)\)$/, '$1') : c.text) };
    }
    var r = firstTextBlock(resultEntry);
    return { head: 'Tool output', line: r ? summary(r.text) : '' };
  }

  function toolBody(callEntry, resultEntry) {
    var html = '';
    [callEntry, resultEntry].forEach(function (entry) {
      if (!entry) { return; }
      html += (entry.blocks || []).map(function (b) { return renderBlock(b, entry.line, { sub: false }); }).join('');
    });
    return html;
  }

  function renderToolCall(callEntry, resultEntry) {
    var s = toolSummary(callEntry, resultEntry);
    var d = document.createElement('details');
    d.className = 'v2s-tool';
    d.setAttribute('data-lines', lineAttr([callEntry, resultEntry]));
    d.innerHTML = '<summary><b>' + esc(s.head) + '</b><span>' + esc(s.line) + '</span></summary><div class="v2s-tool-body">' + toolBody(callEntry, resultEntry) + '</div>';
    d._call = callEntry;

    return d;
  }

  function upgradeToolCall(el, resultEntry) {
    el.setAttribute('data-lines', lineAttr([el._call, resultEntry]));
    el.querySelector('.v2s-tool-body').innerHTML = toolBody(el._call, resultEntry);
  }

  function newTailState() { return { pendingCall: null }; }
  var tail = newTailState();

  // Renders `entries` into `container`; returns the top-level nodes created or extended.
  function renderEntries(entries, state, container) {
    var touched = [];
    entries.forEach(function (entry) {
      if (!groupable(entry)) {
        state.pendingCall = null;
        var el = renderEntry(entry);
        container.appendChild(el);
        touched.push(el);
        return;
      }
      if (entryKind(entry) === 'tool_use') {
        var callEl = renderToolCall(entry, null);
        container.appendChild(callEl);
        touched.push(callEl);
        state.pendingCall = callEl;
        return;
      }
      if (state.pendingCall) {
        upgradeToolCall(state.pendingCall, entry);
        if (touched.indexOf(state.pendingCall) === -1) { touched.push(state.pendingCall); }
        state.pendingCall = null;
        return;
      }
      var orphan = renderToolCall(null, entry);
      container.appendChild(orphan);
      touched.push(orphan);
    });

    return touched;
  }

  // ======================================================================
  // transcript state: first page, older pages, live tail
  // ======================================================================

  // Bumped on every reset so a response that was already in flight cannot paint into the new transcript.
  var gen = 0;
  var T = { newest: null, nextBefore: null, hasMore: false, loading: false, agentSessionId: detail.agent_session_id || null, jump: boot.jumpLine || null };
  var pending = [];

  function showEmpty() {
    $('v2s-empty').hidden = listEl.children.length > 0;
  }

  function nearBottom() {
    return scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 140;
  }
  function toBottom() {
    scroller.scrollTop = scroller.scrollHeight;
    $('v2s-newdot').hidden = true;
  }
  function historyUrl(extra) {
    var p = { session: session, limit: 30 };
    if (prefs.reasoning) { p.include_thinking = 1; }
    Object.keys(extra).forEach(function (k) { p[k] = extra[k]; });

    return '/v2/history?' + qs(p);
  }

  function setOlderButtons() {
    $('v2s-older').hidden = !T.hasMore;
  }

  function flash(el, cls) {
    el.classList.add(cls);
    setTimeout(function () { el.classList.remove(cls); }, 3200);
  }

  function resetTranscript() {
    listEl.innerHTML = '';
    raws = [];
    pending = [];
    tail = newTailState();
    T.newest = null; T.nextBefore = null; T.hasMore = false;
    gen++;
  }

  // First page (the latest, or the one ending at a search hit).
  function loadFirstPage(jumpLine) {
    T.loading = true;
    var extra = jumpLine ? { before: jumpLine + 1 } : {};
    var myGen = gen;

    return getJson(historyUrl(extra)).then(function (data) {
      if (myGen !== gen) { return; }
      T.loading = false;
      if (!data || !data.ok) { showEmpty(); return; }
      var entries = data.entries || [];
      renderEntries(entries, tail, listEl);
      entries.forEach(function (e) { T.newest = e.line; });
      T.nextBefore = data.next_before == null ? null : data.next_before;
      T.hasMore = !!data.has_more;
      setOlderButtons();
      showEmpty();
      showReply();
      $('v2s-jump').hidden = !jumpLine;
      if (jumpLine) {
        var target = listEl.querySelector('[data-lines~="' + jumpLine + '"]');
        if (target) { target.scrollIntoView({ block: 'center' }); flash(target, 'v2s-hl-jump'); }
      } else {
        toBottom();
      }
    }).catch(function () { T.loading = false; });
  }

  function loadOlder(untilUser) {
    if (T.loading || !T.hasMore || T.nextBefore == null) { return Promise.resolve(); }
    T.loading = true;
    var extra = { before: T.nextBefore };
    if (untilUser) { extra.until_user = 1; }

    var myGen = gen;

    return getJson(historyUrl(extra)).then(function (data) {
      if (myGen !== gen) { return; }
      T.loading = false;
      if (!data || !data.ok) { toast((data && data.message) || 'Could not load older messages', true); return; }
      var frag = document.createDocumentFragment();
      var older = renderEntries(data.entries || [], newTailState(), frag);
      var before = scroller.scrollHeight;
      listEl.insertBefore(frag, listEl.firstChild);
      scroller.scrollTop += scroller.scrollHeight - before;
      older.forEach(function (n) { flash(n, 'v2s-hl-jump'); });
      T.nextBefore = data.next_before == null ? null : data.next_before;
      T.hasMore = !!data.has_more;
      setOlderButtons();
    }).catch(function () { T.loading = false; toast('Network error', true); });
  }

  // Pending (optimistic) entries ---------------------------------------
  function addPending(text, pairs) {
    var el = renderEntry({ role: 'user', blocks: [{ kind: 'text', text: text }], line: 0, timestamp: null });
    if (pairs) { var body = el.querySelector('[data-line]'); if (body) { body.innerHTML = answerRows(pairs); } }
    el.classList.add('v2s-pending');
    el.removeAttribute('data-lines');
    el.querySelector('.v2s-meta').innerHTML = '<span class="pending-note">Sending…</span>';
    listEl.appendChild(el);
    var item = { el: el, text: text.trim(), at: Date.now(), pairs: pairs || null };
    pending.push(item);
    showEmpty();
    toBottom();

    return item;
  }
  function removePending(item) {
    if (!item) { return; }
    if (item.el.parentNode) { item.el.parentNode.removeChild(item.el); }
    pending = pending.filter(function (p) { return p !== item; });
  }
  function reconcilePending(fresh) {
    if (!pending.length) { return; }
    var texts = [];
    fresh.forEach(function (e) {
      if (entryKind(e) !== 'user') { return; }
      (e.blocks || []).forEach(function (b) { if (b.kind === 'text') { texts.push(String(b.text).trim()); } });
    });
    if (!texts.length) { return; }
    pending.slice().forEach(function (p) {
      var matched = texts.some(function (t) { return t === p.text || t.indexOf(p.text) !== -1 || p.text.indexOf(t) !== -1; });
      if (matched || Date.now() - p.at > 20000) { removePending(p); }
    });
  }

  // Live tail ------------------------------------------------------------
  function pollHistory(wasNear) {
    var extra = { limit: 50 };
    if (T.newest !== null) { extra.after = T.newest; }
    var myGen = gen;

    return getJson(historyUrl(extra)).then(function (data) {
      if (myGen !== gen || !data || !data.ok) { return; }
      var fresh = (data.entries || []).filter(function (e) { return T.newest === null || e.line > T.newest; });
      if (!fresh.length) { return; }
      reconcilePending(fresh);
      var frag = document.createDocumentFragment();
      var touched = renderEntries(fresh, tail, frag);
      fresh.forEach(function (e) { T.newest = e.line; });
      listEl.appendChild(frag);
      touched.forEach(function (n) { flash(n, 'v2s-hl-new'); });
      showEmpty();
      showReply();
      if (wasNear) { toBottom(); } else { $('v2s-newdot').hidden = false; }
    }).catch(function () {});
  }

  // ======================================================================
  // blocked prompts (single, free-text, multi-question; also an in-transcript question block)
  // ======================================================================

  function optionButtons(options, session) {
    var html = '<div class="v2s-opts" data-prompt-options>';
    var hasFree = false;
    options.forEach(function (o) {
      var label = String(o.label);
      if (label.toLowerCase().indexOf('type something') !== -1) {
        hasFree = true;
        html += '<button type="button" class="v2-btn v2-btn-quiet" data-act="reveal-free" data-option="' + o.number + '">' + o.number + '. ' + esc(label) + '</button>';
      } else {
        html += '<button type="button" class="v2-btn v2-btn-warn" data-act="answer" data-option="' + o.number + '" data-label="' + esc(label) + '"><span class="v2s-optbox"><span>' + o.number + '. ' + esc(label) + '</span>'
          + (o.description ? '<small>' + esc(o.description) + '</small>' : '') + '</span></button>';
      }
    });
    html += '</div>';
    if (hasFree) {
      html += '<div class="v2s-free" hidden><textarea rows="3" placeholder="Type your reply" aria-label="Free-text reply"></textarea>'
        + '<button type="button" class="v2-btn v2-btn-primary v2s-qinline" data-act="send-free">Send reply</button></div>';
    }

    return html;
  }

  // A typed answer next to a multi-select question's checkboxes is only wired for headless Claude; the
  // terminal (tmux) key driver cannot combine the two, and OpenCode has its own answer shape.
  function customOnMulti() {
    return detail.agent === 'claude' && detail.runtime === 'headless';
  }

  function optionLabel(o) {
    return '<span class="v2s-optbox"><span class="v2s-lab">' + esc(o.label || '') + '</span>'
      + (o.description ? '<small>' + esc(o.description) + '</small>' : '') + '</span>';
  }

  function multiForm(questions) {
    var html = '<div class="v2s-multi" data-multi>';
    questions.forEach(function (q, qi) {
      var opts = q.options || [];
      var multi = q.multiSelect === true || q.multiple === true;
      var allowCustom = q.custom !== false && (!multi || customOnMulti());
      html += '<div class="v2s-q" data-q="' + qi + '" data-multi-select="' + (multi ? 1 : 0) + '">'
        + (q.header ? '<span class="v2s-label">' + esc(q.header) + '</span>' : '') + '<p>' + esc(q.question || '') + '</p>';
      opts.forEach(function (o, oi) {
        html += '<label class="v2-check"><input type="' + (multi ? 'checkbox' : 'radio') + '" name="q' + qi + '" value="' + (oi + 1) + '">' + optionLabel(o) + '</label>';
      });
      if (allowCustom) {
        html += '<label class="v2-check"><input type="' + (multi ? 'checkbox' : 'radio') + '" name="q' + qi + '" value="' + (opts.length + 1) + '" data-free="1">'
          + '<span class="v2s-optbox"><span class="v2s-lab">Type something…</span></span></label>'
          + '<textarea rows="2" placeholder="Your answer" aria-label="Your answer to: ' + esc(q.question || '') + '" hidden></textarea>';
      }
      html += '</div>';
    });
    html += '<button type="button" class="v2-btn v2-btn-primary" data-act="send-multi">Send answers</button></div>';

    return html;
  }

  function questionBlock(block, line) {
    var questions = Array.isArray(block.questions) ? block.questions : [];
    var isMulti = questions.length > 1 || (questions[0] && (questions[0].multiSelect === true || questions[0].multiple === true));
    var title = block.question || block.text || 'Waiting on input';
    var html = '<div class="v2s-qblock" data-line="' + line + '">';
    if (block.header) { html += '<span class="v2s-label">' + esc(block.header) + '</span>'; }
    if (!isMulti) { html += '<p class="v2s-desc">' + esc(title) + '</p>'; }
    if (block.pending && isMulti) { html += multiForm(questions); }
    else if (block.pending && block.options && block.options.length) { html += optionButtons(block.options); }
    else if (block.options && block.options.length) {
      html += '<ul class="v2s-answered">' + block.options.map(function (o) { return '<li>' + o.number + '. ' + esc(o.label) + '</li>'; }).join('') + '</ul>';
    }
    var rows = block.answer ? (parseAnswered(block.answer_output) || (questions.length === 1 ? [{ q: questions[0].question || title, a: block.answer }] : null)) : null;
    if (rows) { html += answerRows(rows); }
    else if (block.answer) { html += '<p class="v2s-answered">Answered: ' + esc(block.answer) + '</p>'; }
    else if (block.pending) { html += '<p class="v2s-answered">Awaiting your answer</p>'; }

    return html + '</div>';
  }

  var lastPromptKey = null;
  var answerPending = false;

  // The agent's own words before the question, when the transcript has them; filled by showReply().
  var REPLY_SLOT = '<details class="v2s-reply" id="v2s-prompt-reply" hidden><summary>What the agent said before asking</summary><div class="v2s-text"></div></details>';

  function promptHtml(d) {
    var html = REPLY_SLOT;
    if (d.prompt_external) {
      return html + '<p class="v2-small">Open Codex Remote to answer this prompt and continue.</p>';
    }
    // A question form already shows its questions; repeating the first one as a heading only confuses (several questions).
    if (d.prompt_questions && d.prompt_questions.length) {
      return html + multiForm(d.prompt_questions);
    }
    if (d.blocked_reason) { html += '<h3>' + esc(d.blocked_reason) + '</h3>'; }
    if (d.prompt_context) { html += codeBlock(d.prompt_context, d.prompt_tool_name ? 'Awaiting approval: ' + d.prompt_tool_name : 'Awaiting approval'); }
    if (d.prompt_is_folder_trust) { html += '<p class="v2-small">This is a folder-trust prompt: trusting lets the agent run code from this folder.</p>'; }
    if (d.prompt_options && d.prompt_options.length) { html += optionButtons(withDescriptions(d.prompt_options, d.prompt_tool_input)); }

    return html;
  }

  // A one-question AskUserQuestion on a terminal session arrives as numbered options; the explanation
  // of each choice only exists in the tool input.
  function withDescriptions(options, toolInput) {
    var first = toolInput && Array.isArray(toolInput.questions) ? toolInput.questions[0] : null;
    var known = first && Array.isArray(first.options) ? first.options : [];

    return options.map(function (o) {
      var match = known.filter(function (k) { return k && k.label === o.label && k.description; })[0];
      return match ? { number: o.number, label: o.label, description: match.description } : o;
    });
  }

  // The latest assistant text of the current turn (nothing after it from the user), from what is on screen.
  function precedingReply() {
    var kids = listEl.children;
    for (var i = kids.length - 1; i >= 0; i--) {
      if (kids[i].classList.contains('v2s-pending')) { continue; }
      var kind = kids[i].getAttribute('data-msg');
      if (kind === 'user') { return null; }
      if (kind === 'assistant') {
        var copy = kids[i].querySelector('[data-act="copy"]');
        return copy ? raws[parseInt(copy.getAttribute('data-raw'), 10)] : null;
      }
    }

    return null;
  }

  function showReply() {
    var slot = $('v2s-prompt-reply');
    if (!slot) { return; }
    var text = precedingReply();
    if (text === (slot._text || null)) { return; }
    slot._text = text;
    slot.hidden = !text;
    slot.open = !!text;
    slot.querySelector('.v2s-text').innerHTML = text ? renderMarkdown(text) : '';
  }

  function renderPrompt(d) {
    var key = JSON.stringify([d.blocked_reason || null, d.prompt_context || null, d.prompt_options || null, d.prompt_questions || null, d.prompt_external || null, d.prompt_tool_input || null]);
    // A poll that changes nothing must not rebuild: it would drop a half-typed reply and the scroll position.
    if (key === lastPromptKey) { return false; }
    lastPromptKey = key;
    answerPending = false;
    if (!d.blocked_reason) { promptEl.innerHTML = ''; return true; }
    promptEl.innerHTML = '<div class="v2s-prompt" id="v2s-prompt-card">' + promptHtml(d) + '</div>';
    showReply();

    return true;
  }

  function markPending(card, on) {
    answerPending = on;
    if (card) { card.classList.toggle('is-pending', on); }
  }

  function afterAnswer(res, card, optimistic, okFallback) {
    if (res && res.ok) {
      if (optimistic && optimistic.pairs) { rememberAnswers(optimistic.pairs); }
      renderThinking({ working: false, blocked_reason: null }, true);
      // The request only waits for the keys to be sent; the agent needs a moment to move past the prompt.
      setTimeout(pollNow, 300);
      return;
    }
    markPending(card, false);
    removePending(optimistic);
    toast((res && res.message) || okFallback, true);
    pollNow();
  }

  function confirmAnswer(label) {
    return !prefs.confirm || window.confirm('Send "' + label + '" to this session?');
  }

  // A one-question AskUserQuestion on screen: its question and the answer as rows (null for a permission prompt).
  function singleQuestionPairs(answer) {
    var input = detail.prompt_tool_name === 'AskUserQuestion' ? detail.prompt_tool_input : null;
    var q = input && Array.isArray(input.questions) && input.questions.length === 1 ? input.questions[0].question : null;
    return q ? [{ q: q, a: answer }] : null;
  }

  function answerPlain(btn) {
    var label = btn.getAttribute('data-label');
    if (!confirmAnswer(label)) { return; }
    var card = closest(btn, '.v2s-prompt, .v2s-qblock');
    markPending(card, true);
    var opt = addPending(label, singleQuestionPairs(label));
    post('/answer_prompt.php', { session: session, option: btn.getAttribute('data-option'), expected_label: label })
      .then(function (res) { afterAnswer(res, card, opt, 'Failed to send answer'); })
      .catch(function () { afterAnswer(null, card, opt, 'Network error: answer not sent'); });
  }

  function answerFree(btn) {
    var wrap = closest(btn, '.v2s-free');
    var ta = wrap.querySelector('textarea');
    var text = ta.value.trim();
    if (!text) { ta.focus(); return; }
    var scope = closest(btn, '.v2s-prompt, .v2s-qblock');
    var reveal = scope.querySelector('[data-act="reveal-free"]');
    var card = scope;
    markPending(card, true);
    var opt = addPending(text, singleQuestionPairs(text));
    post('/answer_prompt.php', { session: session, option: reveal ? reveal.getAttribute('data-option') : '0', text: text })
      .then(function (res) { afterAnswer(res, card, opt, 'Failed to send reply'); })
      .catch(function () { afterAnswer(null, card, opt, 'Network error: reply not sent'); });
  }

  // Mirrors the classic collectMultiQuestionAnswers(): null means a question was left unanswered.
  function labelOf(input) {
    return closest(input, 'label').querySelector('.v2s-lab').textContent;
  }

  // Mirrors the classic collectMultiQuestionAnswers(): null means a question was left unanswered.
  // A raw newline in a typed answer would be read as an early Enter by the terminal UI, so it never reaches the wire.
  function collectMulti(wrap) {
    var answers = [];
    var parts = [];
    var pairs = [];
    var qs2 = wrap.querySelectorAll('[data-q]');
    for (var i = 0; i < qs2.length; i++) {
      var q = qs2[i];
      var label = q.querySelector('p').textContent;
      var multi = q.getAttribute('data-multi-select') === '1';
      var chosen = Array.prototype.slice.call(q.querySelectorAll('input:checked'));
      var free = chosen.filter(function (c) { return c.getAttribute('data-free') === '1'; })[0];
      var real = chosen.filter(function (c) { return c !== free; });
      var typed = free ? q.querySelector('textarea').value.replace(/\r?\n/g, ' ').trim() : '';

      if (free && !typed) { return null; }

      if (multi) {
        if (!real.length && !free) { return null; }
        var picks = real.map(function (c) { return parseInt(c.value, 10); });
        if (free) { picks.push({ text: typed }); }
        answers.push(picks);
        parts.push(label + ': ' + real.map(labelOf).concat(free ? [typed] : []).join(', '));
        pairs.push({ q: label, a: real.map(labelOf).concat(free ? [typed] : []).join(', ') });
      } else if (free) {
        answers.push({ text: typed });
        parts.push(label + ': ' + typed);
        pairs.push({ q: label, a: typed });
      } else if (real.length) {
        answers.push(parseInt(real[0].value, 10));
        parts.push(label + ': ' + labelOf(real[0]));
        pairs.push({ q: label, a: labelOf(real[0]) });
      } else {
        return null;
      }
    }

    return { answers: answers, summary: parts.join('\n'), pairs: pairs };
  }

  function answerMulti(btn) {
    var wrap = closest(btn, '[data-multi]');
    var collected = collectMulti(wrap);
    if (collected === null) { toast('Please answer every question before sending.', true); return; }
    var card = closest(btn, '.v2s-prompt, .v2s-qblock');
    markPending(card, true);
    var opt = addPending(collected.summary, collected.pairs);
    post('/answer_multi_question.php', { session: session, answers: JSON.stringify(collected.answers) })
      .then(function (res) { afterAnswer(res, card, opt, 'Failed to send answers'); })
      .catch(function () { afterAnswer(null, card, opt, 'Network error: answers not sent'); });
  }

  // ======================================================================
  // header, working indicator, error, footer state
  // ======================================================================

  function statusOf(d) { return d.blocked_reason ? 'blocked' : (d.working ? 'working' : 'idle'); }

  function renderHeader(d) {
    var title = d.title || d.name || session;
    $('v2s-title').textContent = title;
    document.title = (d.blocked_reason ? '(!) ' : '') + title;
    var status = statusOf(d);
    var bits = ['<i class="v2-dot v2-dot-' + status + '" role="img" aria-label="' + (status === 'blocked' ? 'needs input' : status) + '"></i>'];
    bits.push('<span>' + (status === 'blocked' ? 'needs input' : status) + '</span>');
    if (d.agent && d.agent !== 'claude') { bits.push('<span class="v2-chip v2-agent-' + esc(d.agent) + '">' + esc(d.agent_label || d.agent) + '</span>'); }
    if (d.agent === 'claude' && d.profile) { bits.push('<span class="v2-chip v2-chip-accent">' + esc(d.profile) + '</span>'); }
    if (d.runtime === 'headless') { bits.push('<span class="v2-chip">Headless</span>'); }
    if (d.git_branch) { bits.push('<span>' + esc(d.git_branch) + '</span>'); }
    var pct = d.context_used_percentage;
    if ((d.agent === 'claude' || d.agent === 'codex') && typeof pct === 'number' && isFinite(pct)) {
      bits.push('<span' + (pct >= 80 ? ' class="v2-warn"' : '') + '>ctx ' + Math.round(pct) + '%</span>');
    }
    $('v2s-sub').innerHTML = bits.join('');
  }

  var lastThinking = null;
  function renderThinking(d, optimistic) {
    var show = (!!d.working || !!optimistic) && !d.blocked_reason;
    if (show === lastThinking) { return; }
    lastThinking = show;
    thinkingEl.innerHTML = show
      ? '<div class="v2s-working"><span class="v2-row"><span class="v2-thinking"><i></i><i></i><i></i></span>Working…</span></div>'
      : '';
    stopBtn.hidden = !show || d.agent === 'ollama' ? true : false;
    if (show && nearBottom()) { toBottom(); }
  }

  var lastError = null;
  function renderError(d) {
    var text = d.last_turn_error || null;
    if (text === lastError) { return; }
    lastError = text;
    errorEl.innerHTML = text ? '<div class="v2s-error"><b>' + esc(d.agent_label || 'The agent') + ' reported a problem</b><br>' + esc(text) + '</div>' : '';
  }

  function renderFooter(d) {
    var blocked = !!d.blocked_reason;
    var readOnly = d.writable === false;
    var note = $('v2s-compose-note');
    $('v2s-compose').hidden = blocked || readOnly;
    note.hidden = !(blocked || readOnly);
    note.textContent = readOnly ? (d.read_only_reason || 'This session is currently read-only.')
      : (d.prompt_external ? 'Open Codex Remote to answer this prompt and continue.' : 'Answer the prompt above to continue.');
    stopBtn.hidden = !(d.working && !blocked) || d.agent === 'ollama';
    sendBtn.textContent = d.working ? 'Queue' : 'Send';
    V2S.api.controlsSummary(d);
    $('v2s-bottom').classList.toggle('v2-btn-warn', blocked);
    sizeFooter();
  }

  function sizeFooter() {
    var h = $('v2s-footer').offsetHeight;
    document.documentElement.style.setProperty('--footer-h', h + 'px');
  }

  function render(d) {
    detail = d;
    renderHeader(d);
    renderThinking(d, false);
    renderError(d);
    var near = nearBottom();
    var changed = renderPrompt(d);
    renderFooter(d);
    V2S.api.onDetail(d);
    if (changed && near) { toBottom(); }
  }

  // ======================================================================
  // compose: send / queue / stop / attach
  // ======================================================================

  var attachments = [];
  var DRAFT_KEY = 'v2s-draft:' + session;

  function grow() {
    textEl.style.height = 'auto';
    textEl.style.height = Math.min(textEl.scrollHeight + 2, 144) + 'px';
    sizeFooter();
  }
  function updateSend() {
    sendBtn.disabled = textEl.value.trim() === '' && attachments.length === 0;
  }
  function setStatus(text) {
    var el = $('v2s-compose-status');
    el.textContent = text || '';
    el.hidden = !text;
    sizeFooter();
  }
  function renderAttached() {
    var el = $('v2s-attached');
    el.hidden = attachments.length === 0;
    el.innerHTML = attachments.map(function (a, i) {
      return '<span class="v2s-chip-x"><span>' + esc(a.filename) + '</span><button type="button" data-act="unattach" data-i="' + i + '" aria-label="Remove ' + esc(a.filename) + '">&times;</button></span>';
    }).join('');
    sizeFooter();
  }

  function sendMessage() {
    var text = textEl.value;
    if (text.trim() === '' && !attachments.length) { return; }
    textEl.disabled = true;
    sendBtn.disabled = true;
    setStatus('');
    // The same "[Attached: path]" lines the host agent adds, so the optimistic bubble matches the real entry.
    var lines = attachments.map(function (a) { return '[Attached: ' + a.path + ']'; });
    var shown = lines.length === 0 ? text : (text ? text.replace(/\s*$/, '') + '\n' : '') + lines.join('\n');
    var item = addPending(shown);
    post('/session_send.php', { session: session, message: text, attachments: attachments.map(function (a) { return a.path; }) })
      .then(function (res) {
        if (res && res.ok) {
          if (res.message === 'Message queued') { item.el.querySelector('.pending-note').textContent = 'Queued'; }
          textEl.value = '';
          attachments = [];
          drop(DRAFT_KEY);
          renderAttached();
          grow();
          pollNow();
        } else {
          removePending(item);
          setStatus((res && res.message) || 'Failed to send message.');
        }
      })
      .catch(function () { removePending(item); setStatus('Network error: message not sent.'); })
      .then(function () { textEl.disabled = false; updateSend(); });
  }

  function stopTurn() {
    stopBtn.disabled = true;
    post('/session_escape.php', { session: session }).then(function (res) {
      stopBtn.disabled = false;
      toast(res.ok ? 'Interrupted' : (res.message || 'Could not interrupt'), !res.ok);
      setTimeout(pollNow, 400);
    }).catch(function () { stopBtn.disabled = false; toast('Network error', true); });
  }

  function uploadFile(file) {
    var fd = new FormData();
    fd.append('session', session);
    fd.append('csrf_token', csrf);
    fd.append('file', file);

    return fetch('/upload_file.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(parse).then(function (res) {
      if (res && res.ok) { attachments.push({ path: res.path, filename: res.filename, size: res.size }); return true; }
      setStatus('Failed to upload ' + file.name + ': ' + ((res && res.message) || 'unknown error'));
      return false;
    }).catch(function () { setStatus('Network error: ' + file.name + ' not uploaded.'); return false; });
  }

  function onFiles(files) {
    var list = Array.prototype.slice.call(files);
    setStatus('Uploading…');
    var chain = Promise.resolve();
    list.forEach(function (f) { chain = chain.then(function () { return uploadFile(f); }); });
    chain.then(function () {
      if (attachments.length) { setStatus(''); }
      renderAttached(); updateSend();
      V2S.api.refreshFiles();
    });
  }

  // ======================================================================
  // scroll navigation: one message up / down ignoring tool calls
  // ======================================================================

  function messages() {
    return Array.prototype.slice.call(listEl.querySelectorAll('[data-msg]'));
  }
  function relTop(el) {
    return el.getBoundingClientRect().top - scroller.getBoundingClientRect().top;
  }
  function goPrev() {
    var msgs = messages();
    for (var i = msgs.length - 1; i >= 0; i--) {
      var r = relTop(msgs[i]);
      if (r < -8) { scroller.scrollTop += r - 8; return; }
    }
    if (scroller.scrollTop > 0) { scroller.scrollTop = 0; }
    if (T.hasMore) { toast('Loading older…'); loadOlder(false); }
  }
  function goNext() {
    var msgs = messages();
    for (var i = 0; i < msgs.length; i++) {
      var r = relTop(msgs[i]);
      if (r > 8) { scroller.scrollTop += r - 8; return; }
    }
    toBottom();
  }

  // ======================================================================
  // polling
  // ======================================================================

  var timer = null;
  var running = false;
  var queuedAgain = false;
  var abort = typeof AbortController === 'function' ? new AbortController() : null;

  function pollDetail() {
    return getJson('/session_detail.php?' + qs({ session: session }), abort ? abort.signal : undefined).then(function (d) {
      if (!d || !d.ok) { return; }
      if (typeof d.agent_session_id === 'string' && d.agent_session_id !== '') {
        // /clear, /compact and --resume rotate the transcript file: start over from its first page.
        if (T.agentSessionId !== null && d.agent_session_id !== T.agentSessionId) {
          T.agentSessionId = d.agent_session_id;
          resetTranscript();
          loadFirstPage(null);
        }
        T.agentSessionId = d.agent_session_id;
      }
      render(d);
    }).catch(function () {});
  }

  function pollNow() {
    if (running) { queuedAgain = true; return Promise.resolve(); }
    running = true;
    var near = nearBottom();

    return Promise.all([pollDetail(), T.newest === null && T.loading ? Promise.resolve() : pollHistory(near)]).then(function () {
      running = false;
      if (queuedAgain) { queuedAgain = false; pollNow(); }
    });
  }

  // Other sessions waiting on an answer: a badge on the way back to the list (the classic sidebar's dot).
  var OTHERS_EVERY_MS = 15000;
  function pollOthers() {
    if (document.hidden) { return; }
    getJson('/sessions_list.php').then(function (data) {
      if (!data || !data.ok) { return; }
      var n = (data.sessions || []).filter(function (x) { return x.name !== session && (x.status === 'blocked' || !!x.blocked_reason); }).length;
      var badge = $('v2s-back-badge');
      badge.hidden = n === 0;
      badge.textContent = n;
      $('v2s-back-badge').parentNode.setAttribute('aria-label', n ? 'All sessions (' + n + ' need input)' : 'All sessions');
    }).catch(function () {});
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      (document.hidden ? Promise.resolve() : pollNow()).then(schedule);
    }, prefs.pollMs);
  }

  // ======================================================================
  // events
  // ======================================================================

  document.addEventListener('click', function (e) {
    var el = closest(e.target, '[data-act]');
    var opener = closest(e.target, '[data-open]');
    if (opener) { V2S.api.openSheet(opener.getAttribute('data-open')); return; }
    if (closest(e.target, '[data-close]')) { closest(e.target, 'dialog').close(); return; }
    if (!el) { return; }
    var act = el.getAttribute('data-act');
    var raw = el.getAttribute('data-raw');

    if (act === 'copy') { copyText(raws[parseInt(raw, 10)] || ''); }
    else if (act === 'fullscreen') { V2S.api.fullscreen(el.getAttribute('data-title'), raws[parseInt(raw, 10)] || ''); }
    else if (act === 'image') { V2S.api.viewImage(el.getAttribute('data-full') || el.src); }
    else if (act === 'answer') { answerPlain(el); }
    else if (act === 'reveal-free') {
      var free = closest(el, '.v2s-prompt, .v2s-qblock').querySelector('.v2s-free');
      free.hidden = !free.hidden;
      if (!free.hidden) { free.querySelector('textarea').focus(); }
    }
    else if (act === 'send-free') { answerFree(el); }
    else if (act === 'send-multi') { answerMulti(el); }
    else if (act === 'unattach') {
      var removed = attachments.splice(parseInt(el.getAttribute('data-i'), 10), 1)[0];
      renderAttached(); updateSend();
      // The file was uploaded for this message only; leaving it behind would orphan it in the project.
      if (removed) { post('/delete_uploaded_file.php', { session: session, filename: removed.filename }).then(V2S.api.refreshFiles); }
    }
  });

  // A question's "Type something" choice reveals its own textarea (radio or checkbox).
  document.addEventListener('change', function (e) {
    var input = closest(e.target, '.v2s-q input');
    if (!input) { return; }
    var ta = closest(input, '.v2s-q').querySelector('textarea');
    if (!ta) { return; }
    var free = closest(input, '.v2s-q').querySelector('input[data-free]');
    ta.hidden = !free.checked;
    if (!ta.hidden) { ta.focus(); }
  });

  $('v2s-load-older').addEventListener('click', function () { loadOlder(false); });
  $('v2s-load-until-user').addEventListener('click', function () { loadOlder(true); });
  $('v2s-prev').addEventListener('click', goPrev);
  $('v2s-next').addEventListener('click', goNext);
  $('v2s-bottom').addEventListener('click', toBottom);
  scroller.addEventListener('scroll', function () { if (nearBottom()) { $('v2s-newdot').hidden = true; } }, { passive: true });

  sendBtn.addEventListener('click', sendMessage);
  stopBtn.addEventListener('click', stopTurn);
  textEl.addEventListener('input', function () {
    grow(); updateSend();
    if (textEl.value) { save(DRAFT_KEY, textEl.value); } else { drop(DRAFT_KEY); }
  });
  // Plain Enter is a newline (pasted logs are common); Shift+Enter sends, like the classic page.
  textEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.shiftKey) { e.preventDefault(); sendMessage(); }
  });
  $('v2s-attach-btn').addEventListener('click', function () { $('v2s-file').click(); });
  $('v2s-file').addEventListener('change', function (e) {
    if (e.target.files && e.target.files.length) { onFiles(e.target.files); }
    e.target.value = '';
  });

  document.addEventListener('visibilitychange', function () { if (!document.hidden) { pollNow(); } });
  window.addEventListener('pagehide', function () { if (abort) { abort.abort(); } });

  // iOS keeps the layout viewport tall while the keyboard is open; follow the visual one so the footer stays visible.
  if (window.visualViewport) {
    var fit = function () {
      var vv = window.visualViewport;
      // A pinch-zoom also shrinks the visual viewport; the shell must not chase that.
      var h = Math.abs(vv.scale - 1) < 0.02 ? vv.height : window.innerHeight;
      shell.style.setProperty('--vvh', Math.round(h) + 'px');
      // iOS scrolls the layout viewport under the keyboard; a fixed shell must follow that offset or it slides off screen.
      shell.style.setProperty('--vvtop', (Math.abs(vv.scale - 1) < 0.02 ? Math.round(vv.offsetTop) : 0) + 'px');
      if (nearBottom()) { toBottom(); }
    };
    window.visualViewport.addEventListener('resize', fit);
    window.visualViewport.addEventListener('scroll', fit);
    window.addEventListener('scroll', function () { if (window.scrollY) { window.scrollTo(0, 0); } });
  }
  window.addEventListener('resize', sizeFooter);

  // Horizontal swipes anywhere except where the finger can be scrolling something: a swipe right goes back in
  // history (the dashboard when there is none), a swipe left opens the sessions popup. Touches starting in the
  // outer strip are left to the browser's own edge gestures (back / forward).
  var BROWSER_EDGE_PX = 24, SWIPE_PX = 80, swipe = null;
  function overlayOpen() {
    return !!document.querySelector('dialog[open], .v2s-viewer:not([hidden]), .v2s-fullscreen:not([hidden])');
  }
  // True when `el` or an ancestor can scroll sideways (a wide code block), or is a field the finger may select in.
  function scrollsSideways(el) {
    for (; el && el.nodeType === 1 && el !== document.body; el = el.parentNode) {
      if (el.matches('input, textarea, select, pre, code')) { return true; }
      if (el.scrollWidth > el.clientWidth + 1) {
        var ox = window.getComputedStyle(el).overflowX;
        if (ox === 'auto' || ox === 'scroll') { return true; }
      }
    }
    return false;
  }
  document.addEventListener('touchstart', function (e) {
    var t = e.touches[0];
    swipe = null;
    if (e.touches.length !== 1 || overlayOpen()) { return; }
    if (t.clientX <= BROWSER_EDGE_PX || t.clientX >= window.innerWidth - BROWSER_EDGE_PX || scrollsSideways(e.target)) { return; }
    swipe = { x: t.clientX, y: t.clientY };
  }, { passive: true });
  document.addEventListener('touchend', function (e) {
    var s = swipe;
    swipe = null;
    if (!s || !e.changedTouches.length) { return; }
    var dx = e.changedTouches[0].clientX - s.x;
    var dy = e.changedTouches[0].clientY - s.y;
    if (Math.abs(dx) < SWIPE_PX || Math.abs(dy) > Math.abs(dx) / 2) { return; }
    if (dx > 0) { if (window.history.length > 1) { window.history.back(); } else { window.location.href = '/v2'; } }
    else { V2S.api.openSheet('v2s-sessions'); }
  }, { passive: true });

  // ======================================================================
  // public API for v2-session-sheets.js
  // ======================================================================

  window.V2S.api = {
    session: session, csrf: function () { return csrf; }, esc: esc, toast: toast, post: post, getJson: getJson, qs: qs,
    copy: copyText, fileSize: fileSize, relTime: relTime, save: save, load: load, prefs: prefs,
    detail: function () { return detail; }, parseAnswered: parseAnswered, answerRows: answerRows, rememberAnswers: rememberAnswers,
    pollNow: pollNow, schedule: schedule,
    jumpTo: function (line) { resetTranscript(); return loadFirstPage(line); },
    reload: function () { resetTranscript(); return loadFirstPage(null); },
    // replaced by the sheets file
    controlsSummary: function () {}, onDetail: function () {}, refreshFiles: function () {},
    openSheet: function () {}, fullscreen: function () {}, viewImage: function () {}
  };

  // ---- start ----
  try {
    var notice = sessionStorage.getItem('v2-notice');
    if (notice) { sessionStorage.removeItem('v2-notice'); toast(notice, true); }
  } catch (e) {}
  textEl.value = load(DRAFT_KEY, '');
  document.body.classList.toggle('v2s-hide-reasoning', !prefs.reasoning);
  document.body.classList.toggle('v2s-hide-subagent', !prefs.subagent);
  render(detail);
  grow(); updateSend(); sizeFooter();
  loadFirstPage(T.jump).then(function () { pollNow(); schedule(); });
  pollOthers();
  setInterval(pollOthers, OTHERS_EVERY_MS);
}());
