<?php
declare(strict_types=1);

/**
 * Cross-language parity check for App\Views\MarkdownRenderer::render_html()
 * (PHP) against its documented JS mirror, session.js's renderMarkdown() -
 * see MarkdownRenderer.php's own docblock ("Mirrored line-for-line...keep
 * both in sync") and test_markdown.php's own docblock, which candidly says
 * this exact gap existed: "verified byte-for-byte identical against every
 * case below during development (not re-checked here since there's no JS
 * test runner in this suite)". Flagged by a readability audit
 * as a real risk - nothing previously caught a future PHP-side change
 * (e.g. adding blockquote support) shipping with no matching JS update.
 *
 * Reuses test_markdown.php's own curated input strings (not reinvented
 * here) so both files stay aligned on what "the feature set" means.
 *
 * renderMarkdown() and its mdXxx() helpers live in their own file,
 * public/js/markdown.js (extracted out of session.js's page-scope IIFE
 * specifically because they're pure and have no DOM/session
 * dependencies - see that file's own header comment), as plain top-level
 * functions, same convention as common.js. This loads that file's real
 * shipped source verbatim (plus escapeHtml() from common.js, extracted by
 * marker since that file is not just this one function) and evals it
 * standalone in a blank page via CDP - still the REAL shipped code, just
 * run in isolation rather than via a full page load.
 *
 * Best-effort like test_session_replay_browser.php/test_ui_smoke.php's own
 * headless-Chrome tier: SKIPs (exit 0) rather than failing the suite if no
 * usable Chrome is on this host. *_browser.php filename so tests/run.sh's
 * --no-browser/--browser filtering picks it up automatically.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/cdp.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Views\MarkdownRenderer;

/**
 * Returns the substring from (and including) $startMarker through (and
 * including) $endMarker - throws if either isn't found, so a future rename
 * of the functions being extracted fails this test loudly and specifically,
 * rather than silently comparing against stale/empty JS.
 */
function extract_js_block(string $content, string $startMarker, string $endMarker): string
{
    $start = strpos($content, $startMarker);

    if ($start === false) {
        throw new \RuntimeException("extract_js_block: start marker not found: " . substr($startMarker, 0, 60));
    }

    $endMarkerPos = strpos($content, $endMarker, $start);

    if ($endMarkerPos === false) {
        throw new \RuntimeException("extract_js_block: end marker not found (after start match): " . substr($endMarker, 0, 60));
    }

    return substr($content, $start, $endMarkerPos + strlen($endMarker) - $start);
}

$commonJs = (string)file_get_contents(dirname(__DIR__) . '/public/js/common.js');
$markdownJs = (string)file_get_contents(dirname(__DIR__) . '/public/js/markdown.js');

// escapeHtml() (common.js) - a plain top-level function, needed by
// mdRenderProse()/mdRenderList()/mdRenderCodeBlock() below.
$escapeHtmlJs = extract_js_block($commonJs, 'function escapeHtml(text) {', "\n}\n");

// markdown.js is now already the isolated markdown block on its own -
// no marker extraction needed, the whole file IS renderMarkdown() and its
// helpers (plus its own leading file-doc-comment, harmless to eval too).
$combinedJs = $escapeHtmlJs . "\n" . $markdownJs;

// --- test inputs: reused verbatim from test_markdown.php's own curated
// set, covering plain text, XSS-escaping, bold/italic (both marker styles,
// nesting, the underscore word-boundary guard), inline code spans, both
// list types (including a non-1 ordered-list start), fenced code blocks
// (plain and with HTML needing escaping), and the NUL-placeholder
// collision-safety case. ---
$inputs = [
    'Just a plain reply, nothing special.',
    '',
    '<script>alert(1)</script>',
    'a **bold** word',
    'a __bold__ word',
    'a *italic* word',
    '**bold with *nested* inside**',
    'This * is not italic because unmatched',
    'my_variable_name stays intact',
    'run `**not bold**` literally',
    "- a\n- b",
    "1. first\n2. second",
    "3. third\n4. fourth",
    "before\n```\nfoo `bar` baz\n```\nafter",
    "```\n<script>alert(1)</script>\n```",
    'the IC5 pin should not be corrupted',
];

$browser = null;
$page = null;

try {
    $chromeBin = cdp_find_chrome();

    if ($chromeBin === null) {
        echo "  SKIP: no headless browser found (checked google-chrome-stable/google-chrome/chromium/chromium-browser) - this test file has nothing else to check\n";
    } else {
        $browser = cdp_launch($chromeBin);
        $page = $browser !== null ? cdp_open_page($browser) : null;
    }

    if ($page !== null) {
        $setupOk = cdp_evaluate($page, $combinedJs . "\ntypeof renderMarkdown === 'function';");
        assert_true($setupOk === true, 'markdown parity: the extracted JS block evaluates cleanly and defines renderMarkdown() as a function - if this fails, the extraction markers in this test file likely need updating to match a refactor in session.js/common.js');

        if ($setupOk === true) {
            foreach ($inputs as $i => $input) {
                $phpHtml = MarkdownRenderer::render_html($input);
                $jsHtml = cdp_evaluate($page, 'renderMarkdown(' . json_encode($input) . ');');

                assert_equal($phpHtml, $jsHtml, "markdown parity: PHP MarkdownRenderer::render_html() and JS renderMarkdown() produce identical output for input #{$i} (" . json_encode(mb_strimwidth($input, 0, 40, '…')) . ')');
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
