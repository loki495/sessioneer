<?php

declare(strict_types=1);

/**
 * Full Claude model names for the model dropdowns: SelectableModel's id
 * parsing, ClaudeModelCatalog's "newest version seen per family" store, the
 * agent's list_models answer for the New Session form, and the session page's
 * option rendering. Store-level, no tmux and no socket.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use App\Views\TranscriptView;
use HostAgent\Services\ClaudeModelCatalog;
use HostAgent\Services\Config;
use HostAgent\Services\SelectableModel;
use HostAgent\Stores\GlobalStateStore;

$realPushSqliteFile = Config::push_sqlite_path();
$fixtureDir = sys_get_temp_dir() . '/sessioneer-test-model-catalog-' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0700, true);
putenv("PUSH_SQLITE_FILE={$fixtureDir}/push.sqlite");

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

register_shutdown_function(static function () use ($fixtureDir): void {
    exec('rm -rf ' . escapeshellarg($fixtureDir));
});

echo "SelectableModel::display_name\n";
assert_equal('Opus 5.5', SelectableModel::display_name('claude-opus-5-5'), 'family plus two version parts');
assert_equal('Sonnet 5', SelectableModel::display_name('claude-sonnet-5'), 'a single version part');
assert_equal('Haiku 4.5', SelectableModel::display_name('claude-haiku-4-5-20251001'), 'a date pin is dropped');
assert_equal('Fable 5.1', SelectableModel::display_name('claude-fable-5-1'), 'another family');
assert_equal('Opus 4.1', SelectableModel::display_name('claude-opus-4-1[1m]'), 'a context suffix is dropped');
assert_equal('Opus', SelectableModel::display_name('claude-opus'), 'no version leaves the family name');
assert_equal(null, SelectableModel::display_name('gpt-5'), 'a non-Claude id is not guessed');
assert_equal(null, SelectableModel::display_name(''), 'an empty id has no name');
assert_equal(null, SelectableModel::display_name('claude-3-5-sonnet-20241022'), 'an unrecognised id layout is not guessed');

echo "SelectableModel::version_is_newer\n";
assert_true(SelectableModel::version_is_newer([5, 1], [5]), '5.1 is newer than 5');
assert_true(!SelectableModel::version_is_newer([5], [5, 1]), '5 is not newer than 5.1');
assert_true(!SelectableModel::version_is_newer([4, 5], [4, 5]), 'the same version is not newer');
assert_true(SelectableModel::version_is_newer([5], [4, 9]), 'a higher major wins over a higher minor');

echo "ClaudeModelCatalog\n";
$plain = ['default' => 'Default', 'sonnet' => 'Sonnet', 'fable' => 'Fable', 'opus' => 'Opus', 'haiku' => 'Haiku'];
assert_equal($plain, ClaudeModelCatalog::labels(), 'with nothing seen, every row keeps its plain name');

ClaudeModelCatalog::record('claude-opus-5-5');
ClaudeModelCatalog::record('claude-sonnet-4-5-20250929');
assert_equal(['default' => 'Default', 'sonnet' => 'Sonnet 4.5', 'fable' => 'Fable', 'opus' => 'Opus 5.5', 'haiku' => 'Haiku'], ClaudeModelCatalog::labels(), 'a seen family shows its full name, an unseen one stays plain');

ClaudeModelCatalog::record('claude-sonnet-5');
assert_equal('Sonnet 5', ClaudeModelCatalog::labels()['sonnet'], 'a newer version replaces the older one');
ClaudeModelCatalog::record('claude-sonnet-4-5-20250929');
assert_equal('Sonnet 5', ClaudeModelCatalog::labels()['sonnet'], 'an older pin seen later does not downgrade the label');

$before = GlobalStateStore::read('claude_models_seen');
ClaudeModelCatalog::record('gpt-5');
ClaudeModelCatalog::record('sonnet');
ClaudeModelCatalog::record('');
assert_equal($before, GlobalStateStore::read('claude_models_seen'), 'ids that are not Claude model ids are ignored');

GlobalStateStore::write('claude_models_seen', ['models' => ['opus' => 12345, 'haiku' => 'claude-haiku-4-5', 7 => 'claude-opus-9']]);
assert_equal(['default' => 'Default', 'sonnet' => 'Sonnet', 'fable' => 'Fable', 'opus' => 'Opus', 'haiku' => 'Haiku 4.5'], ClaudeModelCatalog::labels(), 'malformed stored entries are skipped, good ones kept');
GlobalStateStore::write('claude_models_seen', ['models' => 'not an array']);
assert_equal($plain, ClaudeModelCatalog::labels(), 'a malformed store falls back to plain names');
GlobalStateStore::write('claude_models_seen', ['models' => ['opus' => 'claude-opus-4-1']]);
ClaudeModelCatalog::record('claude-opus-5-5');
assert_equal('Opus 5.5', ClaudeModelCatalog::labels()['opus'], 'recording still works on top of previously stored data');

echo "list_models (claude)\n";
$listed = sessioneer_list_models('claude');
assert_true($listed['ok'] === true, 'the claude model list is answered');
assert_equal(['sonnet', 'fable', 'opus', 'haiku'], array_column($listed['models'], 'id'), 'every family row except Default, in picker order');
assert_equal('Opus 5.5', array_column($listed['models'], 'name', 'id')['opus'], 'each row carries its full name');

echo "TranscriptView::model_options\n";
$rendered = TranscriptView::model_options(['model_labels' => ['sonnet' => 'Sonnet 5', 'opus' => 'Opus 5.5']]);
assert_equal(['default' => 'Default', 'sonnet' => 'Sonnet 5', 'fable' => 'Fable', 'opus' => 'Opus 5.5', 'haiku' => 'Haiku'], $rendered, 'labels override, missing ones fall back to the plain name');
assert_equal(array_keys(TranscriptView::MODEL_OPTIONS), array_keys($rendered), 'the option keys stay exactly the picker keys, whatever the labels say');
$hostile = TranscriptView::model_options(['model_labels' => ['sonnet' => ['x'], 'opus' => '', 'evil' => 'Injected', 'fable' => 5]]);
assert_equal(TranscriptView::MODEL_OPTIONS, $hostile, 'non-string, empty and unknown-key labels are ignored');
assert_equal(TranscriptView::MODEL_OPTIONS, TranscriptView::model_options([]), 'a detail with no labels renders the plain names');
assert_equal(TranscriptView::MODEL_OPTIONS, TranscriptView::model_options(['model_labels' => 'nope']), 'a non-array labels field renders the plain names');
assert_contains('Opus 5.5', TranscriptView::render_model_toggle_html(['current_model' => 'opus', 'model_labels' => ['opus' => 'Opus 5.5']]), 'the rendered dropdown shows the full name');
assert_true(!str_contains(TranscriptView::render_model_toggle_html(['current_model' => 'opus', 'model_labels' => ['opus' => '<b>x</b>']]), '<b>x</b>'), 'a label is HTML-escaped');

test_exit();
