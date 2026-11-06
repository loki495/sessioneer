<?php

declare(strict_types=1);

/**
 * UiView::AGENT_OPTIONS is the single list the New Session form renders; a new agent must show up here.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Views\UiView;

$options = UiView::AGENT_OPTIONS;

assert_equal('Ollama (local)', $options['ollama'] ?? null, 'picker: offers the local Ollama agent');
assert_equal(['claude', 'antigravity', 'opencode', 'codex', 'ollama'], array_keys($options), 'picker: still lists every agent the host can create');

foreach ($options as $id => $label) {
    assert_true(is_string($label) && $label !== '', "picker: {$id} has a label");
}

test_exit();
