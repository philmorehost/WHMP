<?php

declare(strict_types=1);

namespace CodeVault\System;

use CodeVault\Database;
use CodeVault\Request;
use CodeVault\Response;

/**
 * Lightweight liveness/readiness probe for uptime monitors and load
 * balancers. Unlike a normal page it makes no session, no CSRF, no
 * migration run — it answers in milliseconds so a monitor can poll it
 * every few seconds without churn.
 *
 * Exposed as JSON at /health. Returns 200 when the app and database are
 * reachable, 503 when the DB is down so a load balancer can drain the
 * instance. Always succeeds even during maintenance mode — a maintenance
 * window is exactly when an operator wants to know the box is still up.
 */
final class HealthController
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function index(Request $request): Response
    {
        $dbOk = false;

        try {
            $row = $this->db->selectOne('SELECT 1');
            $dbOk = $row !== null && (int) ($row['1'] ?? 0) === 1;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'database' => $dbOk ? 'ok' : 'unreachable',
            'time' => gmdate(DATE_ATOM),
            'app' => 'whmp',
        ];

        return Response::json($payload, $dbOk ? 200 : 503);
    }
}
