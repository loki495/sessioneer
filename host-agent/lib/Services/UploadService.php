<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Files uploaded from the compose bar's "+" button (see upload_file.php)
 * land inside a session's own project working dir under .claude/uploads/
 * - naturally already something Claude Code can
 * Read() directly (relative to its own cwd) without needing to be told
 * about an app-specific location.
 */
class UploadService
{
    public static function uploads_dir(string $workdir): string
    {
        return rtrim($workdir, '/') . '/.claude/uploads';
    }

    /**
     * A self-contained .gitignore ("*") inside the uploads dir itself,
     * rather than touching the project's own root .gitignore -
     * .claude/ is NOT reliably already gitignored, so without this an uploaded file
     * would show up as untracked in `git status` in any project that hasn't
     * already excluded .claude/ itself. Self-healing: called on every save
     * (cheap - just an is_file() check in the common case), not only when
     * the directory is first created, so it survives a delete_all wiping
     * the directory back to empty.
     */
    public static function ensure_uploads_gitignore(string $dir): void
    {
        $path = $dir . '/.gitignore';

        if (!is_file($path)) {
            @file_put_contents($path, "*\n");
        }
    }

    /**
     * Resolves a session name to its known project working directory - the
     * same sidecar-backed value build_session_entry() exposes as 'workdir'
     * elsewhere, fetched directly here since uploads only ever need this one
     * field. Only ever set for app-spawned sessions (see write_sidecar() in
     * create_agent_session()) - a bare/manually-attached session has no sidecar
     * and so no known workdir, same limitation every other workdir-dependent
     * feature in this app already has.
     */
    public static function session_workdir(string $name): ?string
    {
        $sidecar = SidecarStore::read_sidecar($name);
        $workdir = $sidecar['workdir'] ?? null;

        return is_string($workdir) && $workdir !== '' ? $workdir : null;
    }

    /**
     * Matches the upload_max_filesize/post_max_size raised in docker-
     * compose.yml's php.ini override - an independent, friendlier-error
     * check rather than relying solely on PHP silently truncating/rejecting
     * an oversized request.
     */
    public static function max_upload_bytes(): int
    {
        return (int)Config::sessioneer_config('MAX_UPLOAD_BYTES', (string)(64 * 1024 * 1024));
    }

    /**
     * Strips any directory components and control characters from a
     * client-supplied filename down to a safe basename - a client could
     * send "../../etc/passwd" or similar as the filename field.
     */
    public static function sanitize_upload_filename(string $filename): string
    {
        $base = basename(trim($filename));
        $base = preg_replace('/[\x00-\x1f]/', '', $base) ?? $base;
        $base = ltrim($base, '.'); // no leading dot - avoid a hidden file, or matching '.'/'..' after stripping

        return $base !== '' ? $base : 'upload';
    }

    /**
     * Appends a numeric suffix before the extension until the name no
     * longer collides with an existing file - never silently overwrites an
     * earlier upload that happens to share a name.
     */
    public static function unique_upload_filename(string $dir, string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = $filename;
        $i = 1;

        while (file_exists($dir . '/' . $candidate)) {
            $candidate = $ext !== '' ? "{$base}-{$i}.{$ext}" : "{$base}-{$i}";
            $i++;
        }

        return $candidate;
    }

    /**
     * @return array{ok:bool, message?:string, filename?:string, path?:string, size?:int}
     */
    public static function save_uploaded_file(string $sessionName, string $filename, string $base64Content): array
    {
        $workdir = self::session_workdir($sessionName);

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $decoded = base64_decode($base64Content, true);

        if ($decoded === false) {
            return ['ok' => false, 'message' => 'Malformed upload data'];
        }

        if (strlen($decoded) > self::max_upload_bytes()) {
            return ['ok' => false, 'message' => 'File too large (max ' . intdiv(self::max_upload_bytes(), 1024 * 1024) . 'MB)'];
        }

        $dir = self::uploads_dir($workdir);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Could not create the uploads directory'];
        }

