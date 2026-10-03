<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Reseller\ClientSiteAccess;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Reseller\StoreMailBranding;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\CapturingQueue;
use CodeVault\Tests\Support\ScriptedDatabase;
use PHPUnit\Framework\TestCase;

/**
 * White-label mail: a reseller store's customer must never see the platform's name,
 * address, website or From identity in an email — whichever of the ~50 call sites
 * sent it, and whether or not that call site knew a store was involved.
 *
 * Two halves. The REWRITE rules are pure and tested directly, because their guards
 * (subdomains, ports, look-alike domains, single pass) are where a bug would either
 * leak the platform or break a customer's hosting details. The DISPATCHER half runs
 * the real EmailDispatcher against a scripted database, to prove the decision "whose
 * message is this?" is made for callers that pass nothing at all.
 */
final class StoreMailBrandingTest extends TestCase
{
    private const PLATFORM_URL = 'https://philmorehost.test';

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_URL', 'APP_NAME'] as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['APP_URL'] = self::PLATFORM_URL;
        unset($_ENV['APP_NAME']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function context(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Acme Hosting',
            'email' => 'help@acme.test',
            'url' => 'https://acme.test',
            'host' => 'acme.test',
            'platform_url' => self::PLATFORM_URL,
            'platform_host' => 'philmorehost.test',
            'platform_names' => ['PhilmoreHost', 'CodeVault'],
        ];
    }

    // ------------------------------------------------------------ the rewrite ---

    public function test_links_to_the_platform_site_go_to_the_store_and_keep_their_path(): void
    {
        $out = StoreMailBranding::rebrand('<a href="https://philmorehost.test/client/invoices/12">Pay</a>', $this->context(), true);

        $this->assertSame('<a href="https://acme.test/client/invoices/12">Pay</a>', $out);
    }

    public function test_www_and_http_variants_of_the_platform_site_are_rewritten_too(): void
    {
        $out = StoreMailBranding::rebrand('http://www.philmorehost.test/a and https://philmorehost.test', $this->context(), false);

        $this->assertSame('https://acme.test/a and https://acme.test', $out);
    }

    public function test_real_infrastructure_hostnames_are_left_alone(): void
    {
        // Nameservers and server hostnames are SUBDOMAINS; a control panel carries a
        // port. Rewriting either would break the service the email describes.
        $text = 'Nameservers: ns1.philmorehost.test, ns2.philmorehost.test. Panel: https://philmorehost.test:2083';

        $this->assertSame($text, StoreMailBranding::rebrand($text, $this->context(), false));
    }

    public function test_a_look_alike_domain_is_not_the_platform(): void
    {
        $text = 'Your domain philmorehost.test.ng is active.';

        $this->assertSame($text, StoreMailBranding::rebrand($text, $this->context(), false));
    }

    public function test_an_address_at_the_platform_domain_becomes_the_stores_sender(): void
    {
        $out = StoreMailBranding::rebrand('Write to billing@philmorehost.test any time.', $this->context(), false);

        $this->assertSame('Write to help@acme.test any time.', $out);
    }

    public function test_the_bare_platform_host_written_as_text_becomes_the_store_host(): void
    {
        $out = StoreMailBranding::rebrand('Visit philmorehost.test for help.', $this->context(), false);

        $this->assertSame('Visit acme.test for help.', $out);
    }

    public function test_the_platform_name_is_replaced_as_a_whole_word_in_any_case(): void
    {
        $out = StoreMailBranding::rebrand('Thanks, the PhilmoreHost team. PHILMOREHOST cares. PhilmoreHostPro is unrelated.', $this->context(), false);

        $this->assertSame('Thanks, the Acme Hosting team. Acme Hosting cares. PhilmoreHostPro is unrelated.', $out);
    }

    public function test_the_store_name_is_escaped_in_html_and_raw_in_a_subject(): void
    {
        $context = $this->context(['name' => 'Bits & Bytes']);

        $this->assertSame('<p>Bits &amp; Bytes</p>', StoreMailBranding::rebrand('<p>PhilmoreHost</p>', $context, true));
        $this->assertSame('Welcome to Bits & Bytes', StoreMailBranding::rebrand('Welcome to PhilmoreHost', $context, false));
    }

