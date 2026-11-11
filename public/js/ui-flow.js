// The session-flow band: every button is one tap on the decision the host already stored. Mounts on any
// [data-flow-host]; plain ES5.
(function () {
  function post(host, params) {
    var body = new URLSearchParams();
    body.append('csrf_token', host.getAttribute('data-csrf') || '');
    Object.keys(params).forEach(function (key) {
      var value = params[key];
      if (value !== null && value !== undefined && value !== '') { body.append(key, value); }
    });
    return fetch('/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (res) {
      return res.json().catch(function () { return { ok: false, message: 'Unexpected response (' + res.status + ')' }; });
    });
  }

  // A request in flight disables this band's buttons only, and marks the host so the dashboard's poll
  // keeps the band it would otherwise swap away underneath the tap.
  function busy(host, on) {
    Array.prototype.forEach.call(host.querySelectorAll('[data-flow-act]'), function (button) { button.disabled = on; });
    if (on) { host.setAttribute('data-busy', '1'); } else { host.removeAttribute('data-busy'); }
  }

  // The dashboard's poll leaves a failed band in place so the message can be read; it clears itself so the
  // card list starts swapping again instead of staying frozen for the life of the page.
  function showError(host, message) {
    var el = host.querySelector('[data-flow-error]');
    if (!el) {
      el = document.createElement('p');
      el.className = 'ui-small ui-warn ui-flow-error';
      el.setAttribute('data-flow-error', '');
      el.setAttribute('role', 'alert');
      host.appendChild(el);
    }
    el.textContent = message || 'Network error';
    if (el.removeTimer) { window.clearTimeout(el.removeTimer); }
    el.removeTimer = window.setTimeout(function () {
      if (el.parentNode) { el.parentNode.removeChild(el); }
    }, 10000);
  }

  function showWhy(host, why) {
    var el = host.querySelector('.ui-flow-why');
    if (!el) {
      el = document.createElement('p');
      el.className = 'ui-flow-why';
      host.insertBefore(el, host.firstChild);
    }
    el.textContent = why;
  }

  // A dismissed offer is gone for every band of that session (the session page carries two).
  function dismissAll(session) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-flow-host]'), function (el) {
      if (el.getAttribute('data-session') === session && el.parentNode) { el.parentNode.removeChild(el); }
    });
  }

  // Edit in form: the same sessionStorage prefill the dashboard's New Session sheet reads once on boot.
  function openForm(host) {
    var prefill = {};
    var agent = host.getAttribute('data-receiver-agent') || host.getAttribute('data-agent') || '';
    var profile = host.getAttribute('data-receiver-profile') || host.getAttribute('data-profile') || '';
    var workdir = host.getAttribute('data-workdir') || '';
    var prompt = host.getAttribute('data-prompt') || '';
    if (agent) { prefill.agent = agent; }
    if (profile && agent === 'claude') { prefill.profile = profile; }
    if (workdir) { prefill.workdir = workdir; }
    if (prompt) { prefill.prompt = prompt; }
    try { window.sessionStorage.setItem('ui-new-prefill', JSON.stringify(prefill)); } catch (e) {
      showError(host, 'Could not open the form');
      return;
    }
    window.location.href = '/';
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-flow-act]') : null;
    if (!button) { return; }
    var host = button.closest('[data-flow-host]');
    if (!host) { return; }
    var act = button.getAttribute('data-flow-act');
    var session = host.getAttribute('data-session') || '';
    if (act === 'edit') { openForm(host); return; }
    var params = null;
    if (act === 'start') {
      params = { action: 'flow_rotate', session: session, handoff_id: host.getAttribute('data-handoff-id') || '' };
    } else if (act === 'handoff' || act === 'takeover') {
      params = { action: 'flow_rotate_request', session: session };
    } else if (act === 'judge') {
      params = { action: 'flow_judge', session: session };
    } else if (act === 'continue' || act === 'wait') {
      params = { action: 'flow_offer_dismiss', session: session };
    }
    if (!params) { return; }
    busy(host, true);
    post(host, params).then(function (res) {
      if (!res || !res.ok) { busy(host, false); showError(host, res && res.message); return; }
      if (res.redirect) { window.location.href = res.redirect; return; }
      if (act === 'judge' && res.flow && res.flow.why) { busy(host, false); showWhy(host, res.flow.why); return; }
      if (act === 'continue' || act === 'wait') { dismissAll(session); return; }
      if (host.parentNode) { host.parentNode.removeChild(host); }
    }).catch(function () { busy(host, false); showError(host, null); });
  });
})();
