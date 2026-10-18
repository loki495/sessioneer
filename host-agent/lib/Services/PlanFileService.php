<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Sidebar "plan/handoff files" glance: listing and reading ad-hoc markdown
 * files sitting directly in a session's own working directory. Split out of
 * SessionService.php - fully self-contained, no cross-class calls at all. Methods/
 * bodies moved verbatim, no behavior changes.
 */
class PlanFileService
{
    /**
     * Every markdown file sitting directly in $sessionName's own working
     * directory (never subdirectories) - the sidebar's "plan/handoff
     * files" glance: ad-hoc plan docs and
     * handoff prompts a session or the user drops straight into a
     * project's root are otherwise invisible/easy to forget about once
     * stale. Deliberately read-only, no delete action here - cleanup
     * stays a manual delete, this is purely a glance. README.md/CLAUDE.md are excluded - permanent, expected
     * project docs, not the kind of ad-hoc scratch file this is meant to
     * surface. Workdir is resolved server-side from the session's own
     * sidecar, never trusted from the caller - same discipline as every
     * other per-session action in this file.
     *
     * @return array{ok:bool, files?:array<int, array{name:string, size:int, mtime:int}>, message?:string}
     */
    public static function list_plan_files(string $sessionName): array
    {
        $sidecar = SidecarStore::read_sidecar($sessionName);
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        if (!is_dir($workdir)) {
            return ['ok' => true, 'files' => []];
        }

        $excludedNames = ['readme.md', 'claude.md'];
        $files = [];

        foreach (scandir($workdir) ?: [] as $entry) {
            if (strtolower((string)pathinfo($entry, PATHINFO_EXTENSION)) !== 'md') {
                continue;
            }

            if (in_array(strtolower($entry), $excludedNames, true)) {
                continue;
            }

            $full = $workdir . '/' . $entry;

            if (!is_file($full)) {
                continue;
            }

            $files[] = [
                'name' => $entry,
                'size' => (int)filesize($full),
                'mtime' => (int)filemtime($full),
            ];
        }

        usort($files, fn(array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return ['ok' => true, 'files' => $files];
    }

    /**
     * Resolves $filename against $sessionName's own working directory with
     * a realpath boundary check (same discipline as UploadService::
     * resolve_upload_path()), re-applying list_plan_files()'s own .md/
     * README/CLAUDE.md rules rather than trusting that a caller-supplied
     * filename only ever came from that listing - it never actually goes
     * through the client round-trip, but the whole point of re-checking
     * server-side is to not depend on that being true forever.
     */
    private static function resolve_plan_file_path(string $workdir, string $filename): ?string
    {
        if (strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) !== 'md') {
            return null;
        }

        if (in_array(strtolower(basename($filename)), ['readme.md', 'claude.md'], true)) {
            return null;
        }

        $realDir = realpath($workdir);

        if ($realDir === false) {
            return null;
        }

        $real = realpath($realDir . '/' . basename($filename));

        if ($real === false || !str_starts_with($real, $realDir . '/')) {
            return null;
        }

        return $real;
    }

    /**
     * The sidebar's "Plan/handoff files" glance links straight to this for
     * a new-tab view of the real file content.
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string}
     */
    public static function read_plan_file(string $sessionName, string $filename): array
    {
        $sidecar = SidecarStore::read_sidecar($sessionName);
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $path = self::resolve_plan_file_path($workdir, $filename);

        if ($path === null) {
            return ['ok' => false, 'message' => 'File not found'];
        }

        $data = file_get_contents($path);

        if ($data === false) {
            return ['ok' => false, 'message' => 'Could not read file'];
        }

        return [
            'ok' => true,
            'data' => base64_encode($data),
            'media_type' => 'text/markdown; charset=utf-8',
            'filename' => basename($path),
        ];
    }

    /**
     * The sidebar "Todo" link: opens a
     * session's own cwd-level `todo` file (the same convention this app's
     * own project keeps a `todo` file at its root for) in a fullscreen
     * modal. Deliberately separate from list_plan_files()/read_plan_file()
     * above - those are scoped to *.md scratch docs with README.md/
     * CLAUDE.md excluded, whereas `todo` is a no-extension, always-expected
     * project bookkeeping file that must stay readable regardless. Same
     * realpath boundary discipline as resolve_plan_file_path() (never trust
     * a caller - the workdir is re-derived from the sidecar, never supplied
     * by the client).
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string}
     */
    public static function read_todo_file(string $sessionName): array
    {
        $sidecar = SidecarStore::read_sidecar($sessionName);
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $realDir = realpath($workdir);

        if ($realDir === false) {
            return ['ok' => false, 'message' => 'Working directory no longer exists'];
        }

        // realpath() before is_file(), so a `todo` symlink pointing outside the
        // workdir is rejected rather than followed. is_file() alone resolves the
        // link, so `todo -> /etc/passwd` read and returned the target's contents
        // to the browser. Same discipline as resolve_plan_file_path() above,
        // which this method's docblock always claimed to follow.
        $path = realpath($realDir . '/todo');

        if ($path === false || !str_starts_with($path, $realDir . '/')) {
            return ['ok' => false, 'message' => 'No todo file in this session\'s working directory'];
        }

        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'No todo file in this session\'s working directory'];
        }

        $data = file_get_contents($path);

        if ($data === false) {
            return ['ok' => false, 'message' => 'Could not read todo file'];
        }

        return [
            'ok' => true,
            'data' => base64_encode($data),
            'media_type' => 'text/plain; charset=utf-8',
            'filename' => 'todo',
        ];
    }
}
