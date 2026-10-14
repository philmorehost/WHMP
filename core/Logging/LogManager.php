<?php

declare(strict_types=1);

namespace CodeVault\Logging;

use CodeVault\Config;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the application's log channels.
 *
 * Every channel writes JSON lines — one object per line — because the consumers
 * are machines: a denied payment, a failed migration and a queue job that threw
 * are matched by their fields (`channel`, `event`, `status`, `job`), not by
 * grepping a sentence. Rotation is by day, 14 days kept, in storage/logs; a
 * CLI process also mirrors to STDERR so `docker compose logs cron` shows the
 * same events without reading the volume.
 *
 * What is deliberately NOT here: a per-request line for every successful
 * request. On a billing platform the interesting traffic is the failures, and a
 * log nobody reads because it is 99% noise is not observability. Set
 * LOG_HTTP=1 (or LOG_LEVEL=debug) when you want the full request stream.
 *
 * Configuration (all optional):
 *
 *   LOG_CHANNEL   default channel for LoggerInterface (default: app)
 *   LOG_LEVEL     debug|info|notice|warning|error|critical|alert|emergency
 *                 — defaults to `debug` when APP_DEBUG is on, `info` otherwise
 *   LOG_HTTP      1 = log every request/response, not just failures
 *   LOG_STDERR    0 = never mirror to STDERR, even in CLI (default: CLI mirrors)
 *   LOG_MAX_FILES days of history to keep (default 14)
 *   LOG_TRACES    0 = log an exception without its stack trace (default: with)
 *
 * If Monolog is not installed (a checkout that never ran `composer install`),
 * channels degrade to a NullLogger instead of taking the site down. That is the
 * same fail-open rule the AI features follow: logging must never be the reason a
 * request 500s.
 */
final class LogManager
{
    /** @var array<string, LoggerInterface> */
    private array $channels = [];

    private readonly ContextProcessor $context;
    private readonly RedactorProcessor $redactor;

    public function __construct(
        private readonly Config $config,
        private readonly string $basePath
    ) {
        $this->context = new ContextProcessor($config, $basePath);
        $this->redactor = new RedactorProcessor();
    }

    /**
     * A channel by name. Unknown names are created on demand — `mail`,
     * `payments`, `cloudflare` and friends are all just labels on the same
     * pipeline, so a subsystem can name its own stream without registering it
     * anywhere first.
     */
    public function channel(?string $name = null): LoggerInterface
    {
        $name ??= (string) $this->config->env('LOG_CHANNEL', 'app');
        $name = preg_replace('/[^a-z0-9_.\-]/i', '-', $name) ?: 'app';

        return $this->channels[$name] ??= $this->build($name);
    }

    /** The channel uncaught exceptions and failed boot steps go to. */
    public function errors(): LoggerInterface
    {
        return $this->channel('errors');
    }

    /**
     * Context every channel adds to every record from now on (request id, cron
     * run id, the admin acting). Called once per entry point.
     *
     * @param array<string, mixed> $context
     */
    public function shareContext(array $context): void
    {
        $this->context->add($context);
    }

    /** @return array<string, mixed> */
    public function sharedContext(): array
    {
        return $this->context->all();
    }

    private function build(string $name): LoggerInterface
    {
        if (!class_exists(Logger::class)) {
            return new NullLogger();
        }

        $level = $this->level();
        $logger = new Logger($name);

        $handler = new RotatingFileHandler(
            $this->basePath . '/storage/logs/' . $name . '.log',
            (int) $this->config->env('LOG_MAX_FILES', '14'),
            $level,
            true,
            0644
        );

        // Stack traces are on by default: a failed queue job or a rejected
        // webhook without one costs an operator a reproduction attempt, and a
        // trace is the difference between "a payment failed" and "a payment
        // failed because the gateway returned a 409 on line 213".
        $formatter = new JsonFormatter(
            JsonFormatter::BATCH_MODE_NEWLINES,
            true,
            false,
            (string) $this->config->env('LOG_TRACES', '1') !== '0'
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        if ($this->mirrorsToStderr()) {
            $stderr = new StreamHandler('php://stderr', $level);
            $stderr->setFormatter($formatter);
            $logger->pushHandler($stderr);
        }

        $logger->pushProcessor($this->context);
        $logger->pushProcessor($this->redactor);

        return $logger;
    }

    private function level(): Level
    {
        $configured = strtolower(trim((string) $this->config->env('LOG_LEVEL', '')));

        if ($configured !== '') {
            if (is_numeric($configured) && ($byValue = Level::tryFrom((int) $configured)) !== null) {
                return $byValue;
            }

            try {
                return Level::fromName($configured);
            } catch (\UnhandledMatchError | \ValueError) {
                // A typo in .env falls back to the default instead of taking the
                // request down — the one thing a logger must never do.
            }
        }

        return $this->config->env('APP_DEBUG', false) === true ? Level::Debug : Level::Info;
    }

    private function mirrorsToStderr(): bool
    {
        if ((string) $this->config->env('LOG_STDERR', '') === '0') {
            return false;
        }

        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
