<?php

declare(strict_types=1);

namespace CodeVault\Modules\Addons;

use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Database\Migrator;
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
 *
 * Extended (R21) with the operational panels that came up repeatedly while
 * building other features this cycle — server connectivity, provisioning
 * failures, backup health, email delivery, and pending migrations — so an
 * admin has one page to check instead of five: "did the backup run", "is
 * this WHM server even reachable", "why did that domain change fail" and
 * "is mail actually going out" were all previously answerable only by
 * reading raw tables directly.
 */
final class SystemDiagnosticsAddon implements AddonModule
{
    private const FAILURE_LOG_MAX = 20;

    /** fsockopen timeout for the opt-in server ping — a real API call can hang far longer than an admin should wait on a page load. */
    private const SERVER_PING_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly string $basePath,
        private readonly Migrator $migrator
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'System Diagnostics',
            'description' => 'Live health snapshot: PHP/extensions, database and Redis reachability, disk space, cron activity and failures, provisioning server connectivity, services stuck on a provisioning error, backup health, email delivery, and pending migrations.',
            'version' => '1.1.0',
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
        $servers = $this->provisioningServers();
        $checkServers = !empty($params['check_servers']);
        $connectivity = $checkServers ? $this->checkServerConnectivity($servers) : [];
        $provisioningErrors = $this->servicesWithProvisioningError();
        $latestBackup = $this->latestBackup();
        $emailHealth = $this->emailHealth();
        $pendingMigrations = $this->migrator->pending();

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

        $serversHtml = '';
        if ($servers === []) {
            $serversHtml = '<tr><td colspan="4">No provisioning servers configured yet.</td></tr>';
        } else {
            foreach ($servers as $server) {
                $reachBadge = '<span class="cv-badge cv-badge--neutral">Not checked</span>';
                if ($checkServers) {
                    $reachBadge = $okBadge($connectivity[(int) $server['id']] ?? false);
                }
                $serversHtml .= '<tr><td>' . e((string) $server['name']) . '</td>'
                    . '<td>' . e((string) $server['hostname']) . '</td>'
                    . '<td>' . e((string) $server['module_slug']) . '</td>'
                    . '<td>' . ((int) $server['active'] === 1 ? $okBadge(true) : $this->badgeText('inactive')) . ' ' . $reachBadge . '</td></tr>';
            }
        }

        $checkServersLink = $checkServers
            ? '<a class="cv-btn cv-btn--secondary" href="?">Hide connectivity results</a>'
            : '<a class="cv-btn" href="?check_servers=1">Check Server Connectivity Now</a>';

        $provisioningErrorsHtml = '';
        if ($provisioningErrors === []) {
            $provisioningErrorsHtml = '<tr><td colspan="4">No services currently have a provisioning error.</td></tr>';
        } else {
            foreach ($provisioningErrors as $svc) {
                $provisioningErrorsHtml .= '<tr><td>#' . (int) $svc['id'] . '</td>'
                    . '<td>' . e((string) ($svc['domain'] ?? '—')) . '</td>'
                    . '<td>' . e((string) $svc['provisioning_error']) . '</td>'
                    . '<td>' . e((string) $svc['updated_at']) . '</td></tr>';
            }
        }

        $backupHtml = 'No backups have run yet.';
        if ($latestBackup !== null) {
            $statusBadge = match ($latestBackup['status']) {
                'success' => $okBadge(true),
                'failed' => $okBadge(false),
                default => $this->badgeText((string) $latestBackup['status']),
            };
            $sizeLabel = $latestBackup['size_bytes'] !== null
                ? number_format(((int) $latestBackup['size_bytes']) / 1024 / 1024, 1) . ' MB'
                : 'unknown size';
            $backupHtml = $statusBadge . ' ' . e((string) $latestBackup['started_at']) . ' — ' . $sizeLabel
                . ($latestBackup['status'] === 'failed' ? ' — ' . e((string) ($latestBackup['error'] ?? '')) : '');
        }

        $emailRowsHtml = '';
        foreach (['sent', 'failed', 'queued'] as $status) {
            $count = (int) ($emailHealth['counts'][$status] ?? 0);
            $emailRowsHtml .= '<tr><td>' . ucfirst($status) . ' (24h)</td><td>' . number_format($count) . '</td></tr>';
        }
        $emailFailuresHtml = '';
        if ($emailHealth['recentFailures'] === []) {
            $emailFailuresHtml = '<tr><td colspan="3">No recent email failures.</td></tr>';
        } else {
            foreach ($emailHealth['recentFailures'] as $fail) {
                $emailFailuresHtml .= '<tr><td>' . e((string) $fail['to_email']) . '</td>'
                    . '<td>' . e((string) ($fail['error'] ?? '')) . '</td>'
                    . '<td>' . e((string) $fail['created_at']) . '</td></tr>';
            }
        }

