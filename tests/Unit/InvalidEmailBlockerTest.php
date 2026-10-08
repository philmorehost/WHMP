<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientEmailValidationRepository;
use CodeVault\Clients\ClientEmailValidationService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Clients\EmailValidationRescanJob;
use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailSuppression;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\SendEmailJob;
use CodeVault\Marketing\MailCampaignRepository;
use CodeVault\Marketing\MailCampaignService;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Modules\Addons\InvalidEmailBlockerAddon;
use CodeVault\Notifications\ClientNotificationRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Session\SessionManager;
use CodeVault\Support\App;
use CodeVault\Tests\Support\CapturingQueue;
use CodeVault\View;
use DateTimeImmutable;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invalid Email Blocker addon: emails to addresses Email Validation marked
 * invalid are skipped — for every email type — while the switch is ON.
 */
final class InvalidEmailBlockerTest extends TestCase
{
    private BlockerDb $db;

    protected function setUp(): void
    {
        $this->db = new BlockerDb();
        // Two flagged addresses; one fine.
        $this->db->validations = [
            ['email' => 'Dead@Expired-Domain.ng', 'is_valid' => 0, 'reason' => 'No mail server found for this domain'],
            ['email' => 'bounces@gmail.com', 'is_valid' => 0, 'reason' => '3 delivery failure(s) in the last 90 days'],
            ['email' => 'ada@gmail.com', 'is_valid' => 1, 'reason' => null],
        ];
    }

    private function blocker(): EmailSuppression
    {
        return new EmailSuppression(new AddonModuleRepository($this->db), $this->db);
    }

    // ------------------------------------------------------------ the decision

    public function test_nothing_is_blocked_until_the_addon_is_activated(): void
    {
        $this->assertNull($this->blocker()->reasonFor('dead@expired-domain.ng'));
        $this->assertFalse($this->blocker()->isBlocking());
    }

    public function test_once_activated_invalid_addresses_are_blocked_whatever_their_case(): void
    {
        $this->db->addonEnabled = true; // activated, no settings saved yet: blocking defaults to ON
        $b = $this->blocker();

        $this->assertTrue($b->isBlocking());
        $this->assertSame('No mail server found for this domain', $b->reasonFor('  DEAD@expired-domain.NG '));
        $this->assertSame('3 delivery failure(s) in the last 90 days', $b->reasonFor('bounces@gmail.com', 'invoice_created'));
        $this->assertNull($b->reasonFor('ada@gmail.com'), 'a valid address is never blocked');
        $this->assertNull($b->reasonFor('never-scanned@example.com'), 'an address the scan never saw is sent to');
    }

    public function test_the_switch_turns_blocking_off_and_on_again(): void
    {
        $this->db->addonEnabled = true;
        $b = $this->blocker();

        $b->setBlocking(false);
        $this->assertFalse($b->isBlocking());
        $this->assertNull($b->reasonFor('bounces@gmail.com'));

        $b->setBlocking(true);
        $this->assertNotNull($b->reasonFor('bounces@gmail.com'));
    }

    public function test_turning_it_on_from_email_validation_also_activates_the_addon(): void
    {
        $b = $this->blocker();
        $b->setBlocking(true);

        $this->assertTrue($this->db->addonEnabled);
        $this->assertTrue($b->isBlocking());
    }

    public function test_security_emails_still_reach_a_flagged_address_unless_turned_off(): void
    {
        $this->db->addonEnabled = true;
        $b = $this->blocker();

        foreach (['client_password_reset', 'client_registration_otp', 'username_change.pin_code'] as $key) {
            $this->assertNull($b->reasonFor('bounces@gmail.com', $key), $key . ' must not lock a client out');
        }
        $this->assertNotNull($b->reasonFor('bounces@gmail.com', 'invoice_overdue'));

        $b->save(['block' => '1', 'rescan_days' => '7']); // "still send security emails" unticked
        $this->assertNotNull($b->reasonFor('bounces@gmail.com', 'client_password_reset'));
    }

    public function test_always_send_exceptions_and_undo(): void
    {
        $this->db->addonEnabled = true;
        $b = $this->blocker();

        $b->allow(' Bounces@Gmail.com ');
        $this->assertNull($b->reasonFor('bounces@gmail.com'));
        $this->assertSame(['bounces@gmail.com'], $b->settings()['allow']);
        $this->assertSame(1, $b->stats()['blocked'], 'only the other address is still blocked');

        $b->disallow('bounces@gmail.com');
        $this->assertNotNull($b->reasonFor('bounces@gmail.com'));
    }

