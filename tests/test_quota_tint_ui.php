<?php
declare(strict_types=1);

/**
 * Server rendering of the quota tint: UiView::sessions_html (dashboard cards), fed rows directly. Written from the
 * Contract only. Intent notes:
 *  - exactly `quota_exhausted === true` adds class ui-quota-out and attribute data-quota="out" to the card's opening
 *    tag; false, null, a missing field and look-alike values ("true", 1, "out") add neither;
 *  - the tint is independent of nesting, waiting badges and the card's other classes: they all survive;
 *  - the popup row (public/js/ui-session-sheets.js) is covered in the browser test, which also checks card and popup agree.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use App\Views\UiView;

/** @param array<string, mixed> $extra */
function qu_row(string $name, array $extra = []): array
{
    return $extra + ['name' => $name, 'title' => 'Title ' . $name, 'agent' => 'claude', 'agent_label' => 'Claude Code', 'runtime' => 'headless', 'working' => false, 'activity' => time() - 60, 'workdir' => '/tmp/x'];
}

function qu_tag(string $html, string $name): string
{
    return preg_match('/<article[^>]*data-session="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m) === 1 ? $m[0] : '';
}

function qu_tinted(string $tag): bool
{
    return preg_match('/class="[^"]*\bui-quota-out\b[^"]*"/', $tag) === 1 && str_contains($tag, 'data-quota="out"');
}

function qu_plain(string $tag): bool
{
    return $tag !== '' && !str_contains($tag, 'ui-quota-out') && !str_contains($tag, 'data-quota');
}

echo "Exhausted rows get the class and the attribute\n";
$html = UiView::sessions_html([qu_row('out', ['quota_exhausted' => true]), qu_row('ok', ['quota_exhausted' => false]), qu_row('unk', ['quota_exhausted' => null]), qu_row('old')], 'tok');
assert_true(qu_tinted(qu_tag($html, 'out')), 'true: the card has class ui-quota-out and data-quota="out"');
assert_true(qu_plain(qu_tag($html, 'ok')), 'false: no class, no attribute');
assert_true(qu_plain(qu_tag($html, 'unk')), 'null: no class, no attribute');
assert_true(qu_plain(qu_tag($html, 'old')), 'a row without the field renders plain');
assert_equal(1, substr_count($html, 'ui-quota-out'), 'only the exhausted card carries the class');
assert_equal(1, substr_count($html, 'data-quota='), 'and only that card carries the attribute');

echo "Only a real true tints\n";
foreach (['string true' => 'true', 'int one' => 1, 'string out' => 'out', 'string 1' => '1', 'array' => [true], 'empty string' => ''] as $label => $value) {
    assert_true(qu_plain(qu_tag(UiView::sessions_html([qu_row('x', ['quota_exhausted' => $value])], 'tok'), 'x')), "{$label}: not tinted");
}

echo "The tint keeps everything else on the card\n";
$plain = UiView::sessions_html([qu_row('same', ['starred' => true, 'working' => true])], 'tok');
$tinted = UiView::sessions_html([qu_row('same', ['starred' => true, 'working' => true, 'quota_exhausted' => true])], 'tok');
$strip = static fn (string $h): string => preg_replace('/\s+/', ' ', str_replace([' ui-quota-out', ' data-quota="out"'], '', $h));
assert_equal($strip($plain), $strip($tinted), 'removing the class and the attribute gives the plain card, byte for byte (modulo whitespace)');
$html = UiView::sessions_html([qu_row('p'), qu_row('k', ['launched_by' => 'p', 'quota_exhausted' => true, 'waiting_on' => [['name' => 'p', 'state' => 'live', 'since' => time() - 5]]])], 'tok');
$tag = qu_tag($html, 'k');
assert_true(qu_tinted($tag) && str_contains($tag, 'ui-card-child') && str_contains($tag, 'data-parent="p"'), 'a tinted child keeps ui-card-child and data-parent');
assert_contains('ui-chip-waiting', $html, 'a tinted card keeps its waiting badges');
assert_true(qu_plain(qu_tag($html, 'p')), 'a healthy parent of a tinted child stays plain');

echo "Two accounts side by side\n";
$html = UiView::sessions_html([qu_row('work-1', ['profile' => 'work', 'quota_exhausted' => true]), qu_row('work-2', ['profile' => 'work', 'quota_exhausted' => true]), qu_row('home-1', ['quota_exhausted' => false])], 'tok');
assert_equal([true, true, false], [qu_tinted(qu_tag($html, 'work-1')), qu_tinted(qu_tag($html, 'work-2')), qu_tinted(qu_tag($html, 'home-1'))], 'each card follows its own row field');

echo "The name never leaks the markup\n";
$html = UiView::sessions_html([qu_row('x"y', ['quota_exhausted' => true])], 'tok');
assert_equal(1, substr_count($html, 'data-quota="out"'), 'an odd name does not break the attribute');

echo "ok\n";
test_exit();
