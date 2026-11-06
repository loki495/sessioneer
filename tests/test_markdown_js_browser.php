<?php
declare(strict_types=1);

/**
 * public/js/markdown.js (the transcript renderer's inline markdown) in a real
 * browser engine: plain text, HTML escaping, bold/italic (both marker styles,
 * nesting, the unmatched-asterisk and underscore-word guards), code spans, both
 * list types, fenced blocks and the NUL-placeholder collision case. The
 * expected strings are the output of the PHP renderer this file replaced, so
 * the shipped format is unchanged.
 *
 * Skips (exit 0) when no Chrome is on the host. *_browser.php so tests/run.sh's
 * --no-browser/--browser filtering applies.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/cdp.php';

$markdownJs = (string)file_get_contents(dirname(__DIR__) . '/public/js/markdown.js');
// The page scripts define this global before markdown.js is used.
$escapeHtmlJs = "function escapeHtml(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;'); }";

$cases = [
    ['Just a plain reply, nothing special.', '<p class="whitespace-pre-wrap break-words">Just a plain reply, nothing special.</p>'],
    ['', ''],
    ['<script>alert(1)</script>', '<p class="whitespace-pre-wrap break-words">&lt;script&gt;alert(1)&lt;/script&gt;</p>'],
    ['a **bold** word', '<p class="whitespace-pre-wrap break-words">a <strong>bold</strong> word</p>'],
    ['a __bold__ word', '<p class="whitespace-pre-wrap break-words">a <strong>bold</strong> word</p>'],
    ['a *italic* word', '<p class="whitespace-pre-wrap break-words">a <em>italic</em> word</p>'],
    ['**bold with *nested* inside**', '<p class="whitespace-pre-wrap break-words"><strong>bold with <em>nested</em> inside</strong></p>'],
    ['This * is not italic because unmatched', '<p class="whitespace-pre-wrap break-words">This * is not italic because unmatched</p>'],
    ['my_variable_name stays intact', '<p class="whitespace-pre-wrap break-words">my_variable_name stays intact</p>'],
    ['run `**not bold**` literally', '<p class="whitespace-pre-wrap break-words">run <code class="px-1 py-0.5 rounded bg-slate-800 text-slate-200 text-[0.85em] font-mono">**not bold**</code> literally</p>'],
    ["- a\n- b", '<ul class="list-disc pl-5 space-y-0.5"><li class="whitespace-pre-wrap break-words">a</li><li class="whitespace-pre-wrap break-words">b</li></ul>'],
    ["1. first\n2. second", '<ol class="list-decimal pl-5 space-y-0.5"><li class="whitespace-pre-wrap break-words">first</li><li class="whitespace-pre-wrap break-words">second</li></ol>'],
    ["3. third\n4. fourth", '<ol class="list-decimal pl-5 space-y-0.5" start="3"><li class="whitespace-pre-wrap break-words">third</li><li class="whitespace-pre-wrap break-words">fourth</li></ol>'],
    ["before\n```\nfoo `bar` baz\n```\nafter", '<p class="whitespace-pre-wrap break-words">before</p><pre class="whitespace-pre overflow-auto rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-300 my-1"><code>foo `bar` baz</code></pre><p class="whitespace-pre-wrap break-words">after</p>'],
    ["```\n<script>alert(1)</script>\n```", '<pre class="whitespace-pre overflow-auto rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-300 my-1"><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>'],
    ['the IC5 pin should not be corrupted', '<p class="whitespace-pre-wrap break-words">the IC5 pin should not be corrupted</p>'],
];

$browser = null;
$page = null;

try {
    $chromeBin = cdp_find_chrome();

    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
    }

    if ($page !== null) {
        $setupOk = cdp_evaluate($page, $escapeHtmlJs . "\n" . $markdownJs . "\ntypeof renderMarkdown === 'function';");
        assert_true($setupOk === true, 'markdown.js evaluates cleanly and defines renderMarkdown()');

        if ($setupOk === true) {
            foreach ($cases as $i => [$input, $expected]) {
                assert_equal($expected, cdp_evaluate($page, 'renderMarkdown(' . json_encode($input) . ');'), "renderMarkdown: case #{$i} (" . substr(json_encode($input), 0, 40) . ')');
            }
        }
    }
} finally {
    if ($page !== null) {
        cdp_close_page($page);
    }

    if ($browser !== null) {
        cdp_shutdown($browser);
    }
}

test_exit();