    public function test_saving_the_form_reads_checkboxes_textarea_and_clamps_days(): void
    {
        $b = $this->blocker();
        $b->save(['allow' => "a@x.com\n\nB@X.com, not-an-email ; a@x.com", 'rescan_days' => '400', 'csrf_field' => '<input>', 'save' => '1']);
        $s = $b->settings();

        $this->assertFalse($s['block'], 'an unticked checkbox is OFF');
        $this->assertFalse($s['allow_security']);
        $this->assertSame(90, $s['rescan_days']);
        $this->assertSame(['a@x.com', 'b@x.com'], $s['allow']);
        $this->assertArrayNotHasKey('csrf_field', $this->db->addonConfig);
    }

    public function test_a_broken_lookup_sends_the_email_anyway(): void
    {
        $this->db->addonEnabled = true;
        $this->db->failValidations = true;

        $this->assertNull($this->blocker()->reasonFor('bounces@gmail.com'), 'fail open: never stop real mail on an error');
    }

    // ------------------------------------------------------------ every email type

    private function dispatcher(CapturingQueue $queue): EmailDispatcher
    {
        return new EmailDispatcher(
            new EmailTemplateRepository($this->db),
            new EmailLogRepository($this->db),
            $queue,
            null,
            null,
            new ClientNotificationRepository($this->db),
            null,
            $this->blocker()
        );
    }

    public function test_a_templated_email_to_a_blocked_address_is_logged_and_shown_in_app_but_not_sent(): void
    {
        $this->db->addonEnabled = true;
        $queue = new CapturingQueue();

        $id = $this->dispatcher($queue)->sendTemplate('invoice_created', 'bounces@gmail.com', ['invoice_id' => '42'], 7);

        $this->assertSame([], $queue->jobs, 'nothing is queued for delivery');
        $log = $this->db->write('/INSERT INTO email_log/');
        $this->assertSame('suppressed', $log['bindings'][4]);
        $this->assertStringContainsString('3 delivery failure(s)', $log['bindings'][5]);
        $this->assertSame('Invoice #42 created', $log['bindings'][1]);
        $this->assertGreaterThan(0, $id);
        $note = $this->db->write('/INSERT INTO notifications/');
        $this->assertNotNull($note, 'the client still sees it in their in-app notifications');
        $this->assertSame($id, $note['bindings'][3]);
    }

    public function test_raw_emails_campaigns_and_tickets_are_covered_too(): void
    {
        $this->db->addonEnabled = true;
        $queue = new CapturingQueue();

        $this->dispatcher($queue)->sendRaw('Big sale', 'Hello', 'DEAD@expired-domain.ng', null);

        $this->assertSame([], $queue->jobs);
        $this->assertSame('suppressed', $this->db->write('/INSERT INTO email_log/')['bindings'][4]);
    }

    public function test_valid_addresses_and_switch_off_send_normally(): void
    {
        $this->db->addonEnabled = true;
        $queue = new CapturingQueue();
        $d = $this->dispatcher($queue);

        $d->sendTemplate('invoice_created', 'ada@gmail.com', ['invoice_id' => '1'], 7);
        $this->assertCount(1, $queue->jobs);
        $this->assertInstanceOf(SendEmailJob::class, $queue->jobs[0]);

        $this->blocker()->setBlocking(false);
        $d2 = $this->dispatcher($queue);
        $d2->sendTemplate('invoice_created', 'bounces@gmail.com', ['invoice_id' => '2'], 7);
        $this->assertCount(2, $queue->jobs, 'switch OFF: the flagged address is mailed again');
    }

    public function test_before_the_migration_a_skipped_email_is_still_logged(): void
    {
        $this->db->addonEnabled = true;
        $this->db->rejectSuppressedStatus = true;

        $this->dispatcher(new CapturingQueue())->sendRaw('Hi', 'Body', 'bounces@gmail.com', null);

        $log = $this->db->write('/INSERT INTO email_log/');
        $this->assertSame('failed', $log['bindings'][4]);
        $this->assertStringStartsWith('Not sent — invalid address', $log['bindings'][5]);
    }

