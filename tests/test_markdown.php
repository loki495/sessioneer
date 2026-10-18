<?php
declare(strict_types=1);

/**
 * Pure unit tests for App\Views\MarkdownRenderer - no tmux, no socket, no
 * fixtures, just string in/HTML out. See that class's own docblock for the
 * design rationale (a deliberately small parser: bold/italic/inline-code/
 * fenced-code-blocks/lists only, everything else stays exactly the single
 * flowing <p class="whitespace-pre-wrap"> transcript 'text' blocks already
 * rendered before markdown parsing existed).
 *
 * public/js/session.js's renderMarkdown() is the poll-time JS mirror of
 * this same class - verified byte-for-byte identical against every case
 * below during development (not re-checked here since there's no JS test
 * runner in this suite; test_ui_smoke.php's headless-browser checks cover
 * "no uncaught JS errors" on a real page load, not per-case parity).
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Views\MarkdownRenderer;

// --- plain text, no markdown syntax at all - renders as exactly the same
// single <p class="whitespace-pre-wrap"> structure the 'text' block kind
// used before markdown parsing existed, just with html-escaping applied
// (the common case: most assistant/user messages have no markdown in them). ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">Just a plain reply, nothing special.</p>',
    MarkdownRenderer::render_html('Just a plain reply, nothing special.'),
    'render_html: plain text with no markdown syntax renders as one plain <p>'
);
assert_equal('', MarkdownRenderer::render_html(''), 'render_html: empty text renders as empty string');

// --- XSS: a real HTML tag in the source text is always escaped, never
// passed through raw - the only raw HTML this renderer ever emits is the
// fixed tags it explicitly writes itself. ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    MarkdownRenderer::render_html('<script>alert(1)</script>'),
    'render_html: a real HTML tag in the source text is escaped, never rendered raw'
);

// --- bold/italic - **/__ for bold, */_ for italic, nested combinations work. ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">a <strong>bold</strong> word</p>',
    MarkdownRenderer::render_html('a **bold** word'),
    'render_html: **bold**'
);
assert_equal(
    '<p class="whitespace-pre-wrap break-words">a <strong>bold</strong> word</p>',
    MarkdownRenderer::render_html('a __bold__ word'),
    'render_html: __bold__'
);
assert_equal(
    '<p class="whitespace-pre-wrap break-words">a <em>italic</em> word</p>',
    MarkdownRenderer::render_html('a *italic* word'),
    'render_html: *italic*'
);
assert_equal(
    '<p class="whitespace-pre-wrap break-words"><strong>bold with <em>nested</em> inside</strong></p>',
    MarkdownRenderer::render_html('**bold with *nested* inside**'),
    'render_html: italic nested inside bold'
);
assert_equal(
    '<p class="whitespace-pre-wrap break-words">This * is not italic because unmatched</p>',
    MarkdownRenderer::render_html('This * is not italic because unmatched'),
    'render_html: an unmatched single asterisk is left as a literal character, not treated as markup'
);

// --- underscore italics need a word-boundary guard - without it, a real
// identifier like "my_variable_name" would have its middle underscores
// misread as italic markup, a well-known naive-markdown-regex gotcha. ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">my_variable_name stays intact</p>',
    MarkdownRenderer::render_html('my_variable_name stays intact'),
    'render_html: snake_case identifiers are never misread as underscore-italic'
);

// --- inline code spans - guarded via a placeholder token so their content
// can never be reinterpreted as bold/italic syntax by a later pass. ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">run <code class="px-1 py-0.5 rounded bg-slate-800 text-slate-200 text-[0.85em] font-mono">**not bold**</code> literally</p>',
    MarkdownRenderer::render_html('run `**not bold**` literally'),
    'render_html: markdown syntax inside a code span is never processed as markup'
);

// --- unordered/ordered lists - only recognized as a list when every
// non-empty line in the block matches the same marker type; rendered as a
// real <ul>/<ol>, not just a bullet-prefixed line. ---
assert_equal(
    '<ul class="list-disc pl-5 space-y-0.5"><li class="whitespace-pre-wrap break-words">a</li><li class="whitespace-pre-wrap break-words">b</li></ul>',
    MarkdownRenderer::render_html("- a\n- b"),
    'render_html: unordered list'
);
assert_equal(
    '<ol class="list-decimal pl-5 space-y-0.5"><li class="whitespace-pre-wrap break-words">first</li><li class="whitespace-pre-wrap break-words">second</li></ol>',
    MarkdownRenderer::render_html("1. first\n2. second"),
    'render_html: ordered list starting at 1 gets no start attribute (browser default numbering already matches)'
);
assert_equal(
    '<ol class="list-decimal pl-5 space-y-0.5" start="3"><li class="whitespace-pre-wrap break-words">third</li><li class="whitespace-pre-wrap break-words">fourth</li></ol>',
    MarkdownRenderer::render_html("3. third\n4. fourth"),
    'render_html: an ordered list not starting at 1 gets a start attribute so numbering matches the source'
);

// --- fenced code blocks - rendered verbatim (no inline bold/italic/code-span
// processing inside), backticks inside the fence are never mistaken for an
// inline code span. ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">before</p><pre class="whitespace-pre overflow-auto rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-300 my-1"><code>foo `bar` baz</code></pre><p class="whitespace-pre-wrap break-words">after</p>',
    MarkdownRenderer::render_html("before\n```\nfoo `bar` baz\n```\nafter"),
    'render_html: a fenced code block renders verbatim, backticks inside it never treated as an inline code span'
);
assert_equal(
    '<pre class="whitespace-pre overflow-auto rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-300 my-1"><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>',
    MarkdownRenderer::render_html("```\n<script>alert(1)</script>\n```"),
    'render_html: HTML inside a fenced code block is escaped, never rendered raw'
);

// --- a real substring that could collide with the internal placeholder
// scheme if it used a plain-text delimiter instead of a NUL-byte one
// (an earlier plain-space-delimited
// version of the JS mirror had exactly this collision). ---
assert_equal(
    '<p class="whitespace-pre-wrap break-words">the IC5 pin should not be corrupted</p>',
    MarkdownRenderer::render_html('the IC5 pin should not be corrupted'),
    'render_html: ordinary text resembling the internal placeholder token format is never misinterpreted'
);

test_exit();