        self::ensure_uploads_gitignore($dir);

        $finalName = self::unique_upload_filename($dir, self::sanitize_upload_filename($filename));

        if (@file_put_contents($dir . '/' . $finalName, $decoded) === false) {
            return ['ok' => false, 'message' => 'Failed to write the uploaded file'];
        }

        return [
            'ok' => true,
            'filename' => $finalName,
            'path' => '.claude/uploads/' . $finalName,
            'size' => strlen($decoded),
        ];
    }

    /**
     * @return array{ok:bool, message?:string, files?:array<int, array{name:string, size:int, mtime:int}>, total_size?:int}
     */
    public static function list_uploaded_files(string $sessionName): array
    {
        $workdir = self::session_workdir($sessionName);

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $dir = self::uploads_dir($workdir);

        if (!is_dir($dir)) {
            return ['ok' => true, 'files' => [], 'total_size' => 0];
        }

        $files = [];
        $totalSize = 0;

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitignore') {
                continue;
            }

            $full = $dir . '/' . $entry;

            if (!is_file($full)) {
                continue;
            }

            $size = filesize($full);
            $size = $size !== false ? $size : 0;

            $files[] = ['name' => $entry, 'size' => $size, 'mtime' => filemtime($full) ?: 0];
            $totalSize += $size;
        }

        usort($files, fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return ['ok' => true, 'files' => $files, 'total_size' => $totalSize];
    }

    /**
     * Resolves $filename against the uploads dir with a realpath boundary
     * check (same discipline as browse_dir()) - basename() alone already
     * stops plain "../" traversal in the filename itself, but not e.g. a
     * symlink planted inside the uploads dir pointing elsewhere.
     */
    public static function resolve_upload_path(string $workdir, string $filename): ?string
    {
        $dir = self::uploads_dir($workdir);
        $realDir = realpath($dir);

        if ($realDir === false) {
            return null;
        }

        $real = realpath($dir . '/' . basename($filename));

        if ($real === false || !str_starts_with($real, $realDir . '/')) {
            return null;
        }

        return $real;
    }

    /**
     * The sidebar's "Uploaded files" glance links straight to this for a
     * new-tab view - resolve_upload_path() already does the real
     * path-traversal boundary check, this just reads the bytes and
     * detects a MIME type for the browser to render/download correctly.
     *
     * @return array{ok:bool, message?:string, data?:string, media_type?:string, filename?:string}
     */
    public static function read_uploaded_file(string $sessionName, string $filename): array
    {
        $workdir = self::session_workdir($sessionName);

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $path = self::resolve_upload_path($workdir, $filename);

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
            'media_type' => mime_content_type($path) ?: 'application/octet-stream',
            'filename' => basename($path),
        ];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public static function delete_uploaded_file(string $sessionName, string $filename): array
    {
        if (basename($filename) === '.gitignore') {
            return ['ok' => false, 'message' => 'File not found']; // internal bookkeeping, not a real upload - same not-found response as any other name that isn't a real uploaded file, no need to expose that this one's special
        }

        $workdir = self::session_workdir($sessionName);

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $real = self::resolve_upload_path($workdir, $filename);

        if ($real === null || !is_file($real)) {
            return ['ok' => false, 'message' => 'File not found'];
        }

        if (!@unlink($real)) {
            return ['ok' => false, 'message' => 'Failed to delete the file'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool, message?:string, deleted?:int}
     */
    public static function delete_all_uploaded_files(string $sessionName): array
    {
        $workdir = self::session_workdir($sessionName);

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Unknown working directory for this session'];
        }

        $dir = self::uploads_dir($workdir);

        if (!is_dir($dir)) {
            return ['ok' => true, 'deleted' => 0];
        }

        $deleted = 0;

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitignore') {
                continue;
            }

            $full = $dir . '/' . $entry;

            if (is_file($full) && @unlink($full)) {
                $deleted++;
            }
        }

        return ['ok' => true, 'deleted' => $deleted];
    }
}