    public function test_a_campaign_leaves_invalid_addresses_out_of_its_recipient_list(): void
    {
        $this->db->addonEnabled = true;
        $this->db->campaign = ['id' => 3, 'status' => 'draft', 'subject' => 'S', 'body' => 'B', 'client_group_id' => 5, 'client_id' => null, 'only_inactive' => 0, 'external_emails' => "prospect@dead-domain.ng\nnew@prospect.com"];
        $this->db->clients = [
            ['id' => 1, 'email' => 'ada@gmail.com'],
            ['id' => 2, 'email' => 'Bounces@gmail.com'],
            ['id' => 3, 'email' => 'dead@expired-domain.ng'],
        ];
        $this->db->validations[] = ['email' => 'prospect@dead-domain.ng', 'is_valid' => 0, 'reason' => 'No mail server found for this domain'];
        $service = new MailCampaignService(new MailCampaignRepository($this->db), new ClientRepository($this->db), $this->dispatcher(new CapturingQueue()), $this->blocker());

        $this->assertSame(2, $service->queue(3));
        $this->assertSame(3, $service->skippedInvalid());
        $queued = array_map(static fn (array $w): string => (string) $w['bindings'][2], $this->db->writes('/INSERT INTO mail_campaign_recipients/'));
        $this->assertSame(['ada@gmail.com', 'new@prospect.com'], $queued);
    }

    // ------------------------------------------------------------ the scan

    private function scanner(callable $resolver, ?array &$asked = null): ClientEmailValidationService
    {
        $asked = [];
        $log = function (string $domain, string $type) use ($resolver, &$asked): bool {
            $asked[] = $domain . ':' . $type;

            return $resolver($domain, $type);
        };

        return (new ClientEmailValidationService(new ClientRepository($this->db), new ClientEmailValidationRepository($this->db), $this->db))->withResolver($log);
    }

    public function test_a_scan_stops_without_changing_anything_when_dns_is_down(): void
    {
        $this->db->clients = [['id' => 1, 'email' => 'ada@gmail.com'], ['id' => 2, 'email' => 'bob@company.ng']];

        $outcome = $this->scanner(static fn (): bool => false)->scanAll();

        $this->assertTrue($outcome['aborted']);
        $this->assertNull($this->db->write('/client_email_validations/'), 'not one client was flagged');
    }

    public function test_scan_flags_malformed_and_dead_domains_and_asks_each_domain_once(): void
    {
        $this->db->clients = [
            ['id' => 1, 'email' => 'ada@gmail.com'],
            ['id' => 2, 'email' => 'tolu@gmail.com'],
            ['id' => 3, 'email' => 'bob@expired.ng'],
            ['id' => 4, 'email' => 'not an email@gmail.com'],
        ];
        $outcome = $this->scanner(static fn (string $d): bool => $d === 'gmail.com', $asked)->scanAll();

        $this->assertSame(['total' => 4, 'invalid' => 2, 'aborted' => false], $outcome);
        $rows = $this->db->writes('/INSERT INTO client_email_validations/');
        $this->assertSame([1, 1, 0, 0], array_map(static fn (array $w): int => (int) $w['bindings'][2], $rows));
        $this->assertSame('No mail server found for this domain', $rows[2]['bindings'][3]);
        $this->assertSame('Not a valid email address', $rows[3]['bindings'][3]);
        $this->assertSame(1, count(array_filter($asked, static fn (string $q): bool => $q === 'gmail.com:MX')) - 1, 'gmail.com is looked up once for two clients (plus the DNS probe)');
    }

    public function test_our_own_smtp_outages_are_not_counted_against_a_client(): void
    {
        $this->db->clients = [['id' => 1, 'email' => 'ada@gmail.com'], ['id' => 2, 'email' => 'gone@gmail.com']];
        $this->db->failures = [
            'ada@gmail.com' => ['Could not connect to SMTP host mail.x:587 (110 - Connection timed out)', 'SMTP protocol error. Expected 235, got: 535 auth failed', 'Not sent — invalid address: x'],
            'gone@gmail.com' => ['SMTP protocol error. Expected 250, got: 550 5.1.1 user unknown', 'SMTP protocol error. Expected 250, got: 550 5.1.1 mailbox unavailable'],
        ];

        $this->scanner(static fn (): bool => true)->scanAll();
        $rows = $this->db->writes('/INSERT INTO client_email_validations/');

        $this->assertSame(1, (int) $rows[0]['bindings'][2], 'three of our own failures do not flag ada');
        $this->assertSame(0, (int) $rows[0]['bindings'][4]);
        $this->assertSame(0, (int) $rows[1]['bindings'][2], 'two real "user unknown" bounces do');
        $this->assertSame(2, (int) $rows[1]['bindings'][4]);
    }

