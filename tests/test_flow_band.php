<?php

declare(strict_types=1);

/**
 * Step 7 of session-flow: the flow band, rendered from the stored overlay (no model turn, no host agent).
 * Written from the Contract only; it fails until src/partials/ui/flow-band.php and its callers exist.
 *
 * Markup pinned here (the Contract names the buttons, not the markup):
 *  host     <div class="ui-flow" data-flow-host data-flow-where="card|session|popup" data-flow-state="ROTATE"
 *                data-session="NAME" data-csrf="TOKEN"
 *                [data-handoff-id="ID"]          the offer's handoff id, only when the offer has one
 *                [data-prompt="TEXT"]            the offer's prompt, only when the offer has one (attribute-escaped)
 *                [data-receiver-agent="AGENT" data-receiver-profile="PROFILE"]   flow.receiver, when set
 *                data-agent="AGENT" data-profile="PROFILE" data-workdir="DIR" data-conversation="ID">   the source row
 *  buttons  <button type="button" class="ui-btn ui-flow-btn" data-flow-act="start|handoff|takeover|continue|wait|judge|edit">
 *           labels, exact: Start receiver | Hand off and start | Take over from transcript | Continue here |
 *           Wait for reset | Ask a small model | Edit in form
 *  chip     <span class="ui-chip ui-flow-chip" data-flow-chip>, with no data-flow-host and no button (WARM_TIGHT)
 *  reason   the decision's why text, HTML-escaped, inside the host
 * Which buttons (flow = the overlay decision; offer = row['offer'] with handoff_id, prompt, decision):
 *  - ROTATE: takeover, continue, edit always; start when the offer carries a handoff_id, else handoff
 *    (never both); judge only when close_call && trigger == quota && reset_at is set (the host refuses any
 *    other judge); never wait.
 *  - PARK and WAIT: wait; never start, handoff, judge or continue.
 *  - GO, UNKNOWN: nothing at all. WARM_TIGHT: the chip only.
 *  - A row with an offer and no flow key takes its state from offer['decision']['state'].
 *  - No flow and no offer: nothing.
 * Both the dashboard card (UiView::card_html) and the session page (UiView::session_page: where=session in
 * the conversation column, where=popup inside the details sheet) render the same partial.
 */

require __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Views\UiView;

$decision = static fn (string $state, array $over = []): array => $over + [
    'state' => $state, 'why' => "why {$state}", 'trigger' => 'cold_cache', 'close_call' => false,
    'receiver' => null, 'reset_at' => null, 'needs_approval' => false, 'warnings' => [],
];
$base = ['name' => 'cc-flow', 'agent' => 'claude', 'profile' => 'work', 'runtime' => 'headless', 'status' => 'idle',
    'activity' => time() - 7200, 'workdir' => '/home/u/www/p', 'agent_session_id' => 'conv-1'];
$offer = static fn (array $over = []): array => $over + [
    'session_name' => 'cc-flow', 'origin' => 'agent', 'handoff_id' => null, 'plan_id' => null, 'prompt' => null,
    'decision' => $decision('ROTATE'),
];
$card = static fn (array $row): string => UiView::card_html($base + $row, 'tok');
$page = static fn (array $row): string => UiView::session_page(['sessionName' => 'cc-flow', 'csrfToken' => 'tok', 'found' => true, 'detail' => ['ok' => true] + $base + $row, 'jumpLine' => null]);
$acts = static function (string $html): array {
    preg_match_all('/<button\b[^>]*data-flow-act="([a-z]+)"/', $html, $m);

    return array_values(array_unique($m[1]));
};
$labelOf = static function (string $html, string $act): ?string {
    return preg_match('/<button\b[^>]*data-flow-act="' . $act . '"[^>]*>(.*?)<\/button>/s', $html, $m) === 1
        ? trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1])))
        : null;
};
$has = static fn (string $html, array $want): bool => array_diff($want, $acts($html)) === [];
$none = static fn (string $html, array $not): bool => array_intersect($not, $acts($html)) === [];

echo "nothing to show\n";
foreach (['no overlay' => [], 'GO' => ['flow' => $decision('GO')], 'UNKNOWN' => ['flow' => $decision('UNKNOWN')]] as $label => $row) {
    foreach (['card' => $card($row), 'page' => $page($row)] as $where => $html) {
        assert_true(!str_contains($html, 'data-flow-host') && !str_contains($html, 'data-flow-act'), "$where, $label: no band, no buttons");
    }
}