        $migrationsHtml = $pendingMigrations === []
            ? $okBadge(true) . ' Schema is up to date.'
            : $okBadge(false) . ' ' . count($pendingMigrations) . ' pending: ' . e(implode(', ', array_slice($pendingMigrations, 0, 5)))
                . (count($pendingMigrations) > 5 ? ' …' : '');

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
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Recent cron failures</h3>
            <table class="cv-table">
                <thead><tr><th>Job</th><th>Error</th><th>When</th></tr></thead>
                <tbody>{$failuresHtml}</tbody>
            </table>
        </div>
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Provisioning servers</h3>
            <p style="color:var(--cv-text-secondary);font-size:0.85rem;">
                Connectivity isn't checked automatically — a real API call can take far longer than a page load
                should wait, so it only runs when you ask for it. {$checkServersLink}
            </p>
            <table class="cv-table">
                <thead><tr><th>Server</th><th>Hostname</th><th>Module</th><th>Status</th></tr></thead>
                <tbody>{$serversHtml}</tbody>
            </table>
        </div>
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Services with a provisioning error</h3>
            <table class="cv-table">
                <thead><tr><th>Service</th><th>Domain</th><th>Error</th><th>When</th></tr></thead>
                <tbody>{$provisioningErrorsHtml}</tbody>
            </table>
        </div>
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Backup health</h3>
            <p style="margin:0;">{$backupHtml}</p>
        </div>
        <div class="cv-card" style="margin-bottom: var(--cv-space-4);">
            <h3 class="cv-card__title">Email delivery</h3>
            <table class="cv-table" style="margin-bottom: var(--cv-space-3);">
                <tbody>{$emailRowsHtml}</tbody>
            </table>
            <table class="cv-table">
                <thead><tr><th>To</th><th>Error</th><th>When</th></tr></thead>
                <tbody>{$emailFailuresHtml}</tbody>
            </table>
        </div>
        <div class="cv-card">
            <h3 class="cv-card__title">Database migrations</h3>
            <p style="margin:0;">{$migrationsHtml}</p>
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

    /** @return array<int, array<string, mixed>> */
    private function provisioningServers(): array
    {
        try {
            return $this->db->select('SELECT id, name, hostname, api_port, use_ssl, module_slug, active FROM servers ORDER BY name ASC');
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A plain TCP connect, not an authenticated API call — enough to answer
     * "is anything even listening here" without the minutes-long worst case
     * a real WHM/API round trip could take against a genuinely dead host.
     *
     * @param array<int, array<string, mixed>> $servers
     * @return array<int, bool> server id => reachable
     */
    private function checkServerConnectivity(array $servers): array
    {
        $results = [];

        foreach ($servers as $server) {
            $port = (int) ($server['api_port'] ?? 2087);
            $host = (string) $server['hostname'];

            $connection = @fsockopen($host, $port, $errno, $errstr, self::SERVER_PING_TIMEOUT_SECONDS);
            $results[(int) $server['id']] = $connection !== false;

            if ($connection !== false) {
                fclose($connection);
            }
        }

        return $results;
    }

    /**
     * Surfaces exactly the services a support admin would otherwise only
     * find by opening each one individually — anything a provisioning
     * action (suspend, terminate, the Domain Name Changer addon, ...) most
     * recently failed against a real server API.
     *
     * @return array<int, array<string, mixed>>
     */
    private function servicesWithProvisioningError(): array
    {
        try {
            return $this->db->select(
                "SELECT id, domain, provisioning_error, updated_at FROM services
                 WHERE provisioning_error IS NOT NULL AND provisioning_error != ''
                 ORDER BY updated_at DESC LIMIT 20"
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    private function latestBackup(): ?array
    {
        try {
            return $this->db->selectOne('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 1');
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{counts: array<string, int>, recentFailures: array<int, array<string, mixed>>} */
    private function emailHealth(): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'queued' => 0];

        try {
            $since = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
            $rows = $this->db->select(
                'SELECT status, COUNT(*) AS c FROM email_log WHERE created_at >= ? GROUP BY status',
                [$since]
            );

            foreach ($rows as $row) {
                $counts[(string) $row['status']] = (int) $row['c'];
            }

            $recentFailures = $this->db->select(
                "SELECT to_email, error, created_at FROM email_log WHERE status = 'failed' ORDER BY id DESC LIMIT 10"
            );
        } catch (Throwable) {
            $recentFailures = [];
        }

        return ['counts' => $counts, 'recentFailures' => $recentFailures];
    }
}
