<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Reseller\ClientMigrationRepository;
use CodeVault\Reseller\ClientMigrationService;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use CodeVault\Tests\Support\CapturingQueue;
use CodeVault\Tests\Support\ScriptedDatabase;
use CodeVault\View;
use PHPUnit\Framework\TestCase;

/**
 * The account-move pages render with the data their controllers actually pass: the
 * preview and destination list straight from ClientMigrationService, and request rows
 * with every column of client_migrations. Any undefined index or bad type shows up here
 * as a PHP diagnostic instead of as a broken admin page.
 */
final class ClientMigrationViewsTest extends TestCase
{
    private View $view;
    private ?string $savedAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new View(dirname(__DIR__, 2) . '/resources/views');
        $this->savedAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://philmorehost.test';

        // The forms call csrf_field(), which resolves the token from the service locator.
        $config = new Config(sys_get_temp_dir() . '/codevault-views-noenv-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->savedAppUrl;
        }

        parent::tearDown();
    }

    private function service(): ClientMigrationService
    {
        $db = (new ScriptedDatabase())
            ->on('/FROM clients c\b/', static fn (array $b): array => (int) $b[0] === 42
                ? [['id' => 42, 'reseller_id' => 7, 'email' => 'jane@customer.test', 'first_name' => 'Jane', 'last_name' => 'O\'Neil <b>']]
                : [])
            ->on('/FROM resellers WHERE id/', static fn (array $b): array => [['id' => (int) $b[0], 'client_id' => 500 + (int) $b[0], 'slug' => 's' . $b[0], 'brand_name' => 'Store ' . $b[0], 'status' => 'active']])
            ->on('/FROM resellers WHERE client_id/', [])
            ->on('/FROM resellers/', [
                ['id' => 7, 'slug' => 'acme', 'brand_name' => 'Acme & Co', 'status' => 'active'],
                ['id' => 8, 'slug' => 'zeta', 'brand_name' => '', 'status' => 'active'],
            ])
            ->on('/COUNT\(\*\) AS n/', [['n' => 2]]);

        $config = new Config(sys_get_temp_dir() . '/codevault-views-noenv-' . uniqid());
        $stores = new ResellerStoreRepository($db);
        $tickets = new TicketRepository($db);

        return new ClientMigrationService(
            $db,
            new ClientMigrationRepository($db),
            $stores,
            new ResellerStoreLocator($stores, $config),
            new ClientRepository($db),
            new TicketService($tickets, new TicketReplyRepository($db), new HookDispatcher(), new TicketAttachmentRepository($db)),
            $tickets,
            new EmailDispatcher(new EmailTemplateRepository($db), new EmailLogRepository($db), new CapturingQueue())
        );
    }

    /** A request row with every column client_migrations has. */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5, 'client_id' => 42, 'client_email' => 'jane@customer.test',
            'from_reseller_id' => 7, 'from_label' => 'Acme & Co', 'target' => 'store', 'to_reseller_id' => 8,
            'target_label' => 'Zeta', 'target_input' => 'zeta.philmorehost.test', 'requested_by' => 'client',
            'requester_client_id' => 42, 'ticket_id' => 91, 'reason' => 'Cheaper <script>', 'status' => 'pending',
            'admin_id' => null, 'decision_note' => null, 'summary' => null, 'decided_at' => null,
            'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
            'first_name' => 'Jane', 'last_name' => 'Doe', 'current_reseller_id' => 7,
        ];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function render(string $template, array $data): array
    {
        $diagnostics = [];
        set_error_handler(static function (int $number, string $message, string $file = '', int $line = 0) use (&$diagnostics): bool {
            $diagnostics[] = $message . ' @ ' . basename($file) . ':' . $line;

            return true;
        });

        try {
            $html = $this->view->render($template, $data);
        } finally {
            restore_error_handler();
        }

        return [$html, $diagnostics];
    }

    public function test_the_admin_queue_renders_pending_and_decided_requests(): void
    {
        $service = $this->service();
        $decided = $this->row([
            'id' => 4, 'status' => 'completed', 'decided_at' => '2026-10-02 10:00:00',
            'summary' => json_encode(['services' => 3, 'domains' => 2, 'invoices' => 5, 'tickets' => 1]),
        ]);
        $rejected = $this->row(['id' => 3, 'status' => 'rejected', 'decision_note' => 'Could not verify', 'requested_by' => 'reseller', 'client_id' => null]);

        foreach ([true, false] as $canDecide) {
            [$html, $diagnostics] = $this->render('reseller.admin-migrations', [
                'pending' => [$this->row(), $this->row(['id' => 6, 'client_id' => null, 'first_name' => null, 'last_name' => null, 'target' => 'platform', 'to_reseller_id' => null, 'ticket_id' => null])],
                'decided' => [$decided, $rejected],
                'destinations' => $service->destinations(),
                'canDecide' => $canDecide,
                'notice' => 'Moved.',
                'error' => null,
            ]);

            $this->assertSame([], $diagnostics);
            $this->assertStringContainsString('jane@customer.test', $html);
            $this->assertStringContainsString('Could not verify', $html);
            $this->assertStringContainsString('Acme &amp; Co', $html);
            $this->assertStringNotContainsString('<script>', $html, 'request text must be escaped');
        }
    }

    public function test_the_review_page_renders_a_valid_preview(): void
    {
        $service = $this->service();
        $preview = $service->preview(42, ClientMigrationService::TARGET_STORE, 8);
        $this->assertTrue($preview['ok'], (string) ($preview['error'] ?? ''));

        [$html, $diagnostics] = $this->render('reseller.admin-migration-review', [
            'preview' => $preview,
            'client' => ['id' => 42, 'email' => 'jane@customer.test', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            'clientInput' => '42',
            'requestRow' => $this->row(),
            'target' => ClientMigrationService::TARGET_STORE,
            'toStoreId' => 8,
            'destinations' => $service->destinations(),
            'canDecide' => true,
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('/admin/resellers/migrations/execute', $html);
        $this->assertStringNotContainsString('<b>', $html, 'client names must be escaped');
    }

    public function test_the_review_page_renders_a_refusal_and_hides_the_button_from_non_super_admins(): void
    {
        $service = $this->service();

        [$html, $diagnostics] = $this->render('reseller.admin-migration-review', [
            'preview' => ['ok' => false, 'error' => 'No client matches "x".'],
            'client' => null,
            'clientInput' => 'x',
            'requestRow' => null,
            'target' => ClientMigrationService::TARGET_PLATFORM,
            'toStoreId' => null,
            'destinations' => $service->destinations(),
            'canDecide' => false,
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('No client matches', $html);
        $this->assertStringNotContainsString('/admin/resellers/migrations/execute', $html);
    }

    public function test_the_resellers_page_renders_requests_in_both_directions(): void
    {
        [$html, $diagnostics] = $this->render('reseller.client-migrations', [
            'store' => ['id' => 8, 'slug' => 'zeta', 'brand_name' => 'Zeta'],
            'requests' => [
                $this->row(['requested_by' => 'reseller', 'requester_client_id' => 508]),
                $this->row(['id' => 9, 'to_reseller_id' => null, 'target' => 'platform', 'status' => 'rejected', 'decision_note' => 'Owner said no', 'ticket_id' => null]),
            ],
            'notice' => null,
            'error' => 'Enter an email.',
            'old' => ['direction' => 'out', 'client_email' => 'a@b.test', 'provider_website' => '', 'reason' => ''],
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('Owner said no', $html);
        $this->assertStringContainsString('a@b.test', $html);
    }

    public function test_the_customers_page_offers_the_form_until_a_request_is_waiting(): void
    {
        [$html, $diagnostics] = $this->render('support.client-account-move', ['requests' => [], 'notice' => null, 'error' => null, 'old' => []]);

        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('action="/client/account-move"', $html);

        // One request at a time: a second would just be a duplicate ticket.
        [$html, $diagnostics] = $this->render('support.client-account-move', [
            'requests' => [$this->row(), $this->row(['id' => 2, 'status' => 'completed', 'ticket_id' => null])],
            'notice' => null,
            'error' => null,
            'old' => [],
        ]);

        $this->assertSame([], $diagnostics);
        $this->assertStringNotContainsString('action="/client/account-move"', $html);
        $this->assertStringContainsString('already have a request waiting', $html);
        $this->assertStringContainsString('/client/tickets/91', $html);
    }

    public function test_registration_says_which_kind_of_existing_account_it_is_without_naming_a_provider(): void
    {
        $base = ['error' => 'An account with that email already exists.', 'googleUser' => null, 'googleClientId' => '', 'refCode' => ''];

        [$html, $diagnostics] = $this->render('client-auth.register', $base + ['accountExists' => 'other_provider']);
        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('already registered with another provider on this platform', $html);
        $this->assertStringContainsString('account move', $html);

        [$html] = $this->render('client-auth.register', $base + ['accountExists' => 'same_site']);
        $this->assertStringContainsString('You already have an account with this email address', $html);
        $this->assertStringNotContainsString('another provider', $html);

        // Any other error still shows as before.
        [$html, $diagnostics] = $this->render('client-auth.register', ['error' => 'Passwords do not match.'] + $base);
        $this->assertSame([], $diagnostics);
        $this->assertStringContainsString('Passwords do not match.', $html);
    }
}
