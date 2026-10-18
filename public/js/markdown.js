// @ts-check
// Lightweight inline markdown renderer, shared by every page that renders
// transcript/message text (mirrors the PHP side's MarkdownRenderer - kept
// in its own file, loaded before session.js/index.js/archived-session.js,
// since it's pure text-to-HTML with zero DOM/session dependencies, unlike
// almost everything else those files do. Extracted from session.js
// because it has no shared state at all.
var MD_NUL = String.fromCharCode(0);

function mdExtractFencedCodeBlocks(text) {
  var codeBlocks = [];
  var replaced = text.replace(/```[^\n`]*\n([\s\S]*?)\n?```/g, function (match, code) {
    codeBlocks.push(code);
    return MD_NUL + 'CB' + (codeBlocks.length - 1) + MD_NUL;
  });
  return { text: replaced, codeBlocks: codeBlocks };
}

// A blank/whitespace-only prose segment is dropped (mirrors PHP's
// array_filter at the end of split_into_segments()) - it only ever
// exists as a separator that used to sit between a list/code block and
// the rest of the text.
function mdSplitIntoSegments(text) {
  var lines = text.split('\n');
  var segments = [];
  var current = null;

  function flush() {
    if (current) {
      segments.push(current);
      current = null;
    }
  }

  var codeTokenRe = new RegExp('^' + MD_NUL + 'CB(\\d+)' + MD_NUL + '$');

  lines.forEach(function (line) {
    var codeMatch = codeTokenRe.exec(line);
    if (codeMatch) {
      flush();
      segments.push({ type: 'code', index: parseInt(codeMatch[1], 10) });
      return;
    }

    var ulMatch = /^\s{0,3}[-*]\s+(.*)$/.exec(line);
    if (ulMatch) {
      if (current && current.type === 'ul') {
        current.items.push(ulMatch[1]);
      } else {
        flush();
        current = { type: 'ul', items: [ulMatch[1]] };
      }
      return;
    }

    var olMatch = /^\s{0,3}(\d+)\.\s+(.*)$/.exec(line);
    if (olMatch) {
      if (current && current.type === 'ol') {
        current.items.push(olMatch[2]);
      } else {
        flush();
        current = { type: 'ol', items: [olMatch[2]], start: parseInt(olMatch[1], 10) };
      }
      return;
    }

    if (current && current.type === 'prose') {
      current.lines.push(line);
    } else {
      flush();
      current = { type: 'prose', lines: [line] };
    }
  });

  flush();

  return segments.filter(function (s) {
    return s.type !== 'prose' || s.lines.join('\n').replace(/^\s+|\s+$/g, '') !== '';
  });
}

// Bold/italic/inline-code on an ALREADY html-escaped string - inline code
// spans are pulled to placeholder tokens first so their content can never
// be reinterpreted as bold/italic syntax by the passes after (the
// classic naive-markdown-regex pitfall this sidesteps). [\s\S] instead of
// a dotAll "." (no /s flag - not universally supported) lets bold/italic
// span multiple lines the same way the PHP side's /s-flagged regex does.
function mdRenderInline(escaped) {
  var codeSpans = [];
  var withoutCode = escaped.replace(/`([^`\n]+?)`/g, function (match, code) {
    codeSpans.push(code);
    return MD_NUL + 'IC' + (codeSpans.length - 1) + MD_NUL;
  });

  var bold = withoutCode
    .replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>')
    .replace(/__([\s\S]+?)__/g, '<strong>$1</strong>');

  // \b word-boundary guard on the underscore form only - without it,
  // "my_variable_name" would have its middle underscore pair read as
  // italic markup, a well-known markdown gotcha. Asterisk italics need
  // no such guard (bare * is never part of an identifier).
  var italic = bold
    .replace(/\*([\s\S]+?)\*/g, '<em>$1</em>')
    .replace(/\b_([\s\S]+?)_\b/g, '<em>$1</em>');

  return italic.replace(new RegExp(MD_NUL + 'IC(\\d+)' + MD_NUL, 'g'), function (match, idx) {
    return '<code class="px-1 py-0.5 rounded bg-slate-800 text-slate-200 text-[0.85em] font-mono">' + (codeSpans[parseInt(idx, 10)] || '') + '</code>';
  });
}

function mdRenderProse(lines) {
  return '<p class="whitespace-pre-wrap break-words">' + mdRenderInline(escapeHtml(lines.join('\n'))) + '</p>';
}

function mdRenderList(items, ordered, start) {
  var itemsHtml = items.map(function (item) {
    return '<li class="whitespace-pre-wrap break-words">' + mdRenderInline(escapeHtml(item)) + '</li>';
  }).join('');

  if (ordered) {
    var startAttr = (start != null && start !== 1) ? (' start="' + start + '"') : '';
    return '<ol class="list-decimal pl-5 space-y-0.5"' + startAttr + '>' + itemsHtml + '</ol>';
  }

  return '<ul class="list-disc pl-5 space-y-0.5">' + itemsHtml + '</ul>';
}

function mdRenderCodeBlock(code) {
  return '<pre class="whitespace-pre overflow-auto rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-slate-300 my-1"><code>' + escapeHtml(code) + '</code></pre>';
}

function renderMarkdown(text) {
  var extracted = mdExtractFencedCodeBlocks(text);
  var segments = mdSplitIntoSegments(extracted.text);

  return segments.map(function (segment) {
    if (segment.type === 'code') {
      return mdRenderCodeBlock(extracted.codeBlocks[segment.index] || '');
    }
    if (segment.type === 'ul') {
      return mdRenderList(segment.items, false, null);
    }
    if (segment.type === 'ol') {
      return mdRenderList(segment.items, true, segment.start);
    }
    return mdRenderProse(segment.lines);
  }).join('');
}
