<?php

declare(strict_types=1);

namespace CodeVault;

/**
 * Immutable-ish snapshot of the incoming HTTP request, captured once at
 * front-controller time so nothing downstream touches superglobals directly.
 */
class Request
{
    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, array<string, mixed>> */
    private array $files;

    private string $method;

    private string $path;

    private string $ip;

    private string $rawBody;

    public function __construct(
        array $query,
        array $body,
        array $server,
        array $headers,
        array $files = [],
        string $rawBody = ''
    ) {
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
        $this->files = $files;
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->ip = $server['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->rawBody = $rawBody;

        $uri = $server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->path = '/' . trim($path, '/');
    }

    public static function capture(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $rawBody = '';

        if (str_contains($contentType, 'application/json')) {
            // Kept verbatim (not just json_decode()'d) — webhook signature
            // verification (Paystack's HMAC, etc.) must hash the exact
            // bytes the sender signed; re-serializing the decoded array
            // can silently produce different bytes (key order, spacing,
            // numeric formatting) and break verification for a genuine payload.
            $rawBody = file_get_contents('php://input') ?: '';
            $decoded = json_decode($rawBody, true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($_GET, $body, $_SERVER, $headers, $_FILES, $rawBody);
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }

    /** @return array<string, mixed>|null the raw $_FILES entry for this field */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtoupper($name)] ?? $this->headers[$name] ?? $default;
    }

    /** @return array<string, string> every captured request header */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('CONTENT-TYPE', '') ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('ACCEPT', '') ?? '', 'application/json') || $this->isJson();
    }
}
