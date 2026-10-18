<!DOCTYPE html>
<?php /* 
     <body>'s own overflow-hidden (below) does NOT actually stop the page
     from scrolling - in standards mode, <html> (document.documentElement),
     not <body>, is the real root scrolling element, and it had no height/
     overflow constraint of its own at all. That gap let focusing the
     compose textarea pan/scroll the whole document, revealing blank space
     (the actual bug: page content vanishing, or a scrollable gap opening
     below the footer) that body's overflow-hidden alone could never have
     prevented no matter how it was tuned. h-full+overflow-hidden here,
     matching body's own fixed-shell treatment exactly, is the real fix -
     see common.js's window-scroll-trap comment for the JS-level safety
     net kept alongside this. */ ?>
<html lang="en" class="<?= !empty($fixedShell) ? 'h-full overflow-hidden' : '' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="<?= $this->e($viewportContent) ?>">
<title><?= $this->e($title) ?></title>
<?php /* Fixed app identity for "Add to Home Screen", independent of $title
     above (which is per-page - a session's own title on session.php, so
     browser tabs stay distinguishable). Without a manifest/fixed title
     here, iOS/Android use whatever page's <title> was open at install
     time for the home-screen icon's name, which for a titleless session
     page is just the bare agent name. */ ?>
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/icons/icon-192.png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Sessioneer">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#020617">
<?= $this->section('head-extra', '') ?>
<link rel="stylesheet" href="<?= \App\Assets::versioned_url('/css/tailwind.css') ?>">
<?= $this->section('style', '') ?>
</head>
<body class="<?= !empty($fixedShell) ? 'h-[var(--app-vh,100dvh)] overflow-hidden' : 'min-h-screen' ?> bg-slate-950 text-slate-100 overscroll-y-none">
<?php /* overscroll-y-none (not the both-axes overscroll-none this replaced) - Y-axis only, so vertical rubber-banding/scroll-chaining
     past the top/bottom of the page is blocked without also touching
     X-axis overscroll-behavior, which the iOS edge-swipe-back gesture
     (see navigation-blanket above) rides on. */ ?>
<?php /* Rendered before the content section, not after - common.js's
     document.getElementById() lookups for this run as soon as its own
     <script> tag is hit, at the END of the content section below, so
     this has to already exist in the DOM by then or those lookups
     silently come back null and the whole feature no-ops. */ ?>
<?php /* Covers any navigation away from this page (the iOS edge-swipe-back
     gesture included - it has no click handler to hook, unlike a normal
     link tap, which is why this can't just be a per-link loading state)
     with the same background the page itself already sits on, rather
     than leaving the old page's content frozen on screen for however
     long the browser takes to actually swap in the next one - see
     common.js's pagehide/pageshow handling. z-[60], one above the
     fullscreen text modal's z-50, so it still wins if a navigation
     happens to fire while that's open too. */ ?>
<div id="navigation-blanket" class="hidden fixed inset-0 z-[60] bg-slate-950 flex items-center justify-center">
  <span class="flex items-center gap-1.5">
    <span class="inline-block w-2 h-2 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>
    <span class="inline-block w-2 h-2 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>
    <span class="inline-block w-2 h-2 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>
  </span>
</div>
<div id="fullscreen-text-modal" class="hidden fixed inset-0 z-50 bg-slate-950 flex flex-col">
  <div class="select-none flex items-center justify-between px-2 py-2 border-b border-slate-800 shrink-0">
    <span class="flex items-center gap-1.5">
      <button type="button" id="fullscreen-text-modal-wrap-toggle" aria-pressed="false" class="text-xs text-slate-400 active:text-slate-200 border border-slate-700 rounded px-2 py-1">Wrap: Off</button>
      <button type="button" id="fullscreen-text-modal-copy" class="text-xs text-slate-400 active:text-slate-200 border border-slate-700 rounded px-2 py-1">Copy</button>
    </span>
    <button type="button" id="fullscreen-text-modal-close" aria-label="Close full screen view" class="text-slate-400 active:text-slate-200 text-2xl leading-none px-3 py-1">&times;</button>
  </div>
  <?php /* A <div>, not a <pre>: markdown in the full size modals - openFullscreenTextModal() (common.js) can
       fill this with rendered markdown HTML (block-level <p>/<ul>/<pre>
       elements from MarkdownRenderer::render_html()/its JS mirror
       renderMarkdown()), which would be invalid/broken nested inside a
       real <pre> (a <pre>'s own UA white-space:pre would also fight the
       markdown blocks' own whitespace-pre-wrap classes). The plain-text
       case (the common one - most expanded blocks aren't markdown) looks
       IDENTICAL either way: whitespace-pre/whitespace-pre-wrap/break-words
       (applyFullscreenTextWrap()'s own toggle classes) are just CSS
       properties, not something requiring a literal <pre> element. */ ?>
  <div id="fullscreen-text-modal-content" class="flex-1 overflow-auto overscroll-contain whitespace-pre px-3 py-2 text-xs text-slate-100"></div>
</div>
<?php /* Editable counterpart to the read-only modal above (lets a text area expand to full screen while
     typing, both compose and answering a blocked prompt) - a genuinely
     separate element, not a repurposed fullscreen-text-modal: that one's
     content is a plain <div> that can hold rendered markdown HTML, driven
     by openFullscreenTextModal()'s own Wrap/Copy chrome, none of which
     applies to a live, editable field. openFullscreenEditModal()/
     closeFullscreenEditModal() (common.js) own this one - see their own
     doc comments for the live two-way sync with whichever real
     <textarea> triggered it. No swipe-to-close here (unlike the read-only
     modal) - deliberately: a real horizontal drag inside a focused,
     editable textarea is a normal text-selection/cursor gesture, not
     something to disambiguate from "the user wants to leave" the way it
     safely can be over read-only content. */ ?>
<div id="fullscreen-edit-modal" class="hidden fixed inset-0 z-50 bg-slate-950 flex flex-col">
  <div class="select-none flex items-center justify-end px-2 py-2 border-b border-slate-800 shrink-0">
    <button type="button" id="fullscreen-edit-modal-close" aria-label="Close full screen editor" class="text-slate-400 active:text-slate-200 text-2xl leading-none px-3 py-1">&times;</button>
  </div>
  <textarea id="fullscreen-edit-modal-textarea" aria-label="Full screen text editor" class="flex-1 resize-none bg-slate-950 text-slate-100 text-base px-3 py-2 focus:outline-none"></textarea>
</div>
<?= $this->section('content') ?>
</body>
</html>
