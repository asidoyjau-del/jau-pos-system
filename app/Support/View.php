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

    /**
     * Inline SVG icon, used everywhere the old UI had an emoji.
     *
     * The POS already draws its interface icons as inline <svg> (same
     * stroke="currentColor" style), so the admin portal now matches it and no
     * longer renders OS-dependent emoji. Icons are inlined rather than
     * sprited because the admin CSP is `script-src 'self'` and there is no
     * icon-font dependency to load.
     *
     * IMPORTANT: the return value is TRUSTED, PRE-BUILT markup — emit it
     * unescaped (`<?= $ico('shield') ?>`). Passing it through View::e() makes
     * the browser print the raw `<svg …>` source as visible text. The only
     * untrusted part ($class) is escaped below, and $name is looked up in the
     * fixed ICONS map, so an unknown name can only ever yield the 'dot' glyph.
     *
     * @param string $name  key of self::ICONS
     * @param string $class extra CSS classes on top of "pa-ico"
     */
    public static function icon(string $name, string $class = ''): string
    {
        $paths = self::ICONS[$name] ?? self::ICONS['dot'];
        $attr  = 'class="pa-ico' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '"'
               . ' viewBox="0 0 24 24" aria-hidden="true" focusable="false"';
        // The space after "<svg" is required — without it the browser parses the
        // tag name as "svgclass" and silently drops every attribute.
        return '<svg ' . $attr . '>' . $paths . '</svg>';
    }

    /** Feather-style 24x24 stroke paths. */
    private const ICONS = [
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'store'    => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'zap'      => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'pause'    => '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
        'play'     => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'refresh'  => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'key'      => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'monitor'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'smartphone' => '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
        'desktop'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'log-out'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'mail'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'phone'    => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'dot'      => '<circle cx="12" cy="12" r="4"/>',
    ];

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