echo "WARM_TIGHT: chip only\n";
foreach (['card' => $card(['flow' => $decision('WARM_TIGHT', ['trigger' => 'context'])]), 'page' => $page(['flow' => $decision('WARM_TIGHT', ['trigger' => 'context'])])] as $where => $html) {
    assert_contains('data-flow-chip', $html, "$where: the chip is shown");
    assert_true(!str_contains($html, 'data-flow-host') && $acts($html) === [], "$where: no host element and no buttons");
}

echo "ROTATE without an offer: hand off and start\n";
$html = $card(['flow' => $decision('ROTATE')]);
assert_contains('data-flow-host', $html, 'card: band present');
assert_contains('data-flow-where="card"', $html, 'card: where=card');
assert_contains('data-flow-state="ROTATE"', $html, 'card: state on the host');
assert_contains('data-session="cc-flow"', $html, 'card: session name on the host');
assert_contains('data-csrf="tok"', $html, 'card: csrf token on the host');
foreach (['data-agent="claude"', 'data-profile="work"', 'data-workdir="/home/u/www/p"', 'data-conversation="conv-1"'] as $attr) {
    assert_contains($attr, $html, "card: source $attr on the host");
}
assert_true($has($html, ['handoff', 'takeover', 'continue', 'edit']), 'card: Hand off and start, Take over from transcript, Continue here, Edit in form');
assert_true($none($html, ['start', 'wait', 'judge']), 'card: no Start receiver (no handoff), no wait, no judge (not a close call)');
foreach (['handoff' => 'Hand off and start', 'takeover' => 'Take over from transcript', 'continue' => 'Continue here', 'edit' => 'Edit in form'] as $act => $text) {
    assert_equal($text, $labelOf($html, $act), "card: the $act button reads $text");
}
assert_contains('why ROTATE', $html, 'card: the reason is shown');

echo "ROTATE with an offer carrying a handoff: start receiver\n";
$withHandoff = ['flow' => $decision('ROTATE'), 'offer' => $offer(['handoff_id' => '4486', 'prompt' => 'Use the "handoff" <4486>'])];
$html = $card($withHandoff);
assert_true($has($html, ['start', 'takeover', 'continue', 'edit']) && $none($html, ['handoff', 'wait', 'judge']), 'card: Start receiver replaces Hand off and start');
assert_contains('data-handoff-id="4486"', $html, 'card: handoff id on the host');
assert_contains('data-prompt="Use the &quot;handoff&quot; &lt;4486&gt;"', $html, 'card: the offer prompt is attribute-escaped');
assert_true(!str_contains($html, 'Use the "handoff" <4486>'), 'card: the raw prompt never appears unescaped');
$html = $card(['flow' => $decision('ROTATE'), 'offer' => $offer()]);
assert_true(!str_contains($html, 'data-handoff-id'), 'card: an offer without a handoff has no handoff id attribute');
assert_true($has($html, ['handoff']) && $none($html, ['start']), 'card: so it hands off first');

echo "ROTATE to a receiver, and the close call\n";
$html = $card(['flow' => $decision('ROTATE', ['trigger' => 'quota', 'receiver' => ['key' => 'codex/main', 'agent' => 'codex', 'profile' => 'main'], 'needs_approval' => true])]);
assert_contains('data-receiver-agent="codex"', $html, 'card: the receiver agent is on the host');
assert_contains('data-receiver-profile="main"', $html, 'card: the receiver profile is on the host');
assert_true($none($html, ['judge']), 'card: a quota rotate that is not a close call has no model button');
$close = $decision('ROTATE', ['trigger' => 'quota', 'close_call' => true, 'reset_at' => time() + 600, 'receiver' => ['key' => 'codex/main', 'agent' => 'codex', 'profile' => 'main']]);
assert_true($has($card(['flow' => $close]), ['judge']), 'card: a close quota call offers Ask a small model');
assert_equal('Ask a small model', $labelOf($card(['flow' => $close]), 'judge'), 'card: with that label');
assert_equal('Start receiver', $labelOf($card(['flow' => $decision('ROTATE'), 'offer' => $offer(['handoff_id' => '9'])]), 'start'), 'card: the start button reads Start receiver');
$parkClose = $decision('PARK', ['trigger' => 'quota', 'close_call' => true, 'reset_at' => time() + 600]);
assert_true($none($card(['flow' => $parkClose]), ['judge']), 'card: PARK is never judged, even as a close call with a reset time (no receiver: the host refuses it)');
assert_true($none($card(['flow' => ['trigger' => 'cold_cache'] + $close]), ['judge']), 'card: a cold-cache close_call is not judged (the host refuses it)');
assert_true($none($card(['flow' => ['reset_at' => null] + $close]), ['judge']), 'card: no reset time, no judge');
assert_true($none($card(['flow' => ['close_call' => false] + $close]), ['judge']), 'card: not a close call, no judge');

