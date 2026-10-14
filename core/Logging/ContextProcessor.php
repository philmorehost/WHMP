<?php

declare(strict_types=1);

namespace CodeVault\Logging;

use CodeVault\Config;
use Monolog\LogRecord;

/**
 * Stamps every record with the facts that make a log line actionable: which
 * process, which entry point, which request.
 *
 * `request_id` is the important one. Without it, "invoice 41 was charged twice"
 * means reading every line around that timestamp; with it, the web request that
 * created the invoice, the queue job that charged it and the webhook that
 * confirmed it all carry the same id, and `jq 'select(.request_id=="...")'`
 * pulls the whole story out of the day's lines.
 */
final class ContextProcessor
{
    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(
        Config $config,
        string $basePath
    ) {
        $this->context = [
            'app' => (string) $config->env('APP_NAME', 'CodeVault'),
            'env' => (string) $config->env('APP_ENV', 'production'),
            'sapi' => PHP_SAPI,
            'pid' => getmypid(),
            'host' => (string) (gethostname() ?: 'unknown'),
        ];
    }

    /** @param array<string, mixed> $context */
    public function add(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->context[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->context;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        // Record context wins: a call site that names its own request_id (a job
        // carrying the id of the request that queued it) must not be overwritten
        // by the process-wide one.
        $record->extra = array_merge($this->context, $record->extra);

        return $record;
    }
}
