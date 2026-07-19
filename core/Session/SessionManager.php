<?php

declare(strict_types=1);

namespace CodeVault\Session;

use CodeVault\Config;
use Throwable;

/**
 * Wraps PHP's native session mechanism. Tries the Redis-backed handler
 * first (per §4.5 "Redis sessions"); on any failure (extension missing,
 * Redis unreachable, local dev box) it silently falls back to PHP's
 * default file-based sessions so the app still runs.
 */
class SessionManager
{
    private bool $started = false;

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        // HttpOnly blocks XSS-based cookie theft; SameSite=Lax blocks the
        // cookie from riding along on a cross-site POST (the primary CSRF
        // vector) while still working for normal top-level navigation
        // (a client clicking an emailed invoice link, etc.); Secure is
        // conditional on the request actually being HTTPS so local HTTP
        // dev (no TLS configured) still works.
        $isHttps = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || (string) $this->config->env('APP_URL', '') !== '' && str_starts_with((string) $this->config->env('APP_URL'), 'https://');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $driver = $this->config->env('SESSION_DRIVER', 'file');

        if ($driver === 'redis' && extension_loaded('redis')) {
            try {
                $handler = new RedisSessionHandler(
                    (string) $this->config->env('REDIS_HOST', '127.0.0.1'),
                    (int) $this->config->env('REDIS_PORT', 6379),
                    (string) $this->config->env('REDIS_PASSWORD', ''),
                    (int) $this->config->env('REDIS_DB', 0),
                );
                session_set_save_handler($handler, true);
            } catch (Throwable) {
                // Redis unavailable — fall through to native file sessions.
            }
        }

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }
}
