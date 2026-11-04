/* Sessioneer v2: usage meters shared by the dashboard Status tab and the session page's quota popup.
   Plain ES5 (see CLAUDE.md). Reset times are shown in Pacific time (the accounts' own reset zone) and relative. */
(function () {
  'use strict';

  var LABELS = { session: '5-hour', week_all: 'Week', week_fable: 'Week (Fable)', month_all: 'Month' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function relative(seconds) {
    if (seconds <= 0) { return 'now'; }
    var m = Math.floor(seconds / 60);
    if (m < 60) { return 'in ' + Math.max(m, 1) + 'm'; }
    var h = Math.floor(m / 60);
    if (h < 48) { return 'in ' + h + 'h' + (m % 60 ? ' ' + (m % 60) + 'm' : ''); }
    return 'in ' + Math.floor(h / 24) + 'd ' + (h % 24) + 'h';
  }

  function pacific(epoch) {
    try {
      return new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Los_Angeles', weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short'
      }).format(new Date(epoch * 1000));
    } catch (e) {
      return new Date(epoch * 1000).toUTCString();
    }
  }

  function resetLine(epoch) {
    if (typeof epoch !== 'number') { return ''; }
    return '<span class="v2-meter-reset">Resets ' + esc(pacific(epoch)) + ' · ' + esc(relative(epoch - Math.floor(Date.now() / 1000))) + '</span>';
  }

  function meter(label, bucket) {
    var pct = bucket.pct;
    var cls = pct >= 100 ? 'is-full' : (pct >= 80 ? 'is-high' : '');
    return '<div class="v2-meter"><div class="v2-meter-top"><span>' + esc(label) + '</span><b>' + Math.round(pct) + '%</b></div>'
      + '<div class="v2-meter-bar"><span class="' + cls + '" style="width:' + Math.min(100, Math.max(0, pct)) + '%"></span></div>'
      + resetLine(bucket.resets_at) + '</div>';
  }

  function rows(quota) {
    if (!quota) { return ''; }
    return Object.keys(quota).filter(function (k) { return quota[k] && typeof quota[k].pct === 'number'; }).map(function (k) {
      var b = quota[k];
      return meter(LABELS[k] || (b.group_name ? b.group_name + ' ' + k : k), b);
    }).join('');
  }

  // One group per agent (per account for Claude when several are configured), a divider between groups.
  // opts.first: agent id listed first; opts.only: agent id only.
  function render(data, opts) {
    opts = opts || {};
    var agents = (data && data.agents) || {};
    var ids = Object.keys(agents);
    if (opts.only) { ids = ids.filter(function (id) { return id === opts.only; }); }
    if (opts.first) { ids.sort(function (a, b) { return (b === opts.first) - (a === opts.first); }); }
    var groups = [];
    ids.forEach(function (id) {
      var a = agents[id];
      if (!a || !a.ok) { return; }
      var profiles = id === 'claude' && data.claude_profiles ? Object.keys(data.claude_profiles) : [];
      if (profiles.length > 1) {
        profiles.forEach(function (p) {
          var pr = data.claude_profiles[p];
          var body = pr && pr.ok ? rows(pr.quota) : '';
          if (body) { groups.push('<section class="v2-quota-group v2-qg-' + esc(id) + '"><h3 class="v2-subtitle">' + esc(a.label || id) + ' · ' + esc(pr.label || p) + '</h3>' + body + '</section>'); }
        });
        return;
      }
      var html = rows(a.quota);
      if (html) { groups.push('<section class="v2-quota-group v2-qg-' + esc(id) + '"><h3 class="v2-subtitle">' + esc(a.label || id) + '</h3>' + html + '</section>'); }
    });

    return groups.join('') || '<span class="v2-muted v2-small">No usage data available.</span>';
  }

  window.V2Quota = { render: render, pacific: pacific, relative: relative };
}());
