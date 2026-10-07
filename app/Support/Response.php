<?php
declare(strict_types=1);

namespace ProCast\Support;

/** Framework-free HTTP response value object. The entry point emits it. */
final class Response
{
    /** @var array<string,string> */
    public array $headers = [];
    public bool $regenerateSession = false;
    public bool $destroySession = false;
    public ?string $filePath = null;

    public function __construct(public int $status = 200, public string $body = '', array $headers = [])
    {
        $this->headers = $headers;
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $path, int $status = 302): self
    {
        // Only ever redirect inside the platform prefix (no open redirects).
        $target = Request::PREFIX . '/' . ltrim($path, '/');
        return new self($status, '', ['Location' => rtrim($target, '/') === Request::PREFIX ? Request::PREFIX . '/' : $target]);
    }

    /** Bare 404: no body, no cookies, no hints about what exists. */
    public static function notFound(): self
    {
        return new self(404, '');
    }

    public static function forbidden(string $msg = 'Forbidden'): self
    {
        return new self(403, $msg, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->filePath !== null && is_file($this->filePath)) {
            header('Content-Length: ' . (string)filesize($this->filePath));
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
