<?php

declare(strict_types=1);

namespace App\Views;

/**
 * A deliberately small markdown-to-HTML renderer for 'text'-kind transcript
 * blocks (assistant replies and real user-typed messages both share this
 * one rendering path - see TranscriptView::render_transcript_block()) -
 * "a simple markdown parser" for bold/lists/code
 * spans, not a full CommonMark implementation. Deliberately does NOT touch
 * headings, links, images, blockquotes, or tables - out of scope for what
 * was actually asked, and each adds its own escaping/edge-case surface.
 *
 * Mirrored line-for-line in public/js/session.js's renderMarkdown() (poll-
 * time path) - keep both in sync when touching either, same convention as
 * every other PHP-render/JS-poll-mirror pair in this codebase (see
 * render_transcript_block()/renderBlock()).
 *
 * Design constraint that shaped the whole algorithm: TranscriptView's
 * existing 'text' block already renders as ONE <p class="whitespace-pre-
 * wrap"> for the entire message (blank lines and single line breaks both
 * preserved visually via that one CSS property, no per-paragraph <p> tags
 * needed). Changing that wholesale (real per-paragraph <p> splitting) would
 * touch the visual layout of every plain-prose message in the app, which is
 * the overwhelming majority of transcript content. So this renderer only
 * ever pulls two things OUT into their own elements - fenced code blocks
 * and runs of list-item lines - and leaves every other line flowing through
 * exactly the same single-<p>-with-pre-wrap treatment as before, just with
 * inline bold/italic/code-span substitutions applied. A message with no
 * lists and no code fences (most of them) renders identically in structure
 * to the pre-markdown version, only the inline styling is new.
 *
 * XSS note: every raw text run is passed through htmlspecialchars() BEFORE
 * any inline substitution runs, and inline code spans are pulled out to
 * placeholder tokens before the bold/italic regexes run (then restored
 * after) so their content can never be reinterpreted as markdown syntax by
 * a later pass. The only new raw HTML tags this ever emits are the fixed
 * set it explicitly writes itself (<p>, <ul>, <ol>, <li>, <pre>, <code>,
 * <strong>, <em>) - nothing from the input text ever reaches the output
 * unescaped.
 */
class MarkdownRenderer
{
    private const CODE_BLOCK_TOKEN = "\x00CB%d\x00";
    private const INLINE_CODE_TOKEN = "\x00IC%d\x00";

    public static function render_html(string $text): string
    {
        [$text, $codeBlocks] = self::extract_fenced_code_blocks($text);
        $segments = self::split_into_segments($text);

        $html = '';

        foreach ($segments as $segment) {
            $html .= match ($segment['type']) {
                'code' => self::render_code_block($codeBlocks[$segment['index']] ?? ''),
                'ul' => self::render_list($segment['items'], false, null),
                'ol' => self::render_list($segment['items'], true, $segment['start']),
                default => self::render_prose($segment['lines']),
            };
        }

        return $html;
    }

    /**
     * @return array{0:string, 1:string[]} the text with each fenced block
     *   replaced by a standalone-line placeholder token, and the extracted
     *   code (verbatim, trailing newline trimmed) in match order.
     */
    private static function extract_fenced_code_blocks(string $text): array
    {
        $codeBlocks = [];

        $replaced = preg_replace_callback(
            '/```[^\n`]*\n(.*?)\n?```/s',
            function (array $m) use (&$codeBlocks): string {
                $codeBlocks[] = $m[1];

                return sprintf(self::CODE_BLOCK_TOKEN, count($codeBlocks) - 1);
            },
            $text
        );

        return [$replaced ?? $text, $codeBlocks];
    }

    /**
     * One pass over the lines, grouping consecutive same-type lines into
     * segments - a blank or otherwise non-list line always ends a list run
     * (Claude's own markdown output consistently blank-line-separates lists
     * from surrounding prose, so this simple heuristic matches real output
     * rather than needing full "loose list" blank-line-tolerance).
     *
     * @return array<int, array{type:string, lines?:string[], items?:string[], index?:int, start?:?int}>
     */
    private static function split_into_segments(string $text): array
    {
        $lines = explode("\n", $text);
        $segments = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\x00CB(\d+)\x00$/', $line, $m) === 1) {
                if ($current !== null) {
                    $segments[] = $current;
                    $current = null;
                }

                $segments[] = ['type' => 'code', 'index' => (int)$m[1]];

                continue;
            }

