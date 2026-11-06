<?php

declare(strict_types=1);

namespace App;

/**
 * Cache-busting for the static files under public/js/ - iOS Safari's
 * home-screen PWA cache in particular has a history of holding onto a
 * stale script with no reload gesture available to the user (see sw.js's
 * comments on iOS quirks); appending the file's own mtime means a code
 * change always produces a new URL, so the browser never has a reason to
 * serve a stale copy.
 */
class Assets
{
    /**
     * $urlPath is the public path as used in a <script src>, e.g. "/js/v2.js".
     */
    public static function versioned_url(string $urlPath): string
    {
        $filePath = __DIR__ . '/../../public' . $urlPath;
        $mtime = @filemtime($filePath);

        return $mtime !== false ? $urlPath . '?v=' . $mtime : $urlPath;
    }
}
