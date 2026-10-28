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

test_exit();
