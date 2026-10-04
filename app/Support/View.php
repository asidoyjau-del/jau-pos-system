<?php
declare(strict_types=1);

namespace ProCast\Support;

use RuntimeException;

/** Minimal PHP-template renderer. Views live in platform-admin/views and escape with e(). */
final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $vars */
    public static function render(string $name, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = self::include($name, $vars);
        if ($layout === null) {
            return $content;
        }
        return self::include($layout, $vars + ['content' => $content]);
    }

    /** @param array<string,mixed> $vars */
    private static function include(string $name, array $vars): string
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            throw new RuntimeException('Invalid view name.');
        }
        $file = PROCAST_ROOT . '/platform-admin/views/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $name);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string)ob_get_clean();
    }
}
