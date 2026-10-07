<?php
declare(strict_types=1);

/**
 * Pure unit tests for HostAgent\Services\SessionService::parse_worker_tag()
 * - no tmux, no socket, no fixtures, just string in/array out. Parses the
 * [WORKER session=.../... parent=...] session-tagging convention (see
 * docs/features.md) out of a session's raw title, as used by
 * sessioneer_headless_sessions() in host-agent/lib/Sessions.php.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\SessionService;

// --- happy path: a well-formed tag, task text after it kept as the clean title. ---
$tag = SessionService::parse_worker_tag(
    "[WORKER session=2026-08-30-cards-migration/task-3 parent=cc-20260830-131822]\nMigrate the remaining IP-based card URLs."
);
assert_true($tag['is_worker'], 'parse_worker_tag: a well-formed tag is recognized as a worker');
assert_equal('cc-20260830-131822', $tag['parent_session_id'], 'parse_worker_tag: parent session id is extracted');
assert_equal('Migrate the remaining IP-based card URLs.', $tag['clean_title'], 'parse_worker_tag: the tag is stripped, leaving the task text as the title');

// --- happy path: opencode's --title carries just the tag with no trailing text
// (title defaults to a truncated prompt if a caller passes only the tag itself). ---
$tag = SessionService::parse_worker_tag('[WORKER session=orch-1/task-1 parent=unknown]');
assert_true($tag['is_worker'], 'parse_worker_tag: a tag with nothing after it is still recognized as a worker');
assert_equal('(worker session)', $tag['clean_title'], 'parse_worker_tag: an empty remainder falls back to a placeholder title, not a blank string');

// --- parent=unknown is a real, honest value (see the skill: "don't guess or
// fabricate one") - must resolve to null, not the literal string "unknown". ---
assert_equal(null, $tag['parent_session_id'], 'parse_worker_tag: parent=unknown resolves to null, not the literal string');

// --- sad path: ordinary, non-tagged titles (the overwhelming common case -
// every user-driven session, and any pre-existing session from before this
// feature shipped) must never be misclassified as a worker. ---
assert_true(!SessionService::parse_worker_tag('homie')['is_worker'], 'parse_worker_tag: a plain workdir-basename title is not a worker');
assert_true(!SessionService::parse_worker_tag('Fix the login redirect bug')['is_worker'], 'parse_worker_tag: an ordinary ai-generated title is not a worker');
assert_true(!SessionService::parse_worker_tag(null)['is_worker'], 'parse_worker_tag: a null title is not a worker');
assert_true(!SessionService::parse_worker_tag('')['is_worker'], 'parse_worker_tag: an empty title is not a worker');
assert_equal('', SessionService::parse_worker_tag(null)['clean_title'], 'parse_worker_tag: a null title round-trips to an empty clean_title, not a crash');

// --- sad path: text that merely mentions the word WORKER, or has it
// mid-string, must not false-positive - the tag must anchor at the start. ---
assert_true(!SessionService::parse_worker_tag('Investigate why the WORKER pool is exhausted')['is_worker'], 'parse_worker_tag: "WORKER" appearing mid-title does not false-positive');
assert_true(!SessionService::parse_worker_tag('worker session cleanup')['is_worker'], 'parse_worker_tag: lowercase "worker" does not match (case-sensitive, matches the skill\'s exact required casing)');

// --- sad path: truncated tag (codex/agy have no explicit title-setting flag,
// so the tag only survives via whatever preview text gets derived and
// truncated - see the skill's own honesty note on this being unverified) -
// still recognized as a worker even with the closing bracket cut off, since
// a worker is worth flagging even from a partial tag; parent is unknown
// rather than a mis-parsed fragment. ---
$truncated = SessionService::parse_worker_tag('[WORKER session=orch-1/task-1 par');
assert_true($truncated['is_worker'], 'parse_worker_tag: a truncated tag missing the closing bracket is still recognized as a worker');
assert_equal(null, $truncated['parent_session_id'], 'parse_worker_tag: a truncated tag with no parseable parent= yields null, not a garbage value');
assert_equal('(worker session)', $truncated['clean_title'], 'parse_worker_tag: a truncated tag with nothing left after it falls back to the placeholder title');

test_exit();
