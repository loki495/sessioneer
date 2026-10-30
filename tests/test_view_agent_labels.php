<?php

declare(strict_types=1);

/**
 * The two view helpers an agent added to the picker/row has to answer for:
 * PageView::AGENT_OPTIONS is the single list the New Session form renders, and
 * agent_label() is what a session page falls back to when the host agent sends
 * no label.
 *
 * Both were hardcoded per-agent chains before Ollama was added, and the old
 * fallback labelled every unrecognised agent "Claude Code" - so the unknown-id
 * case is the regression this exists for, not decoration.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Views\PageView;
use App\Views\SessionRowView;
use App\Views\TranscriptView;

$options = PageView::AGENT_OPTIONS;

assert_equal('Ollama (local)', $options['ollama'] ?? null, 'picker: offers the local Ollama agent');
assert_equal(['claude', 'antigravity', 'opencode', 'codex', 'ollama'], array_keys($options), 'picker: still lists every agent the host can create');

foreach ($options as $id => $label) {
    assert_true(is_string($label) && $label !== '', "picker: {$id} has a label");
    assert_equal($label, PageView::agent_label((string)$id), "picker: agent_label('{$id}') matches the picker");
}

assert_equal('Gemini', PageView::agent_label('gemini'), 'unknown ids become their own name, never another agent\'s');
assert_equal('claude', PageView::agent_label('claude') === 'Claude Code' ? 'claude' : 'wrong', 'the default agent keeps its own label');

// The row badge is the other half: a new agent must not silently fall back to
// the generic slate badge if a colour was chosen for it.
assert_true(SessionRowView::agent_badge_class('ollama') !== SessionRowView::agent_badge_class('nope'), 'row badge: ollama has its own style');

// OpenCode model toggle: a serve session can have no model on its row, so the
// placeholder must carry empty data attributes; session.js then labels it
// "Default model" after the list loads instead of leaving "Loading models…"
// forever.
$ocEmpty = TranscriptView::render_opencode_model_toggle_html(['current_model' => null, 'current_provider' => null]);
assert_contains('data-current-model=""', $ocEmpty, 'opencode model toggle: no current model is an empty data-current-model');
assert_contains('data-current-provider=""', $ocEmpty, 'opencode model toggle: no current provider is an empty data-current-provider');
assert_contains('Loading models&hellip;', $ocEmpty, 'opencode model toggle: starts on the loading placeholder');
$ocCurrent = TranscriptView::render_opencode_model_toggle_html(['current_model' => 'gpt-6.1', 'current_provider' => 'openai']);
assert_contains('data-current-model="gpt-6.1"', $ocCurrent, 'opencode model toggle: a known model is exposed for preselection');
assert_contains('data-current-provider="openai"', $ocCurrent, 'opencode model toggle: its provider too');

test_exit();
