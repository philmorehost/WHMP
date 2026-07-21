<?php

declare(strict_types=1);

use CodeVault\Auth\AuthController;

/** @var CodeVault\Router $router */

$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/login/2fa', [AuthController::class, 'twoFactorForm']);
$router->post('/login/2fa', [AuthController::class, 'verifyTwoFactor']);
$router->get('/login/recover-pin', [AuthController::class, 'recoverPinForm']);
$router->post('/login/recover-pin', [AuthController::class, 'recoverPin']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/login/forgot-password', [AuthController::class, 'forgotPasswordForm']);
$router->post('/login/forgot-password', [AuthController::class, 'sendResetLink']);
$router->get('/login/password/reset/{token}', [AuthController::class, 'resetPasswordForm']);
$router->post('/login/password/reset/{token}', [AuthController::class, 'resetPassword']);
