<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientContactRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Gdpr\DataErasureService;
use CodeVault\Gdpr\DataExportService;
use CodeVault\Gdpr\DataPruningJob;
use CodeVault\Gdpr\GdprRequestRepository;
use CodeVault\Gdpr\GdprSettings;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\PasswordResetToken;
use CodeVault\Security\PasswordResetTokenRepository;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class GdprTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private ClientContactRepository $contacts;
    private GdprRequestRepository $requests;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);
        $this->contacts = new ClientContactRepository($this->db);
        $this->requests = new GdprRequestRepository($this->db);

        $this->clientId = $this->clients->create([
            'email' => 'gdpr-subject@example.test',
            'password' => 'correct-horse-battery',
            'first_name' => 'Data',
            'last_name' => 'Subject',
            'phone' => '+1 555 0100',
        ]);
    }

    // --- GdprRequestRepository -------------------------------------------

    public function test_create_defaults_to_pending_and_forClient_returns_it(): void
    {
        $id = $this->requests->create($this->clientId, 'export');
        $row = $this->requests->find($id);

        $this->assertSame('pending', $row['status']);
        $this->assertSame('export', $row['type']);

        $forClient = $this->requests->forClient($this->clientId);
        $this->assertCount(1, $forClient);
    }

    public function test_all_joins_the_client_email(): void
    {
        $this->requests->create($this->clientId, 'erasure');

        $all = $this->requests->all();
        $this->assertCount(1, $all);
        $this->assertSame('gdpr-subject@example.test', $all[0]['client_email']);
    }

    public function test_mark_completed_and_mark_rejected(): void
    {
        $exportId = $this->requests->create($this->clientId, 'export');
        $this->requests->markCompleted($exportId, 1, '{"ok":true}', null);
        $completed = $this->requests->find($exportId);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame('{"ok":true}', $completed['export_data']);

        $erasureId = $this->requests->create($this->clientId, 'erasure');
        $this->requests->markRejected($erasureId, 1, 'not verified');
        $rejected = $this->requests->find($erasureId);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('not verified', $rejected['admin_notes']);
    }

    // --- DataExportService --------------------------------------------

    public function test_export_returns_null_for_a_nonexistent_client(): void
    {
        $export = $this->exportService()->export(999999);

        $this->assertNull($export);
    }

    public function test_export_includes_profile_without_secrets_and_related_records(): void
    {
        $this->contacts->create($this->clientId, 'Assistant', 'assistant@example.test');

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $groupId = (int) $this->db->insert('INSERT INTO product_groups (name, created_at, updated_at) VALUES (?, ?, ?)', ['Hosting', $now, $now]);
        $productId = (int) $this->db->insert('INSERT INTO products (product_group_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)', [$groupId, 'Starter Hosting', $now, $now]);
        $this->db->insert(
            'INSERT INTO services (client_id, product_id, product_name, billing_cycle, amount, status, next_due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, $productId, 'Starter Hosting', 'monthly', 9.99, 'active', substr($now, 0, 10), $now, $now]
        );
        $this->db->insert(
            'INSERT INTO domains (client_id, domain_name, tld, registrar_slug, status, next_due_date, auto_renew, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'example', 'com', 'local', 'active', substr($now, 0, 10), 1, 12.00, $now, $now]
        );
        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'unpaid', 9.99, 9.99, substr($now, 0, 10), $now, $now]
        );
        $this->db->insert('INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)', [$invoiceId, 'Starter Hosting', 9.99]);
        $deptId = (int) $this->db->insert('INSERT INTO departments (name, created_at, updated_at) VALUES (?, ?, ?)', ['Support', $now, $now]);
        $this->db->insert(
            'INSERT INTO tickets (client_id, email, department_id, subject, status, priority, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->clientId, 'gdpr-subject@example.test', $deptId, 'Help please', 'open', 'medium', $now, $now]
        );
        (new ActivityLogger($this->db))->log('client', $this->clientId, 'client.login', 'client', $this->clientId, 'Logged in', '203.0.113.1');

        $export = $this->exportService()->export($this->clientId);

        $this->assertNotNull($export);
        $this->assertSame('gdpr-subject@example.test', $export['profile']['email']);
        $this->assertArrayNotHasKey('password_hash', $export['profile']);
        $this->assertCount(1, $export['contacts']);
        $this->assertCount(1, $export['services']);
        $this->assertCount(1, $export['domains']);
        $this->assertCount(1, $export['invoices']);
        $this->assertCount(1, $export['invoices'][0]['items']);
        $this->assertCount(1, $export['tickets']);
        $this->assertCount(1, $export['activity_log']['entries']);
    }

    // --- DataErasureService ----------------------------------------------

    public function test_erase_returns_false_for_a_nonexistent_client(): void
    {
        $erased = $this->erasureService()->erase(999999);

        $this->assertFalse($erased);
    }

    public function test_erase_scrubs_pii_deletes_contacts_and_disables_login(): void
    {
        $this->contacts->create($this->clientId, 'Assistant', 'assistant@example.test');
        $originalHash = $this->clients->find($this->clientId)['password_hash'];

        $erased = $this->erasureService()->erase($this->clientId);

        $this->assertTrue($erased);

        $client = $this->clients->find($this->clientId);
        $this->assertSame("deleted-{$this->clientId}@erased.invalid", $client['email']);
        $this->assertSame('Erased', $client['first_name']);
        $this->assertNull($client['phone']);
        $this->assertSame('closed', $client['status']);
        $this->assertNotSame($originalHash, $client['password_hash']);
        $this->assertFalse(password_verify('correct-horse-battery', $client['password_hash']), 'the old password must no longer work');

        $this->assertCount(0, $this->contacts->forClient($this->clientId));
    }

    // --- DataPruningJob ----------------------------------------------

    public function test_pruning_job_deletes_only_rows_older_than_the_configured_threshold(): void
    {
        $activity = new ActivityLogger($this->db);
        $loginAttempts = new LoginAttemptRepository($this->db);
        $emailLog = new EmailLogRepository($this->db);
        $resetTokens = new PasswordResetTokenRepository($this->db);
        $settings = new GdprSettings(new SettingsRepository($this->db));
        $settings->save(30, 30, 30);

        // One old row and one recent row per table.
        $old = (new DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s');
        $recent = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        $this->db->insert('INSERT INTO activity_log (actor_type, action, description, created_at) VALUES (?, ?, ?, ?)', ['system', 'x', 'old', $old]);
        $this->db->insert('INSERT INTO activity_log (actor_type, action, description, created_at) VALUES (?, ?, ?, ?)', ['system', 'x', 'recent', $recent]);

        $this->db->insert('INSERT INTO security_login_attempts (ip_address, successful, created_at) VALUES (?, ?, ?)', ['203.0.113.9', 1, $old]);
        $this->db->insert('INSERT INTO security_login_attempts (ip_address, successful, created_at) VALUES (?, ?, ?)', ['203.0.113.9', 1, $recent]);

        $this->db->insert('INSERT INTO email_log (to_email, subject, status, created_at) VALUES (?, ?, ?, ?)', ['a@example.test', 's', 'sent', $old]);
        $this->db->insert('INSERT INTO email_log (to_email, subject, status, created_at) VALUES (?, ?, ?, ?)', ['a@example.test', 's', 'sent', $recent]);

        $resetToken = new PasswordResetToken();
        $this->db->insert(
            'INSERT INTO password_reset_tokens (account_type, account_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            ['client', $this->clientId, $resetToken->hash('expired-token'), $old, $old]
        );

        $job = new DataPruningJob($settings, $activity, $loginAttempts, $emailLog, $resetTokens);
        $job->handle();

        $this->assertCount(1, $this->db->select('SELECT * FROM activity_log'));
        $this->assertSame('recent', $this->db->select('SELECT * FROM activity_log')[0]['description']);

        $this->assertCount(1, $this->db->select('SELECT * FROM security_login_attempts'));
        $this->assertCount(1, $this->db->select('SELECT * FROM email_log'));
        $this->assertCount(0, $this->db->select('SELECT * FROM password_reset_tokens'), 'the expired-and-unconsumed token must be swept');
    }

    public function test_pruning_job_reports_a_stable_name_and_frequency(): void
    {
        $job = new DataPruningJob(
            new GdprSettings(new SettingsRepository($this->db)),
            new ActivityLogger($this->db),
            new LoginAttemptRepository($this->db),
            new EmailLogRepository($this->db),
            new PasswordResetTokenRepository($this->db)
        );

        $this->assertSame('gdpr-data-pruning', $job->name());
        $this->assertSame(1440, $job->frequencyMinutes());
    }

    private function exportService(): DataExportService
    {
        return new DataExportService(
            $this->clients,
            $this->contacts,
            new ServiceRepository($this->db),
            new DomainRepository($this->db),
            new InvoiceRepository($this->db),
            new TicketRepository($this->db),
            new ActivityLogger($this->db)
        );
    }

    private function erasureService(): DataErasureService
    {
        return new DataErasureService($this->clients, $this->contacts);
    }
}
