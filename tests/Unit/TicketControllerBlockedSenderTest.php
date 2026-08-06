<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Request;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Staff\RoleRepository;
use CodeVault\Support\BlockedEmailSenderRepository;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketController;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The support ticket pages let an admin block a sender (one-click from a
 * ticket, or by adding a pattern on the tickets list) so mail piping stops
 * turning that sender's messages — bounce loops, spam, wrong-party mail —
 * into tickets. These lock in the controller wiring, permission gate, and
 * redirects.
 */
final class TicketControllerBlockedSenderTest extends DatabaseTestCase
{
    private TicketController $controller;
    private TicketRepository $tickets;
    private DepartmentRepository $departments;
    private BlockedEmailSenderRepository $blockedSenders;
    private int $departmentId;
    private string $emptyConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        \CodeVault\Support\App::container()->instance(\CodeVault\Database::class, $this->db);

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $_SESSION = [];
        $this->emptyConfigDir = sys_get_temp_dir() . '/codevault-ticket-blocked-test-' . uniqid();
        mkdir($this->emptyConfigDir);
        $session = new SessionManager(new Config($this->emptyConfigDir));

        $roles = new RoleRepository($this->db);
        $roleId = $roles->create('Owner', true, []);
        $adminId = (new AdminRepository($this->db))->create('ops', 'ops@example.test', 'secret123', 'Ops Admin', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $this->tickets = new TicketRepository($this->db);
        $this->departments = new DepartmentRepository($this->db);
        $this->blockedSenders = new BlockedEmailSenderRepository($this->db);
        $this->departmentId = $this->departments->create('General Support', 'support@example.test');

        $this->controller = \CodeVault\Support\App::container()->make(TicketController::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @rmdir($this->emptyConfigDir);
        parent::tearDown();
    }

    private function createTicket(string $email = 'Mailer-Daemon@whiterider.pmhserver.name.ng'): int
    {
        return $this->tickets->create([
            'email' => $email,
            'department_id' => $this->departmentId,
            'subject' => 'Undelivered Mail Returned to Sender',
            'status' => 'open',
        ]);
    }

    public function test_block_sender_blocks_the_ticket_email_and_redirects_back(): void
    {
        $ticketId = $this->createTicket();

        $response = $this->controller->blockSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $ticketId]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame("/admin/tickets/{$ticketId}?blocked=1", $response->headers()['Location']);
        $this->assertTrue($this->blockedSenders->isBlocked('mailer-daemon@whiterider.pmhserver.name.ng'));
        $this->assertNotNull($this->blockedSenders->matchingPattern('Mailer-Daemon@whiterider.pmhserver.name.ng'));
    }

    public function test_block_sender_records_the_source_ticket(): void
    {
        $ticketId = $this->createTicket();

        $this->controller->blockSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $ticketId]
        );

        $blocked = $this->blockedSenders->all();
        $this->assertCount(1, $blocked);
        $this->assertSame($ticketId, (int) $blocked[0]['source_ticket_id']);
        $this->assertStringContainsString("ticket #{$ticketId}", (string) $blocked[0]['reason']);
    }

    public function test_block_sender_with_no_email_redirects_with_an_error_flag(): void
    {
        $ticketId = $this->createTicket('');
        $this->db->update('UPDATE tickets SET email = NULL WHERE id = ?', [$ticketId]);

        $response = $this->controller->blockSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $ticketId]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame("/admin/tickets/{$ticketId}?blocked=0", $response->headers()['Location']);
        $this->assertSame([], $this->blockedSenders->all());
    }

    public function test_block_sender_returns_404_for_a_missing_ticket(): void
    {
        $response = $this->controller->blockSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => 999999]
        );

        $this->assertSame(404, $response->status());
    }

    public function test_add_blocked_sender_blocks_a_wildcard_pattern(): void
    {
        $response = $this->controller->addBlockedSender(
            new Request([], ['pattern' => '*@pmhserver.name.ng'], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], [])
        );

        $this->assertSame(302, $response->status());
        $this->assertSame('/admin/tickets?blocked_added=1', $response->headers()['Location']);
        $this->assertTrue($this->blockedSenders->isBlocked('Mailer-Daemon@whiterider.pmhserver.name.ng'));
    }

    public function test_add_blocked_sender_rejects_an_empty_pattern(): void
    {
        $response = $this->controller->addBlockedSender(
            new Request([], ['pattern' => '   '], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], [])
        );

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('block_error=', $response->headers()['Location']);
        $this->assertSame([], $this->blockedSenders->all());
    }

    public function test_remove_blocked_sender_unblocks_and_redirects(): void
    {
        $id = $this->blockedSenders->block('spam@example.com');

        $response = $this->controller->removeBlockedSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $id]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame('/admin/tickets?blocked_removed=1', $response->headers()['Location']);
        $this->assertFalse($this->blockedSenders->isBlocked('spam@example.com'));
    }

    public function test_block_sender_requires_the_tickets_manage_permission(): void
    {
        $roleId = (new RoleRepository($this->db))->create('Support Agent', false, [PermissionRegistry::CLIENTS_VIEW]);
        $adminId = (new AdminRepository($this->db))->create('support', 'support@example.test', 'secret123', 'Support', $roleId);
        $_SESSION['admin_id'] = $adminId;

        $ticketId = $this->createTicket();
        $response = $this->controller->blockSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $ticketId]
        );

        $this->assertSame(403, $response->status());
        $this->assertFalse($this->blockedSenders->isBlocked('mailer-daemon@whiterider.pmhserver.name.ng'));
    }

    public function test_block_sender_redirects_to_login_when_not_authenticated(): void
    {
        unset($_SESSION['admin_id']);

        $ticketId = $this->createTicket();
        $response = $this->controller->blockSender(
            new Request([], [], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], []),
            ['id' => $ticketId]
        );

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->headers()['Location']);
        $this->assertFalse($this->blockedSenders->isBlocked('mailer-daemon@whiterider.pmhserver.name.ng'));
    }
}
