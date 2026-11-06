<?php

declare(strict_types=1);

namespace App\Views;

use League\Plates\Engine;

/**
 * Every App\Views\* class extends this - it owns the shared Plates engine
 * (one instance, lazily built) and a render() helper so concrete View
 * classes never touch League\Plates directly. Template root is src/partials/,
 * where the page partials live (partials/ui/, partials/push-notify/).
 */
abstract class View
{
    private static ?Engine $engine = null;

    protected static function render(string $template, array $data = []): string
    {
        self::$engine ??= new Engine(__DIR__ . '/../../partials');

        return self::$engine->render($template, $data);
    }
}