    public function test_automatic_rescan_runs_only_when_due(): void
    {
        $job = fn (): EmailValidationRescanJob => new EmailValidationRescanJob($this->blocker(), new ClientEmailValidationRepository($this->db), $this->scanner(static fn (): bool => true));
        $now = new DateTimeImmutable('2026-10-08 12:00:00');

        $this->assertFalse($job()->isDue($now), 'addon inactive');
        $this->db->addonEnabled = true;
        $this->db->lastScanAt = null;
        $this->assertTrue($job()->isDue($now), 'never scanned');
        $this->db->lastScanAt = '2026-10-05 12:00:00';
        $this->assertFalse($job()->isDue($now), '3 days ago, every 7');
        $this->db->lastScanAt = '2026-09-30 12:00:00';
        $this->assertTrue($job()->isDue($now));
        $this->blocker()->save(['block' => '1', 'rescan_days' => '0']);
        $this->assertFalse($job()->isDue($now), '0 = only manual scans');
    }

    // ------------------------------------------------------------ screens

    public function test_addon_page_saves_and_shows_the_switch(): void
    {
        $this->db->addonEnabled = true;
        $addon = new InvalidEmailBlockerAddon($this->blocker());

        $html = $addon->render(['csrf_field' => '<input type="hidden" name="_token" value="t">']);
        $this->assertStringContainsString('Blocking ON', $html);
        $this->assertStringContainsString('name="block" value="1" checked', $html);
        $this->assertStringContainsString('action="/admin/addons/invalid-email-blocker"', $html);
        $this->assertSame([], $this->db->writes('/addon_modules/'), 'viewing saves nothing');

        $html = $addon->render(['save' => '1', 'allow_security' => '1', 'rescan_days' => '14']);
        $this->assertStringContainsString('Settings saved.', $html);
        $this->assertStringContainsString('Blocking OFF', $html);
        $this->assertSame(14, $this->blocker()->settings()['rescan_days']);
    }

    public function test_email_validation_page_shows_the_switch_and_blocked_addresses(): void
    {
        $this->db->addonEnabled = true;
        $config = new Config(sys_get_temp_dir() . '/cv-ieb-' . uniqid());
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(SessionManager::class, new SessionManager($config));
        $container->bind(CsrfToken::class);
        App::setContainer($container);
        $_SESSION = [];
        $b = $this->blocker();
        $b->allow('bounces@gmail.com');

        $html = (new View(dirname(__DIR__, 2) . '/resources/views'))->render('clients.email-validation', [
            'results' => [
                ['first_name' => 'Dead', 'last_name' => 'Domain', 'email' => 'dead@expired-domain.ng', 'is_valid' => 0, 'reason' => 'No mail server found for this domain', 'checked_at' => '2026-10-08 09:00'],
                ['first_name' => 'Bo', 'last_name' => 'Unce', 'email' => 'bounces@gmail.com', 'is_valid' => 0, 'reason' => '3 failures', 'checked_at' => '2026-10-08 09:00'],
                ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@gmail.com', 'is_valid' => 1, 'reason' => null, 'checked_at' => '2026-10-08 09:00'],
            ],
            'summary' => ['total' => 3, 'invalid' => 2, 'lastScanAt' => '2026-10-08 09:00'],
            'scanned' => null, 'dnsDown' => false, 'notice' => 'blocking_on',
            'blocker' => $b->settings() + ['stats' => $b->stats(), 'can_toggle' => true],
        ]);

        $this->assertStringContainsString('role="switch" aria-checked="true"', $html);
        $this->assertStringContainsString('name="block" value="0"', $html, 'the switch posts OFF while ON');
        $this->assertStringContainsString('Invalid · blocked', $html);
        $this->assertStringContainsString('Invalid · always sent', $html);
        $this->assertStringContainsString('Undo always send', $html);
        $this->assertSame(2, substr_count($html, 'action="/admin/email-validation/allow"'), 'no action on the valid address');
        $this->assertStringContainsString('Blocking is ON', $html);
    }
}

/**
 * Scripted Database fake that also remembers the addon's activation flag and
 * config (so save → read round-trips work) and supports statement().
 */
final class BlockerDb extends Database
{
    /** @var array<int, array{sql: string, bindings: array<int, mixed>}> */
    public array $writes = [];

    public bool $addonEnabled = false;

    /** @var array<string, mixed> */
    public array $addonConfig = [];

    public bool $addonRow = false;

