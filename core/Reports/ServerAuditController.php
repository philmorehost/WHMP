<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\ServerGroupRepository;
use CodeVault\Database;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

final class ServerAuditController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly Database $db
    ) {
    }

    public function audit(Request $request): Response
    {
        $admin = $this->guard->current();
        if (!$admin) {
            return Response::redirect('/admin/login');
        }

        $servers = $this->db->select(
            'SELECT * FROM servers ORDER BY module_slug, name',
            []
        );

        $grouped = [];
        foreach ($servers as $server) {
            $key = $server['module_slug'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $server;
        }

        $duplicates = array_filter($grouped, fn($list) => count($list) > 1);
        $recommendations = $this->analyzeServers($grouped);

        return Response::html($this->view->render('pages.server-audit', [
            'servers' => $servers,
            'grouped' => $grouped,
            'duplicates' => $duplicates,
            'recommendations' => $recommendations,
        ]));
    }

    private function analyzeServers(array $grouped): array
    {
        $toDelete = [];
        $issues = [];

        foreach ($grouped as $module => $servers) {
            if (count($servers) <= 1) {
                continue;
            }

            $active = array_filter($servers, fn($s) => (bool)$s['is_active']);
            $inactive = array_filter($servers, fn($s) => !(bool)$s['is_active']);

            if (count($active) > 1) {
                $issues[] = "Module '$module': Multiple ACTIVE servers detected";
                foreach (array_slice($active, 1) as $dup) {
                    $toDelete[$dup['id']] = $dup['name'] . ' (duplicate active)';
                }
            }

            foreach ($inactive as $dup) {
                $toDelete[$dup['id']] = $dup['name'] . ' (inactive)';
                $issues[] = "Module '$module': Server '{$dup['name']}' is inactive and duplicate";
            }
        }

        return [
            'toDelete' => $toDelete,
            'issues' => $issues,
            'isClean' => empty($toDelete),
        ];
    }
}
