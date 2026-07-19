<?php

declare(strict_types=1);

namespace CodeVault\Modules\Addons;

use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\AddonModule;
use Throwable;

/**
 * The reference AddonModule implementation (R20) — proves the SDK end-to-end
 * with something genuinely useful rather than a toy: a live operational
 * health snapshot pulling together checks this app already makes silently
 * (Redis/IMAP extension presence, which driver each subsystem fell back to,
 * whether the DB is actually reachable) into one admin-visible page, plus
 * cron activity read from CronScheduler's own state file and a small
 * failure log this addon's own hooks() listener maintains.
 */
final class SystemDiagnosticsAddon implements AddonModule
{
    private const FAILURE_LOG_MAX = 20;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly string $basePath
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'System Diagnostics',
            'description' => 'Live health snapshot: PHP/extensions, database and Redis reachability, disk space, cron activity, and recent cron job failures.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        return ['success' => true, 'message' => 'System Diagnostics activated.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'System Diagnostics deactivated.'];
    }

    /**
     * Only wired into the HookDispatcher while this addon is active
     * (AddonModuleService::bootActiveAddons) — a deactivated addon's cron
     * failures are silently un-tracked, same as any disabled listener.
     */
    public function hooks(): array
    {
        return [
            HookPoints::CRON_JOB_FINISHED => function (array $payload): void {
                $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];

                if (!empty($result['ran']) || empty($result['error'])) {
                    return;
                }

                $this->recordFailure((string) ($payload['job'] ?? 'unknown'), (string) $result['error']);
            },
        ];
    }

    public function render(array $params): string
    {
        $extensions = $this->extensionChecklist();
        $database = $this->databaseCheck();
        $redis = $this->redisCheck();
        $disk = $this->diskCheck();
        $cronRuns = $this->cronRuns();
        $failures = $this->recentFailures();

        $rows = static function (array $entries, callable $badge): string {
            $html = '';
            foreach ($entries as $label => $status) {
                $html .= '<tr><td>' . e((string) $label) . '</td><td>' . $badge($status) . '</td></tr>';
            }

            return $html;
        };

        $okBadge = static fn (bool $ok): string => $ok
            ? '<span class="cv-badge cv-badge--success">OK</span>'
            : '<span class="cv-badge cv-badge--danger">Unavailable</span>';

        $cronRowsHtml = '';
        if ($cronRuns === []) {
            $cronRowsHtml = '<tr><td colspan="2">No cron runs recorded yet — verify your OS cron entry points at bin/cron.php.</td></tr>';
        } else {
            foreach ($cronRuns as $job => $timestamp) {
                $cronRowsHtml .= '<tr><td>' . e((string) $job) . '</td><td>' . e(date('Y-m-d H:i:s', (int) $timestamp)) . '</td></tr>';
            }
        }

        $failuresHtml = '';
        if ($failures === []) {
            $failuresHtml = '<tr><td colspan="3">No recorded cron failures.</td></tr>';
        } else {
            foreach (array_reverse($failures) as $failure) {
                $failuresHtml .= '<tr><td>' . e((string) $failure['job']) . '</td>'
                    . '<td>' . e((string) $failure['error']) . '</td>'
                    . '<td>' . e((string) $failure['at']) . '</td></tr>';
            }
        }

        return <<<HTML
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Environment</h3>
            <table class="cv-table">
                <tbody>
                    <tr><td>PHP version</td><td>{$this->badgeText(PHP_VERSION)}</td></tr>
                    <tr><td>Session driver</td><td>{$this->badgeText((string) ($this->config->env('SESSION_DRIVER') ?? 'file'))}</td></tr>
                    <tr><td>Queue driver</td><td>{$this->badgeText((string) ($this->config->env('QUEUE_DRIVER') ?? 'sync'))}</td></tr>
                    <tr><td>Cache driver</td><td>{$this->badgeText((string) ($this->config->env('CACHE_DRIVER') ?? 'array'))}</td></tr>
                    {$rows($extensions, $okBadge)}
                    <tr><td>Database ({$database['driverLabel']})</td><td>{$okBadge($database['ok'])}</td></tr>
                    <tr><td>Redis</td><td>{$okBadge($redis['ok'])} {$this->badgeText($redis['detail'])}</td></tr>
                    <tr><td>Disk free (storage/)</td><td>{$this->badgeText($disk)}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Cron activity (last run per job)</h3>
            <table class="cv-table">
                <tbody>{$cronRowsHtml}</tbody>
            </table>
        </div>
        <div class="cv-card">
            <h3 class="cv-card__title">Recent cron failures</h3>
            <table class="cv-table">
                <thead><tr><th>Job</th><th>Error</th><th>When</th></tr></thead>
                <tbody>{$failuresHtml}</tbody>
            </table>
        </div>
        HTML;
    }

    private function badgeText(string $value): string
    {
        return '<span class="cv-badge cv-badge--neutral">' . e($value) . '</span>';
    }

    /** @return array<string, bool> */
    private function extensionChecklist(): array
    {
        return [
            'ext-redis' => extension_loaded('redis'),
            'ext-imap' => extension_loaded('imap'),
            'ext-curl' => extension_loaded('curl'),
            'ext-pdo_mysql' => extension_loaded('pdo_mysql'),
            'ext-gd' => extension_loaded('gd'),
        ];
    }

    /** @return array{ok: bool, driverLabel: string} */
    private function databaseCheck(): array
    {
        try {
            $this->db->select('SELECT 1 AS ok');

            return ['ok' => true, 'driverLabel' => 'MariaDB/MySQL'];
        } catch (Throwable) {
            return ['ok' => false, 'driverLabel' => 'MariaDB/MySQL'];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function redisCheck(): array
    {
        if (!extension_loaded('redis')) {
            return ['ok' => false, 'detail' => 'ext-redis not loaded — sessions/queue/cache running on their fallback drivers'];
        }

        $host = (string) $this->config->env('REDIS_HOST', '127.0.0.1');
        $port = (int) $this->config->env('REDIS_PORT', 6379);

        try {
            $redis = new \Redis();
            $connected = @$redis->connect($host, $port, 1.0);

            if ($connected) {
                $redis->close();

                return ['ok' => true, 'detail' => "reachable at {$host}:{$port}"];
            }

            return ['ok' => false, 'detail' => "unreachable at {$host}:{$port}"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    private function diskCheck(): string
    {
        $bytes = @disk_free_space($this->basePath . '/storage');
        if ($bytes === false) {
            return 'unknown';
        }

        $gb = $bytes / 1024 / 1024 / 1024;

        return number_format($gb, 2) . ' GB free';
    }

    /** @return array<string, int> job name => unix timestamp, as CronScheduler persists it */
    private function cronRuns(): array
    {
        $path = $this->basePath . '/storage/cache/cron-state.json';
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function recordFailure(string $job, string $error): void
    {
        $path = $this->failureLogPath();
        $failures = $this->recentFailures();

        $failures[] = ['job' => $job, 'error' => $error, 'at' => date('Y-m-d H:i:s')];
        $failures = array_slice($failures, -self::FAILURE_LOG_MAX);

        file_put_contents($path, json_encode($failures, JSON_PRETTY_PRINT));
    }

    /** @return array<int, array{job: string, error: string, at: string}> */
    private function recentFailures(): array
    {
        $path = $this->failureLogPath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function failureLogPath(): string
    {
        return $this->basePath . '/storage/cache/addon-diagnostics-failures.json';
    }
}
