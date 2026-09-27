<?php
declare(strict_types=1);

/**
 * The session page's turn-error card (TranscriptView::render_turn_error_html())
 * names the agent whose turn failed. It used to say "Antigravity did not reply"
 * for every agent, so a Claude headless process that died or hit its usage
 * limit showed an Antigravity message.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Views\TranscriptView;

$quota = 'Usage limit reached - resets 3am';

$claude = TranscriptView::render_turn_error_html(['last_turn_error' => $quota], 'Claude Code');
assert_contains('Claude Code reported a problem', $claude, 'a Claude session names Claude Code');
assert_contains($quota, $claude, 'and shows the error text');
assert_true(!str_contains($claude, 'Antigravity'), 'and never mentions Antigravity');

$agy = TranscriptView::render_turn_error_html(['last_turn_error' => $quota], 'Antigravity');
assert_contains('Antigravity reported a problem', $agy, 'an Antigravity session names Antigravity');

$default = TranscriptView::render_turn_error_html(['last_turn_error' => $quota]);
assert_contains('Claude Code reported a problem', $default, 'the default label is Claude Code, not Antigravity');

$hostile = TranscriptView::render_turn_error_html(['last_turn_error' => '<script>x</script>'], '<b>Evil</b>');
assert_true(!str_contains($hostile, '<script>') && !str_contains($hostile, '<b>Evil'), 'error text and label are HTML-escaped');

assert_equal('', TranscriptView::render_turn_error_html(['last_turn_error' => null], 'Claude Code'), 'no error renders nothing');
assert_equal('', TranscriptView::render_turn_error_html(['last_turn_error' => ''], 'Claude Code'), 'an empty error renders nothing');
assert_equal('', TranscriptView::render_turn_error_html([], 'Claude Code'), 'a detail with no error field renders nothing');
assert_equal('', TranscriptView::render_turn_error_html(['last_turn_error' => ['not', 'a string']], 'Claude Code'), 'a non-string error renders nothing');

test_exit();
