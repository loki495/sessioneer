/* Voice input: tap a mic button, speak a whole message, then review it (read back, edit) and send or retry.
   The browser's speech recognition transcribes. Recognition on iOS ends on its own after a pause, so while
   the person has not tapped Stop it is restarted and the pieces are joined. Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  var READBACK_KEY = 'v2-voice-readback';
  var MAX_RESTARTS = 60;
  var RESTART_DELAY_MS = 150;

  var attached = [];
  var dlg = null;
  var el = {};
  var rec = null;
  var state = 'idle'; // idle | listening | review
  var committed = '';
  var wantListening = false;
  var restarts = 0;
  var current = null;

  function ctor() { return window.SpeechRecognition || window.webkitSpeechRecognition || null; }
  function supported() { return !!ctor(); }

  function load(key, fallback) {
    try { var v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; }
  }
  function save(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
  }

  function build() {
    if (dlg) { return; }
    dlg = document.createElement('dialog');
    dlg.id = 'ui-voice';
    dlg.className = 'ui-sheet';
    dlg.innerHTML = '<div class="ui-sheet-body">'
      + '<div class="ui-sheet-head"><h2 id="ui-voice-title">Dictate</h2><button type="button" class="ui-icon-btn" data-voice="cancel" aria-label="Close">&times;</button></div>'
      + '<p id="ui-voice-status" class="ui-muted ui-small" role="status"></p>'
      + '<textarea id="ui-voice-text" class="ui-voice-text" rows="8" aria-label="Transcript"></textarea>'
      + '<label class="ui-check"><input type="checkbox" id="ui-voice-readback"> Read back automatically</label>'
      + '<div class="ui-voice-actions">'
      + '<button type="button" class="ui-btn ui-btn-primary" data-voice="stop">Stop</button>'
      + '<button type="button" class="ui-btn" data-voice="readback">Read back</button>'
      + '<button type="button" class="ui-btn" data-voice="more">Add more</button>'
      + '<button type="button" class="ui-btn" data-voice="retry">Retry</button>'
      + '<button type="button" class="ui-btn ui-btn-primary" data-voice="send">Send</button>'
      + '</div></div>';
    document.body.appendChild(dlg);
    el.title = dlg.querySelector('#ui-voice-title');
    el.status = dlg.querySelector('#ui-voice-status');
    el.text = dlg.querySelector('#ui-voice-text');
    el.readback = dlg.querySelector('#ui-voice-readback');
    el.readback.checked = load(READBACK_KEY, '1') === '1';
    el.readback.addEventListener('change', function () { save(READBACK_KEY, el.readback.checked ? '1' : '0'); });
    dlg.addEventListener('click', function (e) {
      var node = e.target;
      while (node && node !== dlg && !(node.getAttribute && node.getAttribute('data-voice'))) { node = node.parentNode; }
      if (!node || node === dlg) { return; }
      var act = node.getAttribute('data-voice');
      if (act === 'stop') { stopListening(); }
      else if (act === 'readback') { speak(); }
      else if (act === 'more') { startListening(false); }
      else if (act === 'retry') { startListening(true); }
      else if (act === 'send') { send(); }
      else if (act === 'cancel') { close(); }
    });
    dlg.addEventListener('close', teardown);
    dlg.addEventListener('cancel', teardown);
  }

  function setStatus(text, bad) {
    el.status.textContent = text || '';
    el.status.classList.toggle('ui-voice-bad', !!bad);
  }

  function show(which) {
    state = which;
    var listening = which === 'listening';
    el.text.readOnly = listening;
    dlg.querySelector('[data-voice="stop"]').hidden = !listening;
    ['readback', 'more', 'retry', 'send'].forEach(function (name) {
      dlg.querySelector('[data-voice="' + name + '"]').hidden = listening;
    });
    dlg.querySelector('[data-voice="send"]').disabled = el.text.value.trim() === '';
    dlg.querySelector('[data-voice="readback"]').disabled = el.text.value.trim() === '';
    dlg.classList.toggle('is-listening', listening);
  }

  // Speech synthesis only starts from a tap on iOS; speaking an empty utterance during the tap that opens
  // the dialog unlocks the later read-back, which runs after recognition has ended.
  function unlockSpeech() {
    if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { return; }
    try { window.speechSynthesis.speak(new window.SpeechSynthesisUtterance('')); } catch (e) {}
  }

  function speak() {
    var synth = window.speechSynthesis;
    var text = el.text.value.trim();
    if (!synth || !window.SpeechSynthesisUtterance || text === '') { return; }
    try {
      synth.cancel();
      synth.speak(new window.SpeechSynthesisUtterance(text));
    } catch (e) { setStatus('Read back is not available: ' + e.message, true); }
  }
  function stopSpeaking() {
    try { if (window.speechSynthesis) { window.speechSynthesis.cancel(); } } catch (e) {}
  }

  function join(a, b) { return (a + ' ' + b).replace(/\s+/g, ' ').trim(); }

  function startListening(clear) {
    var Recognition = ctor();
    if (!Recognition) { setStatus('Speech recognition is not available in this browser. Use the keyboard microphone instead.', true); show('review'); return; }
    stopSpeaking();
    committed = clear ? '' : el.text.value.trim();
    el.text.value = committed;
    restarts = 0;
    wantListening = true;
    show('listening');
    setStatus('Listening. Speak your whole message, then tap Stop.');
    begin();
  }

  function begin() {
    var Recognition = ctor();
    var sessionText = '';
    rec = new Recognition();
    rec.continuous = true;
    rec.interimResults = true;
    rec.lang = (navigator.language || 'en-US');

    rec.onresult = function (event) {
      var text = '';
      for (var i = 0; i < event.results.length; i++) { text += event.results[i][0].transcript + ' '; }
      sessionText = text;
      el.text.value = join(committed, sessionText);
    };
    rec.onerror = function (event) {
      var code = event && event.error ? event.error : 'unknown';
      if (code === 'no-speech' || code === 'aborted') { return; }
      wantListening = false;
      if (code === 'not-allowed' || code === 'service-not-allowed') {
        setStatus('Speech recognition is blocked (' + code + '). Allow the microphone and Speech Recognition for this site, or use the keyboard microphone. A home-screen app may not support it.', true);
      } else {
        setStatus('Speech recognition failed (' + code + ').', true);
      }
    };
    rec.onend = function () {
      committed = join(committed, sessionText);
      sessionText = '';
      el.text.value = committed;
      if (wantListening && restarts < MAX_RESTARTS) {
        restarts++;
        setTimeout(function () { if (wantListening) { try { begin(); } catch (e) { finish(); } } }, RESTART_DELAY_MS);
        return;
      }
      finish();
    };
    try { rec.start(); } catch (e) { wantListening = false; setStatus('Could not start listening: ' + e.message, true); finish(); }
  }

  function stopListening() {
    wantListening = false;
    if (rec) { try { rec.stop(); } catch (e) { finish(); } } else { finish(); }
  }

  // Runs once recognition has ended for good: show the transcript for review and read it back.
  function finish() {
    if (state !== 'listening') { return; }
    wantListening = false;
    show('review');
    if (el.text.value.trim() === '') {
      setStatus('Nothing was heard. Tap Retry to try again.', el.status.classList.contains('ui-voice-bad'));
      return;
    }
    if (!el.status.classList.contains('ui-voice-bad')) { setStatus('Check the text, edit it if needed, then ' + (current.sendLabel || 'Send').toLowerCase() + ' or retry.'); }
    if (el.readback.checked) { speak(); }
  }

  function teardown() {
    wantListening = false;
    if (rec) { try { rec.abort(); } catch (e) {} rec = null; }
    stopSpeaking();
    state = 'idle';
  }

  function close() {
    teardown();
    if (dlg && dlg.open) { dlg.close(); }
  }

  function send() {
    var text = el.text.value.trim();
    if (text === '' || !current) { return; }
    var handler = current.onSend;
    close();
    handler(text);
  }

  function open(opts) {
    build();
    current = opts;
    el.title.textContent = opts.title || 'Dictate';
    dlg.querySelector('[data-voice="send"]').textContent = opts.sendLabel || 'Send';
    el.text.value = '';
    setStatus('');
    unlockSpeech();
    if (!dlg.open) { dlg.showModal(); }
    startListening(true);
  }

  function refresh() {
    attached.forEach(function (a) { a.button.hidden = !supported(); });
  }

  // opts: { button, title, sendLabel, onSend(text) }. The button stays hidden when the browser has no speech recognition.
  function attach(opts) {
    attached.push(opts);
    opts.button.hidden = !supported();
    opts.button.addEventListener('click', function () { open(opts); });
  }

  window.UiVoice = { attach: attach, refresh: refresh, supported: supported };
}());