    public function test_the_rewrite_is_a_single_pass_so_inserted_text_is_never_rewritten_again(): void
    {
        // A store whose own name contains ours, and whose address is at our subdomain.
        $context = $this->context([
            'name' => 'PhilmoreHost Partners',
            'email' => 'noreply@acme.philmorehost.test',
            'url' => 'https://acme.philmorehost.test',
            'host' => 'acme.philmorehost.test',
        ]);

        $out = StoreMailBranding::rebrand('PhilmoreHost: https://philmorehost.test/x support@philmorehost.test', $context, false);

        $this->assertSame('PhilmoreHost Partners: https://acme.philmorehost.test/x noreply@acme.philmorehost.test', $out);
    }

    public function test_names_identifying_the_sender_are_set_to_the_store(): void
    {
        $vars = StoreMailBranding::brandVariables(['company_name' => 'PhilmoreHost', 'first_name' => 'Jane'], ['name' => 'Acme Hosting']);

        $this->assertSame('Acme Hosting', $vars['company_name']);
        $this->assertSame('Acme Hosting', $vars['brand_name']);
        $this->assertSame('Jane', $vars['first_name']);
    }

    // ------------------------------------------------------ the dispatcher ---

    /** @return array{0: EmailDispatcher, 1: CapturingQueue, 2: CurrentReseller} */
    private function dispatcher(ScriptedDatabase $db): array
    {
        $config = new Config(sys_get_temp_dir() . '/codevault-store-mail-noenv-' . uniqid());
        $settings = new SettingsRepository($db);
        $stores = new ResellerStoreRepository($db);
        $current = new CurrentReseller();
        $queue = new CapturingQueue();

        $branding = new StoreMailBranding(
            $db,
            $stores,
            new ResellerStoreLocator($stores, $config),
            $current,
            new ClientSiteAccess($db, $current),
            $settings,
            $config
        );

        $dispatcher = new EmailDispatcher(
            new EmailTemplateRepository($db),
            new EmailLogRepository($db),
            $queue,
            $settings,
            $config,
            null,
            $branding
        );

        return [$dispatcher, $queue, $current];
    }

    private function db(): ScriptedDatabase
    {
        $store = [
            'id' => 7, 'client_id' => 500, 'slug' => 'acme', 'status' => 'active',
            'brand_name' => 'Acme Hosting', 'primary_color' => '#ff6600', 'logo_url' => '/uploads/acme.png',
            'custom_domain' => 'acme.test', 'domain_verified_at' => '2026-01-01 00:00:00',
            'support_email' => 'help@acme.test',
        ];

        return (new ScriptedDatabase())
            ->on('/FROM email_templates/', [[
                'key' => 'client_welcome',
                'subject' => 'Welcome to {{company_name}}, {{first_name}}!',
                'body_html' => '<p>Hi {{first_name}},</p><p>Sign in at <a href="https://philmorehost.test/client/login">https://philmorehost.test/client/login</a>.</p><p>Thanks,<br>PhilmoreHost Team</p>',
            ]])
            ->on('/FROM settings/', static fn (array $b): array => match ($b[0]) {
                'theme.brand_name' => [['value' => 'PhilmoreHost']],
                default => [],
            })
            ->on('/FROM resellers WHERE id/', static fn (array $b): array => (int) $b[0] === 7 ? [$store] : [])
            ->on('/FROM clients WHERE id/', static fn (array $b): array => match ((int) $b[0]) {
                42 => [['id' => 42, 'reseller_id' => 7]],
                43 => [['id' => 43, 'reseller_id' => null]],
                default => [],
            })
            ->on('/FROM clients WHERE email/', static fn (array $b): array => $b[0] === 'jane@customer.test' ? [['id' => 42, 'reseller_id' => 7]] : [])
            ->on('/FROM admins/', static fn (array $b): array => $b[0] === 'staff@philmorehost.test' ? [['id' => 1]] : [])
            // Client 43 has an order, so it is the platform's, not an unclaimed prospect.
            ->on('/FROM orders WHERE client_id/', static fn (array $b): array => (int) $b[0] === 43 ? [['id' => 1]] : []);
    }

    public function test_a_store_customers_email_is_entirely_the_stores_even_when_the_caller_passed_the_platform_name(): void
    {
        [$dispatcher, $queue] = $this->dispatcher($this->db());

        // Exactly how call sites behave today: platform name in, client id only.
        $dispatcher->sendTemplate('client_welcome', 'jane@customer.test', ['first_name' => 'Jane', 'company_name' => 'PhilmoreHost'], 42);

        $job = $queue->only();

        $this->assertSame(['email' => 'help@acme.test', 'name' => 'Acme Hosting'], $job->from);
        $this->assertSame('Welcome to Acme Hosting, Jane!', $job->subject);
        $this->assertStringContainsString('https://acme.test/client/login', $job->html);
        $this->assertStringContainsString('https://acme.test/uploads/acme.png', $job->html, 'the shell carries the store logo');
        $this->assertStringContainsString('#ff6600', $job->html, 'the shell carries the store colour');

        foreach (['PhilmoreHost', 'philmorehost.test', 'CodeVault'] as $leak) {
            $this->assertStringNotContainsString($leak, $job->subject . $job->html, "the platform leaked into a store email: {$leak}");
        }
    }

