<?php
declare(strict_types=1);

namespace ProCast\Support;

/** Immutable view of the incoming HTTP request (path is relative to /platform-admin). */
final class Request
{
    public const PREFIX = '/platform-admin';

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $path,      // null => request is not under /platform-admin
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly bool $https = true,
    ) {
    }

    /** @param array<string,mixed> $server */
    public static function fromGlobals(array $server, array $get, array $post, array $cookie): self
    {
        $uri = (string)parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $decoded = rawurldecode($uri);
        $path = null;
        if ($decoded === self::PREFIX || strncmp($decoded, self::PREFIX . '/', strlen(self::PREFIX) + 1) === 0) {
            $path = substr($decoded, strlen(self::PREFIX));
            $path = '/' . trim($path, '/');
            // path traversal / null byte / encoded tricks => treat as unroutable
            if (strpos($path, "\0") !== false || strpos($path, '..') !== false || strpos($path, '\\') !== false) {
                $path = null;
            }
        }
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return new self(
            strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $get,
            $post,
            $cookie,
            self::clientIp($server),
            (string)($server['HTTP_USER_AGENT'] ?? ''),
            $https
        );
    }

    /**
     * Client IP. Behind Render's proxy set PLATFORM_TRUSTED_PROXY=true: the
     * LAST X-Forwarded-For hop is the one appended by the trusted proxy, so it
     * cannot be forged by the client (earlier hops can).
     *
     * @param array<string,mixed> $server
     */
    public static function clientIp(array $server): string
    {
        $remote = (string)($server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (Env::get('PLATFORM_TRUSTED_PROXY') === 'true' && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', (string)$server['HTTP_X_FORWARDED_FOR']));
            $last = end($parts);
            if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) {
                return $last;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function queryStr(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }
}
