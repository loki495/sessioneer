<?php
declare(strict_types=1);

/**
 * Server rendering of the session hierarchy: UiView::sessions_html (dashboard cards) and UiView::session_page
 * (the detail page), fed rows directly, no agent and no server. Written from the Contract only. Intent notes:
 *  - nesting: a row whose launched_by names another row in the same list renders directly under that row, as a card
 *    with data-parent="NAME" and class ui-card-child; a child whose parent is not in the list stays at the top level
 *    and keeps the existing `spawned by` link; a cycle or a self parent terminates and renders every row once;
 *  - badges: one `waiting on NAME` chip per target (class ui-chip-waiting, link to /session?session=NAME, plus is-ended
 *    when the target is not live), separate from indentation: ancestry without waiting and waiting without ancestry
 *    look different;
 *  - detail page: `Launched by NAME`, `Launched: a, b` and the waiting-on badges as links; nothing when there are none.
 * Not pinned (the Contract does not name a marker): how the depth cap of 3 shows on the card.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use App\Views\UiView;

/** @param array<string, mixed> $extra */
function uh_row(string $name, ?string $launchedBy = null, array $waiting = [], array $extra = []): array
{
    return $extra + ['name' => $name, 'title' => 'Title ' . $name, 'agent' => 'claude', 'agent_label' => 'Claude Code', 'runtime' => 'headless', 'working' => false, 'activity' => time() - 60, 'workdir' => '/tmp/x', 'launched_by' => $launchedBy, 'waiting_on' => $waiting];
}

function uh_wait(string $name, string $state = 'live', ?int $since = null): array
{
    return ['name' => $name, 'state' => $state, 'since' => $since ?? time() - 300];
}

/** @param list<array<string, mixed>> $rows @return list<string> the card names in document order */
function uh_order(array $rows): array
{
    preg_match_all('/<article class="ui-card[^"]*"[^>]*data-session="([^"]*)"/', UiView::sessions_html($rows, 'tok'), $m);

    return array_map('html_entity_decode', $m[1]);
}

