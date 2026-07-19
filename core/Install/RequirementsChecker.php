<?php

declare(strict_types=1);

namespace CodeVault\Install;

/**
 * Installer Stage 1 — PHP version, required extensions, and writable
 * directories. Every check must pass before Stage 2 (DB config) is reachable.
 */
final class RequirementsChecker
{
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'openssl', 'curl', 'mbstring', 'zip', 'json'];

    private const WRITABLE_PATHS = [
        'storage/cache',
        'storage/sessions',
        'storage/system',
        'storage/backups',
        '.', // for writing .env
    ];

    public function __construct(
        private readonly string $basePath
    ) {
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    public function checkAll(): array
    {
        $results = [];

        $results[] = [
            'label' => 'PHP version >= 8.2',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'detail' => 'Running ' . PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $results[] = [
                'label' => "ext-{$extension}",
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'loaded' : 'missing',
            ];
        }

        foreach (self::WRITABLE_PATHS as $relative) {
            $path = $this->basePath . '/' . $relative;
            $ok = is_dir($path) && is_writable($path);
            $results[] = [
                'label' => "writable: {$relative}",
                'ok' => $ok,
                'detail' => $ok ? 'writable' : (is_dir($path) ? 'not writable' : 'missing directory'),
            ];
        }

        return $results;
    }

    public function allPassed(): bool
    {
        foreach ($this->checkAll() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }
}