    /** @var array<int, array<string, mixed>> */
    public array $validations = [];

    public bool $failValidations = false;

    public bool $rejectSuppressedStatus = false;

    /** @var array<int, array<string, mixed>> */
    public array $clients = [];

    /** @var array<string, array<int, string>> address => email_log errors */
    public array $failures = [];

    /** @var array<string, mixed>|null */
    public ?array $campaign = null;

    public ?string $lastScanAt = '2026-10-08 09:00:00';

    private int $nextId = 100;

    public function __construct()
    {
        parent::__construct('', '', '', '', '');
    }

    /** @return array{sql: string, bindings: array<int, mixed>}|null */
    public function write(string $pattern): ?array
    {
        return $this->writes($pattern)[0] ?? null;
    }

    /** @return array<int, array{sql: string, bindings: array<int, mixed>}> */
    public function writes(string $pattern): array
    {
        return array_values(array_filter($this->writes, static fn (array $w): bool => preg_match($pattern, $w['sql']) === 1));
    }

    public function select(string $sql, array $bindings = []): array
    {
        if (str_contains($sql, 'FROM addon_modules')) {
            return ($this->addonRow || $this->addonEnabled)
                ? [['slug' => $bindings[0] ?? '', 'enabled' => $this->addonEnabled ? 1 : 0, 'config' => $this->addonConfig === [] ? null : json_encode($this->addonConfig)]]
                : [];
        }

        if (str_contains($sql, 'FROM client_email_validations') && str_contains($sql, 'MAX(checked_at)')) {
            return [['total' => 3, 'invalid' => 2, 'last_scan_at' => $this->lastScanAt]];
        }

        if (str_contains($sql, 'FROM client_email_validations')) {
            if ($this->failValidations) {
                throw new RuntimeException('database went away');
            }

            $invalid = array_filter($this->validations, static fn (array $v): bool => (int) $v['is_valid'] === 0);

            if (str_contains($sql, 'DISTINCT')) {
                return array_values(array_map(static fn (array $v): array => ['email' => strtolower((string) $v['email'])], $invalid));
            }

            foreach ($invalid as $v) {
                if (strtolower((string) $v['email']) === $bindings[0]) {
                    return [['reason' => $v['reason']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'FROM email_templates')) {
            return [['key' => $bindings[0], 'subject' => 'Invoice #{{invoice_id}} created', 'body_html' => '<p>Invoice {{invoice_id}}</p>']];
        }

        if (str_contains($sql, 'FROM email_log WHERE to_email')) {
            return array_map(static fn (string $e): array => ['error' => $e], $this->failures[$bindings[0]] ?? []);
        }

        if (str_contains($sql, 'COUNT(*) AS c FROM email_log')) {
            return [['c' => 0]];
        }

        if (str_contains($sql, 'FROM mail_campaigns') && $this->campaign !== null) {
            return [$this->campaign];
        }

        if (str_contains($sql, 'FROM clients c WHERE c.status')) {
            return $this->clients;
        }

        return [];
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    public function insert(string $sql, array $bindings = []): string
    {
        if ($this->rejectSuppressedStatus && str_contains($sql, 'INSERT INTO email_log') && in_array('suppressed', $bindings, true)) {
            throw new RuntimeException("SQLSTATE[01000]: Data truncated for column 'status'");
        }

        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];
        $this->track($sql, $bindings);

        return (string) $this->nextId++;
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];
        $this->track($sql, $bindings);

        return 1;
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return 1;
    }

    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];

        return new PDOStatement();
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }

    /** @param array<int, mixed> $bindings */
    private function track(string $sql, array $bindings): void
    {
        if (str_contains($sql, 'INSERT INTO addon_modules (slug, enabled, activated_at')) {
            $this->addonRow = true;
            $this->addonEnabled = true;
        } elseif (str_contains($sql, 'INSERT INTO addon_modules (slug, enabled, config')) {
            $this->addonRow = true;
            $this->addonConfig = json_decode((string) $bindings[1], true) ?: [];
        } elseif (str_contains($sql, 'UPDATE addon_modules SET config')) {
            $this->addonConfig = json_decode((string) $bindings[0], true) ?: [];
        } elseif (str_contains($sql, 'UPDATE addon_modules SET enabled = 1')) {
            $this->addonEnabled = true;
        } elseif (str_contains($sql, 'UPDATE addon_modules SET enabled = 0')) {
            $this->addonEnabled = false;
        }
    }
}