/** The opening tag of one card. */
function uh_card_tag(string $html, string $name): string
{
    return preg_match('/<article[^>]*data-session="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m) === 1 ? $m[0] : '';
}

/** The markup of one card, up to the next card. */
function uh_card(string $html, string $name): string
{
    $start = strpos($html, 'data-session="' . $name . '"');
    if ($start === false) {
        return '';
    }
    $next = strpos($html, '<article', $start + 1);

    return substr($html, $start, ($next === false ? strlen($html) : $next) - $start);
}

echo "Nesting: a child sits directly under its parent\n";
$rows = [uh_row('p1'), uh_row('k1', 'p1'), uh_row('k2', 'p1'), uh_row('p2')];
assert_equal(['p1', 'k1', 'k2', 'p2'], uh_order($rows), 'children follow their parent in list order');
$html = UiView::sessions_html($rows, 'tok');
assert_contains('data-parent="p1"', uh_card_tag($html, 'k1'), 'a child card carries data-parent');
assert_contains('ui-card-child', uh_card_tag($html, 'k1'), 'a child card has class ui-card-child');
assert_contains('data-parent="p1"', uh_card_tag($html, 'k2'), 'every child carries its parent');
foreach (['p1', 'p2'] as $top) {
    assert_true(!str_contains(uh_card_tag($html, $top), 'ui-card-child') && !str_contains(uh_card_tag($html, $top), 'data-parent'), "a top level card ({$top}) has neither the class nor the attribute");
}

echo "Nesting: the parent is found wherever it is in the list\n";
assert_equal(['p2', 'p1', 'k1'], uh_order([uh_row('k1', 'p1'), uh_row('p2'), uh_row('p1')]), 'a child listed before its parent is moved under it; the parent keeps its own place');
assert_equal(['x', 'p1', 'k1', 'y'], uh_order([uh_row('x'), uh_row('k1', 'p1'), uh_row('p1'), uh_row('y')]), 'unrelated rows keep their order around the group');

echo "Nesting: grandchildren and depth\n";
assert_equal(['a', 'b', 'c', 'z'], uh_order([uh_row('a'), uh_row('b', 'a'), uh_row('c', 'b'), uh_row('z')]), 'a grandchild follows its parent, before the next top level row');
$html = UiView::sessions_html([uh_row('a'), uh_row('b', 'a'), uh_row('c', 'b')], 'tok');
assert_contains('data-parent="b"', uh_card_tag($html, 'c'), 'a grandchild names its direct parent');
assert_contains('ui-card-child', uh_card_tag($html, 'c'), 'a grandchild is a child card too');
$chain = [uh_row('d0')];
foreach (range(1, 7) as $i) {
    $chain[] = uh_row("d{$i}", 'd' . ($i - 1));
}
$order = uh_order($chain);
assert_equal(array_map(static fn (int $i): string => "d{$i}", range(0, 7)), $order, 'a chain eight deep renders in order, deeper rows flattened rather than dropped');
$html = UiView::sessions_html($chain, 'tok');
foreach (range(4, 7) as $i) {
    assert_contains('data-parent="d' . ($i - 1) . '"', uh_card_tag($html, "d{$i}"), "d{$i} beyond the depth cap still names its real parent");
}
assert_equal(8, substr_count($html, '<article'), 'a deep chain renders each card once');

echo "Nesting: many children\n";
$many = [uh_row('big')];
foreach (range(1, 25) as $i) {
    $many[] = uh_row("kid-{$i}", 'big');
}
$many[] = uh_row('after');
$order = uh_order($many);
assert_equal(27, count($order), 'one parent with 25 children renders 27 cards');
assert_equal('big', $order[0], 'the parent is first');
assert_equal('after', $order[26], 'the next top level row comes after every child');
assert_equal(array_map(static fn (int $i): string => "kid-{$i}", range(1, 25)), array_slice($order, 1, 25), 'children keep their list order');

echo "Nesting: a parent that is not in the list\n";
$rows = [uh_row('lonely', 'gone-parent', [], ['kind' => 'worker', 'parent_session_id' => 'gone-parent'])];
$html = UiView::sessions_html($rows, 'tok');
assert_equal(['lonely'], uh_order($rows), 'the child still renders');
assert_true(!str_contains(uh_card_tag($html, 'lonely'), 'ui-card-child') && !str_contains(uh_card_tag($html, 'lonely'), 'data-parent'), 'at the top level: no child class, no data-parent');
assert_contains('spawned by', uh_card($html, 'lonely'), 'and it keeps the existing `spawned by` line');
assert_contains('href="/session?session=gone-parent"', uh_card($html, 'lonely'), 'with its link to the parent');
$html = UiView::sessions_html([uh_row('loner', null)], 'tok');
assert_true(!str_contains($html, 'ui-card-child') && !str_contains($html, 'data-parent'), 'a row with launched_by null is top level');
$html = UiView::sessions_html([['name' => 'old', 'title' => 'Old row', 'working' => false]], 'tok');
assert_contains('data-session="old"', $html, 'a row without the new fields renders unchanged');
assert_true(!str_contains($html, 'ui-chip-waiting') && !str_contains($html, 'ui-card-child'), 'and shows nothing hierarchical');

echo "Nesting: cycles and self parents terminate\n";
set_time_limit(20);
assert_equal(['a', 'b'], array_values(array_intersect(['a', 'b'], uh_order([uh_row('a', 'b'), uh_row('b', 'a')]))), 'A launched B launched A renders both');
assert_equal(2, count(uh_order([uh_row('a', 'b'), uh_row('b', 'a')])), 'A launched B launched A: each exactly once');
$order = uh_order([uh_row('x'), uh_row('a', 'b'), uh_row('b', 'c'), uh_row('c', 'a'), uh_row('y')]);
sort($order);
assert_equal(['a', 'b', 'c', 'x', 'y'], $order, 'a three row cycle among other rows renders every row once');
assert_equal(['me'], uh_order([uh_row('me', 'me')]), 'a row launched by itself renders once');
$html = UiView::sessions_html([uh_row('me', 'me'), uh_row('other')], 'tok');
assert_equal(2, substr_count($html, '<article'), 'a self parent does not duplicate or drop cards');

echo "Badges: waiting on\n";
$html = UiView::sessions_html([uh_row('w', null, [uh_wait('t-live', 'live'), uh_wait('t-end', 'ended'), uh_wait('t-arc', 'archived'), uh_wait('t-unk', 'unknown')]), uh_row('t-live')], 'tok');
$card = uh_card($html, 'w');
assert_equal(4, substr_count($card, 'ui-chip-waiting'), 'one badge per target');
foreach (['t-live', 't-end', 't-arc', 't-unk'] as $t) {
    assert_true(preg_match('/<a[^>]*class="[^"]*ui-chip-waiting[^"]*"[^>]*href="\/session\?session=' . $t . '"[^>]*>\s*waiting on ' . $t . '\s*<\/a>/', $card) === 1
        || preg_match('/<a[^>]*href="\/session\?session=' . $t . '"[^>]*class="[^"]*ui-chip-waiting[^"]*"[^>]*>\s*waiting on ' . $t . '\s*<\/a>/', $card) === 1, "badge for {$t} is a link to its session reading `waiting on {$t}`");
}
$badge = static fn (string $card, string $t): string => preg_match('/<a[^>]*ui-chip-waiting[^>]*session=' . preg_quote($t, '/') . '"[^>]*>/', $card, $m) === 1 ? $m[0] : '';
assert_true(!str_contains($badge($card, 't-live'), 'is-ended'), 'a live target is not greyed');
foreach (['t-end', 't-arc'] as $t) {
    assert_contains('is-ended', $badge($card, $t), "a {$t} target is greyed with is-ended");
}
assert_equal(0, substr_count(uh_card($html, 't-live'), 'ui-chip-waiting'), 'the target card itself has no badge');

assert_equal(0, substr_count(UiView::sessions_html([uh_row('n', null, [])], 'tok'), 'ui-chip-waiting'), 'an empty waiting_on shows no badge');
assert_equal(0, substr_count(UiView::sessions_html([['name' => 'n', 'title' => 'x', 'waiting_on' => null]], 'tok'), 'ui-chip-waiting'), 'a null waiting_on shows no badge');
$html = UiView::sessions_html([uh_row('w', null, [uh_wait('he said "hi" <b>')])], 'tok');
assert_true(!str_contains($html, '<b>') && !str_contains($html, 'he said "hi"'), 'a target name is escaped in text and attribute');
$html = UiView::sessions_html([uh_row('w', null, [uh_wait('a b&c')])], 'tok');
assert_contains('session=a+b%26c', $html, 'a target name is URL encoded in the link');

echo "Badges are separate from indentation\n";
$rows = [uh_row('par'), uh_row('child-only', 'par'), uh_row('wait-only', null, [uh_wait('par')]), uh_row('both', 'par', [uh_wait('wait-only')])];
$html = UiView::sessions_html($rows, 'tok');
assert_equal(['par', 'child-only', 'both', 'wait-only'], uh_order($rows), 'children group under the parent; a waiting row does not move');
assert_true(str_contains(uh_card_tag($html, 'child-only'), 'ui-card-child') && !str_contains(uh_card($html, 'child-only'), 'ui-chip-waiting'), 'ancestry without waiting: indented, no badge');
assert_true(!str_contains(uh_card_tag($html, 'wait-only'), 'ui-card-child') && !str_contains(uh_card_tag($html, 'wait-only'), 'data-parent') && str_contains(uh_card($html, 'wait-only'), 'ui-chip-waiting'), 'waiting without ancestry: badge, not indented');
assert_true(str_contains(uh_card_tag($html, 'both'), 'ui-card-child') && str_contains(uh_card($html, 'both'), 'ui-chip-waiting'), 'both: indented and badged');
assert_true(!str_contains(uh_card($html, 'child-only'), 'waiting on'), 'a child is never shown as waiting on its parent');
$html = UiView::sessions_html([uh_row('w', null, [uh_wait('x')]), uh_row('x', 'w')], 'tok');
assert_equal(['w', 'x'], array_map('html_entity_decode', (function (string $h): array { preg_match_all('/data-session="([^"]*)"/', $h, $m); return array_values(array_unique($m[1])); })($html)), 'waiting on your own child and launching it are independent');

echo "Empty list\n";
assert_contains('No sessions yet', UiView::sessions_html([], 'tok'), 'an empty list still shows the empty state');

echo "Detail page\n";
$page = static fn (array $detail): string => UiView::session_page(['sessionName' => 'cc-x', 'csrfToken' => 'tok', 'found' => true, 'detail' => $detail + ['ok' => true, 'name' => 'cc-x', 'runtime' => 'headless', 'agent' => 'claude', 'status' => 'idle'], 'jumpLine' => null]);
$html = $page(['launched_by' => 'cc-parent', 'launched' => ['cc-kid-1', 'cc-kid-2'], 'waiting_on' => [uh_wait('cc-w1'), uh_wait('cc-w2', 'ended')]]);
assert_true(preg_match('/Launched by\s*<a[^>]*href="\/session\?session=cc-parent"[^>]*>cc-parent<\/a>/', $html) === 1, 'page: `Launched by NAME` links to the parent');
assert_true(preg_match('/Launched:\s*<a[^>]*href="\/session\?session=cc-kid-1"[^>]*>cc-kid-1<\/a>,\s*<a[^>]*href="\/session\?session=cc-kid-2"[^>]*>cc-kid-2<\/a>/', $html) === 1, 'page: `Launched: a, b` lists the children as links');
assert_equal(2, preg_match_all('/<a[^>]*ui-chip-waiting[^>]*>/', $html), 'page: one waiting badge per target');
assert_true(preg_match('/<a[^>]*href="\/session\?session=cc-w1"[^>]*>\s*waiting on cc-w1\s*<\/a>/', $html) === 1, 'page: a badge links to the target session');
assert_true(preg_match('/<a[^>]*ui-chip-waiting[^>]*is-ended[^>]*session=cc-w2|<a[^>]*is-ended[^>]*ui-chip-waiting[^>]*session=cc-w2|<a[^>]*session=cc-w2[^>]*(?:ui-chip-waiting[^>]*is-ended|is-ended[^>]*ui-chip-waiting)/', $html) === 1, 'page: an ended target badge is greyed');

$plain = $page([]);
assert_true(!str_contains($plain, 'Launched by') && !preg_match('/Launched:/', $plain), 'page: no hierarchy fields, no hierarchy text');
assert_equal(0, substr_count($plain, 'ui-chip-waiting'), 'page: and no badges');
$empty = $page(['launched_by' => null, 'launched' => [], 'waiting_on' => []]);
assert_true(!str_contains($empty, 'Launched by') && !preg_match('/Launched:/', $empty) && !str_contains($empty, 'ui-chip-waiting'), 'page: null, empty and empty never show a heading with nothing under it');
$onlyKids = $page(['launched_by' => null, 'launched' => ['cc-kid-1'], 'waiting_on' => []]);
assert_true(preg_match('/Launched:/', $onlyKids) === 1 && !str_contains($onlyKids, 'Launched by'), 'page: children without a parent: Launched, not Launched by');
$onlyWait = $page(['launched_by' => null, 'launched' => [], 'waiting_on' => [uh_wait('cc-w1')]]);
assert_true(!str_contains($onlyWait, 'Launched by') && !preg_match('/Launched:/', $onlyWait) && str_contains($onlyWait, 'ui-chip-waiting'), 'page: waiting without ancestry shows only the badge');
$onlyParent = $page(['launched_by' => 'cc-parent', 'launched' => [], 'waiting_on' => []]);
assert_true(str_contains($onlyParent, 'Launched by') && !str_contains($onlyParent, 'ui-chip-waiting') && !preg_match('/Launched:/', $onlyParent), 'page: ancestry without waiting shows only Launched by');
$hostile = $page(['launched_by' => '"><script>x</script>', 'launched' => ['<i>k</i>'], 'waiting_on' => [uh_wait('"><img src=x>')]]);
assert_true(!str_contains($hostile, '"><script>x</script>') && !str_contains($hostile, '<i>k</i>') && !str_contains($hostile, '"><img src=x>'), 'page: names are escaped');

echo "Ended badges carry a text cue\n";
$html = UiView::sessions_html([uh_row('w', null, [uh_wait('t-live', 'live'), uh_wait('t-end', 'ended')])], 'tok');
$card = uh_card($html, 'w');
$tag = static fn (string $card, string $t): string => preg_match('/<a[^>]*ui-chip-waiting[^>]*session=' . preg_quote($t, '/') . '"[^>]*>/', $card, $m) === 1 ? $m[0] : '';
assert_true(preg_match('/title="[^"]*ended[^"]*"/', $tag($card, 't-end')) === 1, 'an ended badge has a title containing `ended`');
assert_true(preg_match('/aria-label="[^"]*ended[^"]*"/', $tag($card, 't-end')) === 1, 'an ended badge has an aria-label containing `ended`');
assert_true(preg_match('/(?:title|aria-label)="[^"]*t-end[^"]*"/', $tag($card, 't-end')) === 1, 'and the cue names the target');
assert_true(!str_contains($tag($card, 't-live'), 'ended') && $tag($card, 't-live') !== '', 'a live badge has no `ended` text');
$html = $page(['waiting_on' => [uh_wait('cc-w1'), uh_wait('cc-w2', 'ended')]]);
$tagP = static fn (string $t): string => preg_match('/<a[^>]*ui-chip-waiting[^>]*session=' . preg_quote($t, '/') . '"[^>]*>/', $html, $m) === 1 ? $m[0] : '';
assert_true(str_contains($tagP('cc-w2'), 'is-ended') && preg_match('/title="[^"]*ended/', $tagP('cc-w2')) === 1 && preg_match('/aria-label="[^"]*ended/', $tagP('cc-w2')) === 1, 'page: an ended target carries is-ended, a title and an aria-label with `ended`');
assert_true($tagP('cc-w1') !== '' && !str_contains($tagP('cc-w1'), 'ended'), 'page: a live target has no `ended` text or class');

test_exit();
