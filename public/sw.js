// Service worker for Web Push notifications - see push_subscribe.php and
// the "Notify me" button's JS (in AgentClient.php's push_notify_button_html())
// for the subscribe flow. Registered at root scope (/sw.js), so it can
// receive pushes and handle notification clicks regardless of which page
// (index.php or session.php) is currently open, or even if none is.
//
// The push handler ALWAYS calls event.waitUntil(showNotification(...)),
// synchronously, even in a hypothetical no-data-parsed case - iOS Safari
// treats a push that doesn't result in a shown notification as "silent",
// and permanently kills the subscription after 3 of those with no error
// signal to the app. There is deliberately no code path here that can
// skip showing a notification.

// --- CSRF token cache (IndexedDB) - read side ---
// A service worker has no access to server-rendered HTML or localStorage
// (a completely separate execution context from any page), so the
// Approve/Deny notification actions below
// need some other way to learn the app's CSRF token before they can
// fetch() answer_prompt.php headlessly. push-notify.js writes the current
// token into this same IndexedDB store on every page load - see its own
// comment for why IndexedDB specifically (the only storage this context
// and a normal page both have access to).
function readPushCsrfToken() {
  return new Promise(function (resolve) {
    try {
      var openReq = indexedDB.open('sessioneer-push', 1);

      openReq.onupgradeneeded = function () {
        openReq.result.createObjectStore('kv');
      };

      openReq.onsuccess = function () {
        var db = openReq.result;
        var tx = db.transaction('kv', 'readonly');
        var getReq = tx.objectStore('kv').get('csrf_token');

        getReq.onsuccess = function () {
          db.close();
          resolve(typeof getReq.result === 'string' ? getReq.result : null);
        };
        getReq.onerror = function () {
          db.close();
          resolve(null);
        };
      };

      openReq.onerror = function () {
        resolve(null);
      };
    } catch (e) {
      resolve(null);
    }
  });
}

// Shared by notificationclick's plain-tap path and its approve/deny
// fallback (when the answer can't be sent headlessly - see below) -
// clients.openWindow() takes a relative url just fine per spec (resolved
// against the SW's own registration scope), but iOS Safari's PWA
// (home-screen-installed, standalone display) mode has a known history of
// mishandling that and opening/focusing the app at its start_url instead
// of the requested one. Passing an
// already-absolute URL is the commonly-cited workaround, so trying that
// here rather than the relative form.
function openOrFocusUrl(url) {
  var absoluteUrl = new URL(url, self.location.origin).href;

  return clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
    for (var i = 0; i < clientList.length; i++) {
      if (clientList[i].url.indexOf(url) !== -1 && 'focus' in clientList[i]) {
        return clientList[i].focus();
      }
    }

    if (clients.openWindow) {
      return clients.openWindow(absoluteUrl);
    }

    return undefined;
  });
}

self.addEventListener('push', function (event) {
  var data = {};

  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {}

  var title = data.title || 'Sessioneer';
  var options = {
    body: data.body || 'A session needs your attention.',
    data: {
      url: data.url || '/',
      session: data.session || null,
      approveOption: data.approve_option != null ? data.approve_option : null,
      denyOption: data.deny_option != null ? data.deny_option : null
    }
  };

  // One-tap Approve/Deny action buttons - only offered when the push
  // payload actually carries both option numbers (see
  // PushDeliveryService::approve_deny_actions() on the host-agent side;
  // never set for a "finished"/quota notification, only a blocked one).
  // Platform support for `actions` varies: full
  // on Android Chrome and desktop Chrome/Edge on Windows/Linux, degraded
  // to hover+"More" on macOS, absent entirely on iOS Safari and Firefox -
  // a browser that doesn't support `actions` just silently ignores this
  // key and shows a plain notification, same as before this existed.
  if (data.approve_option != null && data.deny_option != null) {
    options.actions = [
      { action: 'approve', title: 'Approve' },
      { action: 'deny', title: 'Deny' }
    ];
  }

  // Both promises collected and passed to ONE event.waitUntil(Promise.all(...))
  // - matches WebKit's own documented pattern exactly (see
  // https://webkit.org/blog/14112/badging-for-home-screen-web-apps/), not
  // two separate waitUntil() calls as this originally shipped. The
  // separate-calls version never actually showed a badge on a real iOS
  // device (notification itself worked fine, so this wasn't
  // a permissions issue) despite no thrown/caught error anywhere - iOS
  // Safari's Badging API implementation apparently doesn't reliably honor
  // a badge promise registered via a SECOND, separate waitUntil() call.
  // No real per-notification unseen count is tracked (would need
  // persistent storage across SW restarts) - a fixed 1 just flags "there's
  // something", cleared back off entirely by clearAppBadge() below/on
  // page load.
  var promises = [self.registration.showNotification(title, options)];

  if ('setAppBadge' in self.navigator) {
    promises.push(self.navigator.setAppBadge(1).catch(function () {}));
  }

  event.waitUntil(Promise.all(promises));
});

self.addEventListener('notificationclick', function (event) {
  var notifData = event.notification.data || {};

  // Approve/Deny tapped directly on the notification - answers the prompt headlessly via fetch(), with NO
  // window ever opening, matching what a native app's notification action
  // does. Falls back to the plain open/focus behavior below if anything
  // needed to do that isn't actually available (no cached CSRF token yet,
  // or the payload is somehow missing the option it claims to answer) -
  // opening the app to answer it by hand beats silently doing nothing.
  if (event.action === 'approve' || event.action === 'deny') {
    event.notification.close();

    var option = event.action === 'approve' ? notifData.approveOption : notifData.denyOption;

    var answerPromise = readPushCsrfToken().then(function (csrfToken) {
      if (!csrfToken || !notifData.session || option == null) {
        return openOrFocusUrl(notifData.url || '/');
      }

      return fetch('/answer_prompt.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          csrf_token: csrfToken,
          session: notifData.session,
          option: String(option),
          expected_label: event.action === 'approve' ? 'Yes' : 'No'
        }).toString()
      }).catch(function () {});
    });

    var actionPromises = [answerPromise];

    if ('setAppBadge' in self.navigator) {
      actionPromises.push(self.navigator.clearAppBadge().catch(function () {}));
    }

    event.waitUntil(Promise.all(actionPromises));
    return;
  }

  event.notification.close();

  // Same single-Promise.all()-in-one-waitUntil() pattern as the push
  // handler above, for the same reason.
  var promises = [openOrFocusUrl(notifData.url || '/')];

  if ('setAppBadge' in self.navigator) {
    promises.push(self.navigator.clearAppBadge().catch(function () {}));
  }

  event.waitUntil(Promise.all(promises));
});
