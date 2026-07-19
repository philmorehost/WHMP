<?php

declare(strict_types=1);

use CodeVault\Billing\BillableItemController;
use CodeVault\Support\AnnouncementController;
use CodeVault\Support\CannedReplyController;
use CodeVault\Support\ClientTicketController;
use CodeVault\Support\DepartmentController;
use CodeVault\Support\MailPipingSettingsController;
use CodeVault\Support\NetworkIssueController;
use CodeVault\Support\PublicStatusController;
use CodeVault\Support\TicketController;

/** @var CodeVault\Router $router */

$router->get('/admin/tickets', [TicketController::class, 'index']);
$router->get('/admin/tickets/{id}', [TicketController::class, 'show']);
$router->post('/admin/tickets/{id}/reply', [TicketController::class, 'reply']);
$router->post('/admin/tickets/{id}/close', [TicketController::class, 'close']);
$router->post('/admin/tickets/{id}/reopen', [TicketController::class, 'reopen']);
$router->post('/admin/tickets/{id}/assign', [TicketController::class, 'assign']);
$router->post('/admin/tickets/{id}/priority', [TicketController::class, 'setPriority']);
$router->post('/admin/tickets/{id}/department', [TicketController::class, 'setDepartment']);
$router->post('/admin/tickets/{id}/billable', [TicketController::class, 'convertToBillable']);
$router->post('/admin/tickets/{id}/ai-suggest', [TicketController::class, 'aiSuggest']);

$router->get('/admin/departments', [DepartmentController::class, 'index']);
$router->post('/admin/departments', [DepartmentController::class, 'store']);
$router->post('/admin/departments/{id}/delete', [DepartmentController::class, 'destroy']);

$router->get('/admin/billable-items', [BillableItemController::class, 'index']);

$router->get('/admin/canned-replies', [CannedReplyController::class, 'index']);
$router->post('/admin/canned-replies', [CannedReplyController::class, 'store']);
$router->post('/admin/canned-replies/{id}', [CannedReplyController::class, 'update']);
$router->post('/admin/canned-replies/{id}/delete', [CannedReplyController::class, 'destroy']);

$router->get('/admin/announcements', [AnnouncementController::class, 'index']);
$router->post('/admin/announcements', [AnnouncementController::class, 'store']);
$router->post('/admin/announcements/{id}/delete', [AnnouncementController::class, 'destroy']);

$router->get('/admin/network-issues', [NetworkIssueController::class, 'index']);
$router->post('/admin/network-issues', [NetworkIssueController::class, 'store']);
$router->post('/admin/network-issues/{id}/status', [NetworkIssueController::class, 'updateStatus']);
$router->post('/admin/network-issues/{id}/delete', [NetworkIssueController::class, 'destroy']);

$router->get('/status', [PublicStatusController::class, 'index']);

$router->get('/admin/mail-piping', [MailPipingSettingsController::class, 'index']);
$router->post('/admin/mail-piping', [MailPipingSettingsController::class, 'store']);

$router->get('/client/tickets', [ClientTicketController::class, 'index']);
$router->get('/client/tickets/create', [ClientTicketController::class, 'create']);
$router->post('/client/tickets', [ClientTicketController::class, 'store']);
$router->get('/client/tickets/{id}', [ClientTicketController::class, 'show']);
$router->post('/client/tickets/{id}/reply', [ClientTicketController::class, 'reply']);
$router->post('/client/tickets/{id}/rate', [ClientTicketController::class, 'rate']);
