<?php

declare(strict_types=1);

namespace CodeVault\Logging;

use Throwable;

/**
 * A small, dependency-free error record: the last N distinct exceptions this
 * install hit, with a count and a first/last-seen timestamp.
 *
 * The structured log is the history; this is the answer to "is anything broken
 * right now?" without grepping it. Same-failure detection is by fingerprint —
 * exception class plus the first line of the message plus the code location —
 * so a loop that throws the same way 5,000 times is one entry with count 5000,
 * not 5,000 entries that push everything else out of the list.
 *
 * Stored as JSON in storage/system/errors.json (bounded by MAX_RECORDS), written
 * with a lock so two concurrent requests cannot interleave a half-written file.
 */
final class ErrorTracker
{
    private const MAX_RECORDS = 50;

    public function __construct(
        private readonly string $path
    ) {
    }

    public function record(Throwable $error, array $context = []): string
    {
        $fingerprint = self::fingerprint($error);

        $state = $this->read();
        $entry = $state[$fingerprint] ?? [
            'fingerprint' => $fingerprint,
            'class' => $error::class,
            'message' => $this->firstLine($error->getMessage()),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'count' => 0,
            'first_seen' => gmdate('c'),
            'context' => [],
        ];

        $entry['count']++;
        $entry['last_seen'] = gmdate('c');
        $entry['context'] = $this->safeContext($context);

        $state[$fingerprint] = $entry;

        // Keep the freshest records; the file must not grow without bound.
        uasort($state, static fn (array $a, array $b): int => strcmp((string) $b['last_seen'], (string) $a['last_seen']));

        $this->write(array_slice($state, 0, self::MAX_RECORDS, true));

        return $fingerprint;
    }

    /**
     * @return array<int, array<string, mixed>> most recent first
     */
    public function recent(int $limit = 20): array
    {
        return array_slice(array_values($this->read()), 0, $limit);
    }

    public function count(): int
    {
        return count($this->read());
    }

    public function totalOccurrences(): int
    {
        return array_sum(array_map(static fn (array $e): int => (int) $e['count'], $this->read()));
    }

    public function clear(): void
    {
        @unlink($this->path);
    }

    public static function fingerprint(Throwable $error): string
    {
        return substr(hash('sha256', implode('|', [
            $error::class,
            self::firstLine($error->getMessage()),
            $error->getFile(),
            (string) $error->getLine(),
        ])), 0, 16);
    }

    private static function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? '' : mb_substr($line, 0, 300);
    }

    /**
     * Context is stored, so it goes through the same key-name redaction the log
     * does — an error report must not become a place secrets leak from.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function safeContext(array $context): array
    {
        $processor = new RedactorProcessor();
        $record = new \Monolog\LogRecord(
            new \Monolog\DateTimeImmutable(),
            'errors',
            \Monolog\Level::Error,
            '',
            $context
        );

        return $processor($record)->context;
    }

    /** @return array<string, array<string, mixed>> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $state */
    private function write(array $state): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
