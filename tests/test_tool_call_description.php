<?php
declare(strict_types=1);

/**
 * The tool-call entry's description line (TranscriptView::
 * render_tool_call_entry_html()): a call's own `description` shows on its
 * own line above the summary, and nothing extra renders when it is missing,
 * blank, or already the summary text. test_session_replay_browser.php covers
 * session.js's mirror of the same rule.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Views\TranscriptView;

/** @param array<string, mixed> $callBlock */
function tool_call_summary_html(array $callBlock): string
{
    $html = TranscriptView::render_transcript_entries_html([
        ['type' => 'assistant', 'role' => 'assistant', 'timestamp' => '2026-10-06T12:00:00Z', 'line' => 1, 'blocks' => [$callBlock + ['kind' => 'tool_use']]],
        ['type' => 'user', 'role' => 'user', 'timestamp' => '2026-10-06T12:00:01Z', 'line' => 2, 'blocks' => [['kind' => 'tool_result', 'text' => 'ok']]],
    ], 'cc-test', false, '/home/user/project');

    return preg_match('/<summary[^>]*>(.*?)<\/summary>/s', $html, $m) === 1 ? $m[1] : '';
}

$bash = ['text' => 'tool: Bash - command: npm test', 'tool_name' => 'Bash', 'command' => 'npm test'];

assert_equal(
    '<span class="tool-call-description text-slate-300">Run the unit tests</span><span class="block truncate">Ran npm test</span>',
    tool_call_summary_html($bash + ['description' => 'Run the unit tests']),
    'Bash with a description: description on the first line, "Ran <command>" below it'
);
assert_equal(
    '<span class="tool-call-description text-slate-300">Check &lt;b&gt; &amp; escape</span><span class="block truncate">Ran npm test</span>',
    tool_call_summary_html($bash + ['description' => 'Check <b> & escape']),
    'description is HTML-escaped, never rendered raw'
);
assert_equal('Ran npm test', tool_call_summary_html($bash), 'Bash with no description: summary only, no description line');
assert_equal('Ran npm test', tool_call_summary_html($bash + ['description' => '']), 'Bash with an empty description: no empty description line');
assert_equal('Ran npm test', tool_call_summary_html($bash + ['description' => "  \n"]), 'Bash with a whitespace-only description: no blank description line');
assert_equal(
    'Fetch the release page',
    tool_call_summary_html(['text' => 'tool: WebFetch - url: https://example.com', 'tool_name' => 'WebFetch', 'description' => 'Fetch the release page']),
    'a tool whose summary already IS its description: shown once, not duplicated'
);
assert_equal(
    'Read src/app.php',
    tool_call_summary_html(['text' => 'Read(src/app.php)', 'tool_name' => 'Read', 'file_path' => '/home/user/project/src/app.php']),
    'Read with no description: unchanged "Read <relative path>" summary'
);

test_exit();