            if (preg_match('/^\s{0,3}[-*]\s+(.*)$/', $line, $m) === 1) {
                if ($current !== null && $current['type'] === 'ul') {
                    $current['items'][] = $m[1];
                } else {
                    if ($current !== null) {
                        $segments[] = $current;
                    }

                    $current = ['type' => 'ul', 'items' => [$m[1]]];
                }

                continue;
            }

            if (preg_match('/^\s{0,3}(\d+)\.\s+(.*)$/', $line, $m) === 1) {
                if ($current !== null && $current['type'] === 'ol') {
                    $current['items'][] = $m[2];
                } else {
                    if ($current !== null) {
                        $segments[] = $current;
                    }

                    $current = ['type' => 'ol', 'items' => [$m[2]], 'start' => (int)$m[1]];
                }

                continue;
            }

            if ($current !== null && $current['type'] === 'prose') {
                $current['lines'][] = $line;
            } else {
                if ($current !== null) {
                    $segments[] = $current;
                }

                $current = ['type' => 'prose', 'lines' => [$line]];
            }
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        // A blank prose segment only ever exists as a separator that used
        // to sit between a list/code block and the rest of the text (a
        // genuinely blank MESSAGE is the only single-segment case this
        // could apply to, and dropping it there is harmless too - nothing
        // to render either way).
        return array_values(array_filter(
            $segments,
            static fn(array $s): bool => $s['type'] !== 'prose' || trim(implode("\n", $s['lines'])) !== ''
        ));
    }

    /**
     * @param string[] $lines
     */
    private static function render_prose(array $lines): string
    {
        $escaped = htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8');

        return '<p class="whitespace-pre-wrap break-words">' . self::render_inline($escaped) . '</p>';
    }

    /**
     * @param string[] $items raw (unescaped) item text
     */
    private static function render_list(array $items, bool $ordered, ?int $start): string
    {
        $itemsHtml = '';

        foreach ($items as $item) {
            $escaped = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
            $itemsHtml .= '<li class="whitespace-pre-wrap break-words">' . self::render_inline($escaped) . '</li>';
        }

        if ($ordered) {
            $startAttr = $start !== null && $start !== 1 ? ' start="' . $start . '"' : '';

            return '<ol class="list-decimal pl-5 space-y-0.5"' . $startAttr . '>' . $itemsHtml . '</ol>';
        }

        return '<ul class="list-disc pl-5 space-y-0.5">' . $itemsHtml . '</ul>';
    }

    private static function render_code_block(string $code): string
    {
        return '<pre class="whitespace-pre overflow-auto rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-300 my-1"><code>'
            . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }

    /**
     * Bold/italic/inline-code on an ALREADY html-escaped string - inline
     * code spans are pulled to placeholder tokens first so their content
     * can never be reinterpreted as bold/italic syntax by the passes after
     * (the classic naive-markdown-regex pitfall this sidesteps).
     */
    private static function render_inline(string $escaped): string
    {
        $codeSpans = [];

        $withoutCode = preg_replace_callback(
            '/`([^`\n]+?)`/',
            function (array $m) use (&$codeSpans): string {
                $codeSpans[] = $m[1];

                return sprintf(self::INLINE_CODE_TOKEN, count($codeSpans) - 1);
            },
            $escaped
        ) ?? $escaped;

        $bold = preg_replace(['/\*\*(.+?)\*\*/s', '/__(.+?)__/s'], '<strong>$1</strong>', $withoutCode) ?? $withoutCode;
        // \b word-boundary guard on the underscore form only - without it,
        // "my_variable_name" would have its middle underscore pair read as
        // italic markup, a well-known markdown gotcha. Asterisk italics
        // need no such guard (bare * is never part of an identifier).
        $italic = preg_replace(['/\*(.+?)\*/s', '/\b_(.+?)_\b/s'], '<em>$1</em>', $bold) ?? $bold;

        return preg_replace_callback(
            '/\x00IC(\d+)\x00/',
            static fn(array $m): string => '<code class="px-1 py-0.5 rounded bg-slate-800 text-slate-200 text-[0.85em] font-mono">'
                . ($codeSpans[(int)$m[1]] ?? '') . '</code>',
            $italic
        ) ?? $italic;
    }
}
