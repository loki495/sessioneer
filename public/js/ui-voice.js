/* Voice input: tap a mic button once, speak a whole message and stop talking. After a pause the panel stops
   listening, reads the text back, then listens for a spoken "send", "add more" or "clear" (the buttons work
   too). The browser's speech recognition transcribes. Recognition on iOS ends on its own after a pause, so
   while the person has not stopped it is restarted and the pieces are joined. A second part watches a text
   box for keyboard dictation and offers the same choices after a pause. Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  var READBACK_KEY = 'v2-voice-readback';
  var COMMANDS_KEY = 'v2-voice-commands';
  var PAUSE_KEY = 'v2-voice-pause'; // seconds of silence that end dictation; 0 turns it off
  var PAUSE_DEFAULT_SECONDS = '3';
  var FIRST_SPEECH_WAIT_MS = 10000;
  var COMMAND_WAIT_MS = 8000;
  var COMMAND_ATTEMPTS = 2;
  var COMMAND_MAX_WORDS = 4;
  var SPEAK_FALLBACK_BASE_MS = 4000;
  var SPEAK_FALLBACK_PER_CHAR_MS = 90;
  var MAX_RESTARTS = 60;
  var RESTART_DELAY_MS = 150;
  var BURST_MIN_CHARS = 8; // a keyboard-dictation insert is a phrase; typing adds one character
  var BAR_POLL_MS = 1000;

  var attached = [];
  var dlg = null;
  var el = {};
  var rec = null;
  var cmdRec = null;
  var state = 'idle'; // idle | listening | review
  var committed = '';
  var wantListening = false;
  var restarts = 0;
  var current = null;
  var silenceTimer = null;
  var cmdTimer = null;
  var speakTimer = null;

  function ctor() { return window.SpeechRecognition || window.webkitSpeechRecognition || null; }
  function supported() { return !!ctor(); }

  function load(key, fallback) {
    try { var v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; }
  }
  function save(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
  }
  function pauseMs() {
    var seconds = parseFloat(load(PAUSE_KEY, PAUSE_DEFAULT_SECONDS));
    return seconds > 0 ? Math.round(seconds * 1000) : 0;
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
      + '<div class="ui-voice-actions">'
      + '<button type="button" class="ui-btn ui-btn-primary" data-voice="stop">Stop</button>'
      + '<button type="button" class="ui-btn" data-voice="readback">Read back</button>'
      + '<button type="button" class="ui-btn" data-voice="more">Add more</button>'
      + '<button type="button" class="ui-btn" data-voice="retry">Clear</button>'
      + '<button type="button" class="ui-btn ui-btn-primary" data-voice="send">Send</button>'
      + '</div>'
      + '<label class="ui-check"><input type="checkbox" id="ui-voice-readback"> Read back automatically</label>'
      + '<label class="ui-check"><input type="checkbox" id="ui-voice-commands"> Listen for &ldquo;send&rdquo;, &ldquo;add more&rdquo; or &ldquo;clear&rdquo;</label>'
      + '<label class="ui-field">Stop listening after a pause'
      + '<select id="ui-voice-pause"><option value="0">Never (tap Stop)</option><option value="2">2 seconds</option><option value="3">3 seconds</option><option value="5">5 seconds</option></select></label>'
      + '</div>';
    document.body.appendChild(dlg);
    el.title = dlg.querySelector('#ui-voice-title');
    el.status = dlg.querySelector('#ui-voice-status');
    el.text = dlg.querySelector('#ui-voice-text');
    el.readback = dlg.querySelector('#ui-voice-readback');
    el.commands = dlg.querySelector('#ui-voice-commands');
    el.pause = dlg.querySelector('#ui-voice-pause');
    el.readback.checked = load(READBACK_KEY, '1') === '1';
    el.commands.checked = load(COMMANDS_KEY, '1') === '1';
    syncPauseSelect();
    el.readback.addEventListener('change', function () { save(READBACK_KEY, el.readback.checked ? '1' : '0'); });
    el.commands.addEventListener('change', function () {
      save(COMMANDS_KEY, el.commands.checked ? '1' : '0');
      if (!el.commands.checked) { stopCommand(); }
    });
    el.pause.addEventListener('change', function () { save(PAUSE_KEY, el.pause.value); });
    dlg.addEventListener('click', function (e) {
      var node = e.target;
      while (node && node !== dlg && !(node.getAttribute && node.getAttribute('data-voice'))) { node = node.parentNode; }
      if (!node || node === dlg) { return; }
      stopCommand();
      var act = node.getAttribute('data-voice');
      if (act === 'stop') { stopListening(); }
      else if (act === 'readback') { setStatus('Reading it back...'); speak(listenForCommand); }
      else if (act === 'more') { startListening(false); }
      else if (act === 'retry') { startListening(true); }
      else if (act === 'send') { send(); }
      else if (act === 'cancel') { close(); }
    });
    // The close event is queued, so it can arrive after the panel was opened again; it must not stop that listening.
    dlg.addEventListener('close', function () { if (!dlg.open) { teardown(); } });
    dlg.addEventListener('cancel', teardown);
  }

  // The stored pause may not be one of the listed choices (the tests use a short one); show it as-is.
  function syncPauseSelect() {
    var stored = load(PAUSE_KEY, PAUSE_DEFAULT_SECONDS);
    var found = false;
    for (var i = 0; i < el.pause.options.length; i++) { if (el.pause.options[i].value === stored) { found = true; } }
    if (!found) {
      var opt = document.createElement('option');
      opt.value = stored;
      opt.textContent = stored + ' seconds';
      el.pause.appendChild(opt);
    }
    el.pause.value = stored;
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
  // the dialog unlocks the later read back, which runs after recognition has ended.
  function unlockSpeech() {
    if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { return; }
    try { window.speechSynthesis.speak(new window.SpeechSynthesisUtterance('')); } catch (e) {}
  }

  // Speaks the transcript, then calls done (when given). done also runs if speech is unavailable or never
  // reports its end, so the commands step is not stuck behind a read back that never finishes.
  function speak(done) {
    var synth = window.speechSynthesis;
    var text = el.text.value.trim();
    clearTimeout(speakTimer);
    var called = false;
    function finishOnce() {
      if (called) { return; }
      called = true;
      clearTimeout(speakTimer);
      if (done) { done(); }
    }
    if (!synth || !window.SpeechSynthesisUtterance || text === '') { finishOnce(); return; }
    try {
      synth.cancel();
      var utterance = new window.SpeechSynthesisUtterance(text);
      utterance.onend = finishOnce;
      utterance.onerror = finishOnce;
      synth.speak(utterance);
      speakTimer = setTimeout(finishOnce, SPEAK_FALLBACK_BASE_MS + text.length * SPEAK_FALLBACK_PER_CHAR_MS);
    } catch (e) {
      setStatus('Read back is not available: ' + e.message, true);
      finishOnce();
    }
  }
  function stopSpeaking() {
    clearTimeout(speakTimer);
    try { if (window.speechSynthesis) { window.speechSynthesis.cancel(); } } catch (e) {}
  }

  function join(a, b) { return (a + ' ' + b).replace(/\s+/g, ' ').trim(); }

  // A web page cannot open the Settings app on iOS, so the panel says where to go.
  function isHomeScreenApp() {
    return window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  }
  function blockedHelp(code) {
    var head = 'Speech recognition is blocked (' + code + '). ';
    if (isHomeScreenApp()) {
      return head + 'This is the home-screen app, where iOS often does not allow it. Open this page in Safari instead, or use the keyboard microphone key.';
    }
    return head + 'In Safari, tap the aA button, then Website Settings, and allow Microphone. Also check Settings > Privacy & Security > Speech Recognition and Microphone, and that Dictation is on (Settings > General > Keyboard > Enable Dictation). Or use the keyboard microphone key.';
  }

  // ---- dictating ----

  function armSilence(ms) {
    clearTimeout(silenceTimer);
    if (pauseMs() === 0) { return; }
    silenceTimer = setTimeout(function () { if (state === 'listening' && wantListening) { stopListening(); } }, ms);
  }

  function startListening(clear) {
    var Recognition = ctor();
    if (!Recognition) { setStatus('Speech recognition is not available in this browser. Use the keyboard microphone instead.', true); show('review'); return; }
    stopSpeaking();
    stopCommand();
    committed = clear ? '' : el.text.value.trim();
    el.text.value = committed;
    restarts = 0;
    wantListening = true;
    show('listening');
    setStatus(pauseMs() > 0 ? 'Listening. Speak your whole message; I stop when you pause.' : 'Listening. Speak your whole message, then tap Stop.');
    armSilence(FIRST_SPEECH_WAIT_MS);
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
      armSilence(pauseMs());
    };
    rec.onerror = function (event) {
      var code = event && event.error ? event.error : 'unknown';
      if (code === 'no-speech' || code === 'aborted') { return; }
      wantListening = false;
      clearTimeout(silenceTimer);
      if (code === 'not-allowed' || code === 'service-not-allowed') {
        setStatus(blockedHelp(code), true);
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
    clearTimeout(silenceTimer);
    if (rec) { try { rec.stop(); } catch (e) { finish(); } } else { finish(); }
  }

  // Runs once recognition has ended for good: show the transcript for review, read it back, then listen for a command.
  function finish() {
    if (state !== 'listening') { return; }
    wantListening = false;
    clearTimeout(silenceTimer);
    show('review');
    if (el.text.value.trim() === '') {
      setStatus('Nothing was heard. Tap Clear to try again.', el.status.classList.contains('ui-voice-bad'));
      return;
    }
    if (el.status.classList.contains('ui-voice-bad')) { return; }
    setStatus('Check the text, edit it if needed, then ' + (current.sendLabel || 'Send').toLowerCase() + ' or clear.');
    if (el.readback.checked) { setStatus('Reading it back...'); speak(listenForCommand); } else { listenForCommand(); }
  }

  // ---- spoken commands after the read back ----

  var NEGATION = /\b(don'?t|do not|not|no|never)\b/;
  var COMMANDS = [
    { name: 'cancel', re: /\b(cancel|close|never mind|forget it)\b/ },
    { name: 'retry', re: /\b(clear|retry|redo|start over|try again|delete)\b/ },
    { name: 'more', re: /\b(add more|more|continue|keep going|add)\b/ },
    { name: 'send', re: /\b(send|submit|confirm|yes|go ahead|use it|use this)\b/ }
  ];

  // A short phrase only, and never with a negation: "don't send" must not send.
  function commandFor(transcript) {
    var text = String(transcript || '').toLowerCase().replace(/[^a-z' ]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '' || text.split(' ').length > COMMAND_MAX_WORDS || NEGATION.test(text)) { return null; }
    for (var i = 0; i < COMMANDS.length; i++) { if (COMMANDS[i].re.test(text)) { return COMMANDS[i].name; } }
    return null;
  }

  function runCommand(name) {
    if (name === 'send') { send(); }
    else if (name === 'more') { startListening(false); }
    else if (name === 'retry') { startListening(true); }
    else if (name === 'cancel') { close(); }
  }

  function stopCommand() {
    clearTimeout(cmdTimer);
    if (cmdRec) {
      var old = cmdRec;
      cmdRec = null;
      old.onresult = null; old.onerror = null; old.onend = null;
      try { old.abort(); } catch (e) {}
    }
  }

  function listenForCommand(attempt) {
    var Recognition = ctor();
    attempt = attempt || 0;
    if (!Recognition || !el.commands.checked || state !== 'review' || !dlg.open) { setStatus('Tap Send, Add more or Clear.'); return; }
    stopCommand();
    var heard = false;
    var mine = new Recognition();
    cmdRec = mine;
    mine.continuous = false;
    mine.interimResults = false;
    mine.lang = (navigator.language || 'en-US');
    setStatus('Say "' + (current.sendLabel || 'send').toLowerCase() + '", "add more" or "clear", or tap a button.');
    mine.onresult = function (event) {
      if (cmdRec !== mine) { return; }
      heard = true;
      var name = commandFor(event.results[0] && event.results[0][0] ? event.results[0][0].transcript : '');
      stopCommand();
      if (name) { runCommand(name); return; }
      setStatus('I did not catch a command. Tap Send, Add more or Clear.');
    };
    mine.onerror = function () { if (cmdRec === mine) { stopCommand(); setStatus('Tap Send, Add more or Clear.'); } };
    mine.onend = function () {
      if (cmdRec !== mine || heard) { return; }
      stopCommand();
      if (state === 'review' && dlg.open && attempt + 1 < COMMAND_ATTEMPTS) { listenForCommand(attempt + 1); } else { setStatus('Tap Send, Add more or Clear.'); }
    };
    cmdTimer = setTimeout(function () { if (cmdRec === mine) { try { mine.stop(); } catch (e) {} } }, COMMAND_WAIT_MS);
    try { mine.start(); } catch (e) { stopCommand(); setStatus('Tap Send, Add more or Clear.'); }
  }

  function teardown() {
    wantListening = false;
    clearTimeout(silenceTimer);
    stopCommand();
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
    el.readback.checked = load(READBACK_KEY, '1') === '1';
    el.commands.checked = load(COMMANDS_KEY, '1') === '1';
    syncPauseSelect();
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

  // ---- keyboard dictation: Send / Add more / Clear after the text stops changing ----

  // The browser gives no "dictation stopped" event. A phrase-sized insert that is not a paste or a drop is
  // taken as dictation; a pause after it shows the bar. Typing or editing afterwards hides it again.
  // opts: { field, before (element the bar goes in front of), sendLabel (omit for no Send), onSend(text) }.
  function watch(opts) {
    var field = opts.field;
    var bar = document.createElement('div');
    bar.className = 'ui-dictbar';
    bar.hidden = true;
    bar.setAttribute('role', 'status');
    bar.innerHTML = '<span class="ui-muted ui-small">Done dictating?</span>'
      + (opts.sendLabel ? '<button type="button" class="ui-btn ui-btn-primary" data-bar="send"></button>' : '')
      + '<button type="button" class="ui-btn" data-bar="more">Add more</button>'
      + '<button type="button" class="ui-btn" data-bar="clear">Clear</button>';
    if (opts.sendLabel) { bar.querySelector('[data-bar="send"]').textContent = opts.sendLabel; }
    opts.before.parentNode.insertBefore(bar, opts.before);

    var lastLength = field.value.length;
    var burst = false;
    var showTimer = null;
    var pollTimer = null;

    function hide() {
      clearTimeout(showTimer);
      clearInterval(pollTimer);
      pollTimer = null;
      bar.hidden = true;
    }
    function reveal() {
      if (!burst || field.value.trim() === '') { return; }
      bar.hidden = false;
      // The text can be cleared elsewhere (a send, a draft reset): drop the bar with it.
      pollTimer = setInterval(function () { if (field.value.trim() === '') { hide(); } }, BAR_POLL_MS);
    }

    field.addEventListener('input', function (event) {
      // A value shorter than before with no delete event was reset by the page (after a send); count it fresh.
      var grew = field.value.length < lastLength ? field.value.length : field.value.length - lastLength;
      lastLength = field.value.length;
      var type = event && event.inputType ? event.inputType : '';
      hide();
      if (type === 'insertFromPaste' || type === 'insertFromDrop' || type.indexOf('delete') === 0) { burst = false; return; }
      if (grew >= BURST_MIN_CHARS) { burst = true; } else { burst = false; return; }
      if (pauseMs() > 0) { showTimer = setTimeout(reveal, pauseMs()); }
    });

    bar.addEventListener('click', function (e) {
      var node = e.target;
      while (node && node !== bar && !(node.getAttribute && node.getAttribute('data-bar'))) { node = node.parentNode; }
      if (!node || node === bar) { return; }
      var act = node.getAttribute('data-bar');
      burst = false;
      hide();
      if (act === 'send') { opts.onSend(field.value.trim()); lastLength = field.value.length; }
      else if (act === 'clear') { field.value = ''; lastLength = 0; field.dispatchEvent(new Event('input', { bubbles: true })); field.focus(); }
      else { field.focus(); }
    });
  }

  window.UiVoice = { attach: attach, watch: watch, refresh: refresh, supported: supported, commandFor: commandFor };
}());
