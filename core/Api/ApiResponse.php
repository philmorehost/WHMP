<?php

declare(strict_types=1);

namespace CodeVault\Api;

use CodeVault\Response;

/**
 * Standard envelope every API action returns (blueprint §3/§7 — the API
 * response shape is frozen early so 300+ actions stay consistent).
 */
final class ApiResponse
{
    public static function success(array $data = [], int $status = 200): Response
    {
        return Response::json([
            'status' => 'success',
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, string $code = 'ERROR', int $status = 400, array $details = []): Response
    {
        $payload = [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return Response::json($payload, $status);
    }
}
