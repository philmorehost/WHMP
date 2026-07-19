<?php

declare(strict_types=1);

use CodeVault\Api\ApiResponse;
use CodeVault\Container;
use CodeVault\Request;
use CodeVault\Response;

/** @var CodeVault\Router $router */

$router->get('/api/ping', function (Request $request, array $params, Container $container): Response {
    return ApiResponse::success([
        'pong' => true,
        'time' => date(DATE_ATOM),
    ]);
});
