// The Ollama session page's model switch - the only per-session control this
// agent has (see OllamaHeadlessRuntime::update_settings(): no mode, no effort,
// no provider). The select is rendered with the session's current model
// already in it (partials/transcript/ollama-model-toggle.php), so it stays
// correct even when this file's catalog fetch fails; a successful fetch swaps
// in every chat-capable model the server currently has. ES5 only - this file
// deliberately avoids ES6+ (mobile Safari).
(function () {
  var select = document.getElementById('ollama-model-select');

  if (!select) {
    return;
  }

  var sessionName = window.SESSIONEER_BOOTSTRAP.session;
  var csrfToken = window.SESSIONEER_BOOTSTRAP.csrfToken;
  var currentModel = select.getAttribute('data-current-model') || '';

  function selectCurrentModel() {
    for (var i = 0; i < select.options.length; i++) {
      if (select.options[i].value === currentModel) {
        select.selectedIndex = i;
        return;
      }
    }
  }

  // /session_list_models.php reaches the host agent, which asks the Ollama
  // server itself (AgentAdapter::model_catalog()) - the container can't.
  fetch('/session_list_models.php?agent=ollama', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data || !data.ok || !data.models || !data.models.length) {
        return;
      }

      while (select.options.length > 0) {
        select.remove(0);
      }

      data.models.forEach(function (m) {
        var opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.name || m.id;
        select.appendChild(opt);
      });

      selectCurrentModel();
    })
    .catch(function () {});

  select.addEventListener('change', function () {
    var chosenModel = select.value;
    select.disabled = true;

    fetch('/session_model.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, model: chosenModel }).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        select.disabled = false;

        // Refused while a turn is still answering, or for a model that is no
        // longer pulled - both need to be visible, so the select snaps back
        // to the model the session actually has.
        if (!data || !data.ok) {
          select.value = currentModel;
          alert((data && data.message) || 'Could not change model.');
          return;
        }

        currentModel = chosenModel;
        select.setAttribute('data-current-model', currentModel);
      })
      .catch(function () {
        select.disabled = false;
        select.value = currentModel;
        alert('Network error - model not changed.');
      });
  });
})();