echo "PARK and WAIT: wait for the reset\n";
foreach (['PARK', 'WAIT'] as $state) {
    $html = $card(['flow' => $decision($state, ['trigger' => 'quota', 'reset_at' => time() + 7200])]);
    assert_true($has($html, ['wait']) && $none($html, ['start', 'handoff', 'judge', 'continue']), "card: $state offers Wait for reset only (plus any takeover or edit)");
    assert_equal('Wait for reset', $labelOf($html, 'wait'), "card: $state wait label");
}

echo "an offer without flow\n";
$html = $card(['offer' => $offer(['handoff_id' => '7'])]);
assert_contains('data-flow-state="ROTATE"', $html, 'card: the state comes from the offer decision');
assert_true($has($html, ['start']), 'card: and the handoff offer starts a receiver');
assert_true(!str_contains($card(['offer' => $offer(['decision' => $decision('GO')])]), 'data-flow-host'), 'card: an offer whose decision is GO shows nothing');

echo "escaping\n";
$html = $card(['flow' => $decision('ROTATE', ['why' => '<script>alert(1)</script>'])]);
assert_true(!str_contains($html, '<script>alert(1)'), 'card: the reason is HTML-escaped');
assert_contains('&lt;script&gt;', $html, 'card: and shown escaped');
$html = UiView::card_html(['name' => 'a"b<c'] + $base + ['flow' => $decision('ROTATE')], 'to"k');
assert_true(!str_contains($html, 'data-session="a"b') && !str_contains($html, 'data-csrf="to"k'), 'card: session name and csrf are attribute-escaped');

echo "session page: the same band twice\n";
$html = $page(['flow' => $withHandoff['flow'], 'offer' => $withHandoff['offer']]);
assert_equal(1, substr_count($html, 'data-flow-where="session"'), 'page: one session band');
assert_equal(1, substr_count($html, 'data-flow-where="popup"'), 'page: one popup band');
assert_contains('data-csrf="tok"', $html, 'page: csrf token on the bands');
assert_contains('data-handoff-id="4486"', $html, 'page: the offer handoff reaches the page bands');
assert_contains('ui-flow.js', $html, 'page: loads the flow script');
assert_true(!str_contains($html, 'ui-advise'), 'page: nothing of the advice UI');
assert_true($has($page(['flow' => $decision('ROTATE')]), ['handoff', 'edit']), 'page: the buttons are the card buttons');
assert_true(!str_contains($page(['flow' => $decision('WARM_TIGHT')]), 'data-flow-host'), 'page: WARM_TIGHT is a chip only');

echo "static assets\n";
$root = dirname(__DIR__);
assert_true(is_file($root . '/public/js/ui-flow.js'), 'public/js/ui-flow.js exists');
$js = is_file($root . '/public/js/ui-flow.js') ? (string)file_get_contents($root . '/public/js/ui-flow.js') : '';
foreach (['start', 'handoff', 'takeover', 'continue', 'wait', 'judge', 'edit'] as $act) {
    assert_true(str_contains($js, $act), "ui-flow.js handles the $act button");
}
foreach (['flow_rotate', 'flow_rotate_request', 'flow_offer_dismiss', 'flow_judge', 'data-flow-act', 'ui-new-prefill', '/action'] as $needle) {
    assert_contains($needle, $js, "ui-flow.js mentions $needle");
}
assert_true(!preg_match('/\b(const|let)\s|=>|`/', $js), 'ui-flow.js is plain ES5');
assert_true(!preg_match("/action['\"]?\s*:\s*['\"]advise/", $js) && stripos($js, 'advise') === false, 'ui-flow.js has no advise call');
$css = (string)file_get_contents($root . '/public/css/ui.css');
assert_contains('.ui-flow', $css, 'ui.css styles the band');
assert_true(stripos($css, 'ui-advise') === false, 'ui.css has no advise rules left');
assert_true(stripos((string)file_get_contents($root . '/public/js/ui.js'), 'ui-advise') === false, 'ui.js no longer mentions the advise panel');
$sheets = (string)file_get_contents($root . '/public/js/ui-session-sheets.js');
assert_true(!preg_match("/action['\"]?\s*:\s*['\"]advise/", $sheets), 'ui-session-sheets.js no longer posts the advise action');

test_exit();
