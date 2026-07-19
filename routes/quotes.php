<?php

declare(strict_types=1);

use CodeVault\Billing\ClientQuoteController;
use CodeVault\Billing\QuoteController;

/** @var CodeVault\Router $router */

$router->get('/admin/quotes', [QuoteController::class, 'index']);
$router->get('/admin/quotes/create', [QuoteController::class, 'createForm']);
$router->post('/admin/quotes', [QuoteController::class, 'store']);
$router->get('/admin/quotes/{id}', [QuoteController::class, 'show']);
$router->post('/admin/quotes/{id}/send', [QuoteController::class, 'send']);
$router->post('/admin/quotes/{id}/delete', [QuoteController::class, 'destroy']);
$router->get('/admin/quotes/{id}/pdf', [QuoteController::class, 'downloadPdf']);

$router->get('/client/quotes', [ClientQuoteController::class, 'index']);
$router->get('/client/quotes/{id}', [ClientQuoteController::class, 'show']);
$router->post('/client/quotes/{id}/accept', [ClientQuoteController::class, 'accept']);
$router->post('/client/quotes/{id}/decline', [ClientQuoteController::class, 'decline']);
$router->get('/client/quotes/{id}/pdf', [ClientQuoteController::class, 'downloadPdf']);
