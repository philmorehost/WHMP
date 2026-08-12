<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Ai\AiProvider;
use CodeVault\Ai\AiSettings;
use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Cache\ArrayCache;
use CodeVault\Clients\ClientRepository;
use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Domains\DomainRepository;
use CodeVault\Reports\AiInsightsWidget;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class AiInsightsWidgetTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private ArrayCache $cache;
    private ClientRepository $clients;
    private OrderRepository $orders;
    private InvoiceRepository $invoices;
    private TicketRepository $tickets;
    private ServiceRepository $services;
    private DomainRepository $domains;
    private CurrencyRepository $currencies;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->settings = new SettingsRepository($this->db);
        $this->cache = new ArrayCache();
        $this->clients = new ClientRepository($this->db);
        $this->orders = new OrderRepository($this->db);
        $this->invoices = new InvoiceRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->domains = new DomainRepository($this->db);
        $this->currencies = new CurrencyRepository($this->db);
    }

    private function makeWidget(AiProvider $ai): AiInsightsWidget
    {
        return new AiInsightsWidget(
            $ai,
            new AiSettings($this->settings, new Config(dirname(__DIR__, 2))),
            $this->cache,
            $this->clients,
            $this->orders,
            $this->invoices,
            $this->tickets,
            $this->services,
            $this->domains,
            $this->currencies
        );
    }

    private function fakeProvider(bool $success, ?string $text): AiProvider
    {
        return new class ($success, $text) implements AiProvider {
            public function __construct(
                private readonly bool $success,
                private readonly ?string $text
            ) {
            }

            public function complete(string $systemPrompt, string $userPrompt): array
            {
                return $this->success
                    ? ['success' => true, 'text' => $this->text, 'error' => null]
                    : ['success' => false, 'text' => null, 'error' => 'boom'];
            }
        };
    }

    public function test_renders_plain_facts_when_no_ai_key_configured(): void
    {
        $widget = $this->makeWidget($this->fakeProvider(true, 'should not be called'));

        $html = $widget->render();

        $this->assertStringContainsString('AI Insights', $html);
        $this->assertStringContainsString('income this month', $html);
        $this->assertStringContainsString('configure an AI provider key', $html);
        $this->assertStringNotContainsString('should not be called', $html);
    }

    public function test_renders_ai_summary_when_configured_and_successful(): void
    {
        $this->settings->set('ai.api_key', 'test-key');
        $widget = $this->makeWidget($this->fakeProvider(true, 'Strong month: $1,200 income and 3 new clients.'));

        $html = $widget->render();

        $this->assertStringContainsString('Strong month: $1,200 income and 3 new clients.', $html);
        $this->assertStringContainsString('deepseek-chat', $html);
        $this->assertStringNotContainsString('income this month ·', $html);
    }

    public function test_falls_back_to_plain_facts_when_ai_errors(): void
    {
        $this->settings->set('ai.api_key', 'test-key');
        $widget = $this->makeWidget($this->fakeProvider(false, null));

        $html = $widget->render();

        $this->assertStringContainsString('income this month', $html);
        $this->assertStringContainsString('showing live figures instead', $html);
    }

    public function test_ai_summary_is_cached_between_renders(): void
    {
        $this->settings->set('ai.api_key', 'test-key');
        $calls = 0;

        $ai = new class () implements AiProvider {
            public int $calls = 0;

            public function complete(string $systemPrompt, string $userPrompt): array
            {
                $this->calls++;

                return ['success' => true, 'text' => 'cached summary', 'error' => null];
            }
        };

        $widget = $this->makeWidget($ai);

        $widget->render();
        $widget->render();

        $this->assertSame(1, $ai->calls);
    }

    public function test_metadata_and_placement(): void
    {
        $widget = $this->makeWidget($this->fakeProvider(false, null));

        $this->assertSame('dashboard', $widget->placement());
        $this->assertSame('AI Insights', $widget->metadata()['name']);
        $this->assertSame([], $widget->configOptions());
    }

    public function test_describes_income_and_overdue_per_currency_not_summed(): void
    {
        // Default USD is created on demand; NGN is the second currency.
        $this->currencies->default();
        $ngnId = $this->currencies->create('NGN', '₦', 1490.0);

        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $stamp = $now->format('Y-m-d H:i:s');

        $clientId = (int) $this->db->insert(
            "INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES (?, '', 'Multi', 'Currency', 'active', ?, ?)",
            ['multi@example.test', $stamp, $stamp]
        );

        // Paid this month, one bucket per currency.
        $this->db->statement(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at, paid_at) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'paid', 1000.00, null, 1.0000, $now->format('Y-m-d'), $stamp, $stamp, $stamp]
        );
        $this->db->statement(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at, paid_at) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'paid', 50000.00, $ngnId, 1.0000, $now->format('Y-m-d'), $stamp, $stamp, $stamp]
        );

        // Overdue, one bucket per currency.
        $this->db->statement(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'unpaid', 2000.00, null, 1.0000, $yesterday, $stamp, $stamp]
        );
        $this->db->statement(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?)',
            [$clientId, 'unpaid', 30000.00, $ngnId, 1.0000, $yesterday, $stamp, $stamp]
        );

        $this->settings->set('ai.api_key', 'test-key');

        $ai = new class () implements AiProvider {
            public string $prompt = '';

            public function complete(string $systemPrompt, string $userPrompt): array
            {
                $this->prompt = $userPrompt;

                return ['success' => true, 'text' => 'summary', 'error' => null];
            }
        };

        $this->makeWidget($ai)->render();

        // Both currencies appear under their own symbol.
        $this->assertStringContainsString('NGN', $ai->prompt);
        $this->assertStringContainsString('USD', $ai->prompt);
        $this->assertStringContainsString('₦50,000.00', $ai->prompt);
        $this->assertStringContainsString('$1,000.00', $ai->prompt);

        // The naive cross-currency sum (1000 + 50000 = 51,000) must NOT
        // appear as a single figure — that was the wrong-data bug.
        $this->assertStringNotContainsString('51,000', $ai->prompt);
        $this->assertStringContainsString('$2,000.00', $ai->prompt);
        $this->assertStringContainsString('₦30,000.00', $ai->prompt);
    }
}