    public function test_a_store_customer_is_found_from_the_address_when_no_client_id_is_passed(): void
    {
        [$dispatcher, $queue] = $this->dispatcher($this->db());

        $dispatcher->sendTemplate('client_welcome', 'jane@customer.test', ['first_name' => 'Jane']);

        $this->assertSame('help@acme.test', $queue->only()->from['email'] ?? null);
    }

    public function test_staff_mail_stays_the_platforms(): void
    {
        [$dispatcher, $queue, $current] = $this->dispatcher($this->db());
        // Even while a store's site is being served.
        $current->set(['id' => 7, 'slug' => 'acme', 'brand_name' => 'Acme Hosting']);

        $dispatcher->sendTemplate('client_welcome', 'staff@philmorehost.test', ['first_name' => 'Sam', 'company_name' => 'PhilmoreHost']);

        $job = $queue->only();
        $this->assertNull($job->from);
        $this->assertSame('Welcome to PhilmoreHost, Sam!', $job->subject);
    }

    public function test_a_platform_customer_hears_from_the_platform(): void
    {
        [$dispatcher, $queue] = $this->dispatcher($this->db());

        $dispatcher->sendTemplate('client_welcome', 'pat@customer.test', ['first_name' => 'Pat', 'company_name' => 'PhilmoreHost'], 43);

        $this->assertNull($queue->only()->from);
        $this->assertStringContainsString('PhilmoreHost', $queue->only()->subject);
    }

    public function test_someone_with_no_account_yet_on_a_store_site_hears_from_the_store(): void
    {
        // The registration code: sent before the account exists.
        [$dispatcher, $queue, $current] = $this->dispatcher($this->db());
        $current->set(['id' => 7, 'slug' => 'acme', 'brand_name' => 'Acme Hosting', 'support_email' => 'help@acme.test',
            'custom_domain' => 'acme.test', 'domain_verified_at' => '2026-01-01 00:00:00']);

        $dispatcher->sendTemplate('client_welcome', 'new@person.test', ['first_name' => 'New', 'company_name' => 'PhilmoreHost']);

        $this->assertSame(['email' => 'help@acme.test', 'name' => 'Acme Hosting'], $queue->only()->from);
    }

    public function test_a_caller_that_knows_whose_message_it_is_overrides_the_guess(): void
    {
        [$dispatcher, $queue] = $this->dispatcher($this->db());

        $dispatcher->onBehalfOfPlatform()->sendTemplate('client_welcome', 'jane@customer.test', ['first_name' => 'Jane'], 42);
        $this->assertNull($queue->only()->from, 'forced platform must not be re-guessed as the store');

        $queue->jobs = [];
        $dispatcher->onBehalfOfStore(['id' => 9, 'slug' => 'zeta', 'brand_name' => ''])->sendTemplate('client_welcome', 'guest@x.test', ['first_name' => 'G']);

        // No brand name and no support address: still nothing of ours — the slug, on
        // the store's own subdomain.
        $this->assertSame(['email' => 'noreply@zeta.philmorehost.test', 'name' => 'Zeta'], $queue->only()->from);
    }

    public function test_the_shared_dispatcher_is_not_changed_by_a_forced_copy(): void
    {
        [$dispatcher, $queue] = $this->dispatcher($this->db());

        $dispatcher->onBehalfOfPlatform();
        $dispatcher->sendTemplate('client_welcome', 'jane@customer.test', ['first_name' => 'Jane'], 42);

        $this->assertSame('help@acme.test', $queue->only()->from['email'] ?? null);
    }

    public function test_raw_mail_to_a_store_customer_is_rebranded_too(): void
    {
        [$dispatcher, $queue] = $this->dispatcher($this->db());

        $dispatcher->sendRaw('News from PhilmoreHost', 'Visit https://philmorehost.test/store today.', 'jane@customer.test', 42);

        $job = $queue->only();
        $this->assertSame('News from Acme Hosting', $job->subject);
        $this->assertStringContainsString('https://acme.test/store', $job->html);
        $this->assertSame('help@acme.test', $job->from['email'] ?? null);
    }
}
