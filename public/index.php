<?php

declare(strict_types=1);

/**
 * The one true front controller - every request (Apache/nginx via a
 * rewrite-everything-here rule, or php -S given this file's path as its
 * router-script argument) reaches this file first.
 *
 * Static assets (/js/*.js, /css/*.css, /sw.js, /manifest.json, /icons/*.png)
 * are deliberately excluded below and served directly by whatever's in
 * front instead - Apache/nginx's own real-file handling (see
 * public/.htaccess), or php -S's own fallback when this file returns
 * false. Checked against the query-string-stripped path so
 * Assets::versioned_url()'s ?v=<mtime> cache-busting suffix never defeats
 * the match. No output happens before that `return false` - anything
 * echoed here would leak into whatever gets served next for this same
 * request (only relevant when this file is used as a php -S router-script
 * argument; Apache/nginx never reach this file at all for a real static
 * asset, per public/.htaccess / an equivalent nginx try_files rule).
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

if (
    $path === '/sw.js'
    || $path === '/manifest.json'
    || preg_match('#^/js/[\w-]+\.js$#', $path) === 1
    || preg_match('#^/css/[\w-]+\.css$#', $path) === 1
    || preg_match('#^/icons/[\w-]+\.png$#', $path) === 1
) {
    return false;
}

require __DIR__ . '/../vendor/autoload.php';

if (!App\Services\AuthService::request_host_allowed()) {
    http_response_code(421);
    echo 'Host not allowed. Add it to ALLOWED_HOSTS in .env.';

    return;
}

$router = require __DIR__ . '/../src/routes.php';
$handler = $router->match($_SERVER['REQUEST_METHOD'], $path);

if ($handler === null) {
    http_response_code(404);
    echo 'Not found';

    return;
}

[$controllerClass, $methodName] = $handler;
(new $controllerClass())->$methodName();
