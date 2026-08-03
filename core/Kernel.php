<?php

declare(strict_types=1);

namespace CodeVault;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthGuard;
use CodeVault\Auth\AuthManager;
use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRenewalService;
use CodeVault\Billing\CancellationRequestRepository;
use CodeVault\Billing\CancellationRequestService;
use CodeVault\Billing\CancellationCronJob;
use CodeVault\Billing\OrderCancellationService;
use CodeVault\Billing\InvoiceCancellationService;
use CodeVault\Billing\FlutterwaveGateway;
use CodeVault\Billing\ManualGateway;
use CodeVault\Billing\PaystackGateway;
use CodeVault\Billing\PayhubGateway;
use CodeVault\Billing\PaypalGateway;
use CodeVault\Billing\PlisioGateway;
use CodeVault\Billing\OrderRepository;
use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Billing\PaymentService;
use CodeVault\Billing\DunningJob;
use CodeVault\Billing\ServiceLifecycleSettings;
use CodeVault\Billing\CurrencySelection;
use CodeVault\Localization\LanguageRepository;
use CodeVault\Notifications\DiscordNotificationModule;
use CodeVault\Notifications\NotificationDispatcher;
use CodeVault\Localization\LocalizationService;
use CodeVault\Localization\TranslationOverrideRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\PromotionRepository;
use CodeVault\Billing\PromotionService;
use CodeVault\Billing\ProrationService;
use CodeVault\Billing\RecurringBillingService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Billing\TaxRuleRepository;
use CodeVault\Billing\TaxSettings;
use CodeVault\Billing\VatLookupService;
use CodeVault\Billing\VatNumberValidator;
use CodeVault\Billing\ViesVatLookupService;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Catalog\ConfigurableOptionGroupRepository;
use CodeVault\Catalog\ConfigurableOptionPricingRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Cart\Cart;
use CodeVault\Cart\CartService;
use CodeVault\Cart\CheckoutService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientAuthManager;
use CodeVault\Clients\ClientContactRepository;
use CodeVault\Clients\ClientGroupRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronScheduler;
use CodeVault\CustomFields\CustomFieldRepository;
use CodeVault\CustomFields\CustomFieldValueRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Integrity\CurlIntegrityHttpClient;
use CodeVault\Integrity\IntegrityHttpClient;
use CodeVault\Integrity\IntegrityManager;
use CodeVault\Integrity\IntegrityTokenCipher;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\SmtpMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Modules\AddonModule;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Modules\AddonModuleService;
use CodeVault\Modules\Addons\DomainChangerAddon;
use CodeVault\Modules\Addons\SystemDiagnosticsAddon;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Modules\NotificationModule;
use CodeVault\Modules\WidgetModule;
use CodeVault\Modules\ReportModule;
use CodeVault\Modules\SecurityQuestionModule;
use CodeVault\Provisioning\CpanelProvisioningModule;
use CodeVault\Provisioning\CurlHttpClient;
use CodeVault\Provisioning\CyberPanelProvisioningModule;
use CodeVault\Provisioning\HttpClient;
use CodeVault\Provisioning\InterServerVpsProvisioningModule;
use CodeVault\Provisioning\InterServerDedicatedProvisioningModule;
use CodeVault\Provisioning\ResellerClubEmailProvisioningModule;
use CodeVault\Provisioning\NocixDedicatedServerModule;
use CodeVault\Provisioning\LocalProvisioningModule;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Domains\ConnectResellerRegistrarModule;
use CodeVault\Domains\NamecheapRegistrarModule;
use CodeVault\Domains\ResellerClubRegistrarModule;
use CodeVault\Domains\DomainRenewalBillingJob;
use CodeVault\Domains\DomainRenewalBillingService;
use CodeVault\Domains\DomainRepository;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Domains\DomainSettings;
use CodeVault\Domains\DomainSyncJob;
use CodeVault\Domains\LocalRegistrarModule;
use CodeVault\Domains\RegistrarRepository;
use CodeVault\Domains\UpperlinkRegistrarModule;
use CodeVault\Import\WhmcsImportService;
use CodeVault\Modules\RegistrarModule;
use CodeVault\Provisioning\ServerGroupRepository;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Queue\QueueInterface;
use CodeVault\Queue\RedisQueue;
use CodeVault\Queue\SyncQueue;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\CsrfToken;
use CodeVault\Security\GeoIpResolver;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\RoleRepository;
use CodeVault\Affiliates\AffiliateCommissionRepository;
use CodeVault\Affiliates\AffiliatePayoutRequestRepository;
use CodeVault\Affiliates\AffiliateReferralRepository;
use CodeVault\Affiliates\AffiliateRepository;
use CodeVault\Affiliates\AffiliateService;
use CodeVault\Ai\AiProvider;
use CodeVault\Ai\DeepSeekProvider;
use CodeVault\Billing\BillableItemInvoicingJob;
use CodeVault\Billing\BillableItemRepository;
use CodeVault\Downloads\DownloadCategoryController;
use CodeVault\Downloads\DownloadCategoryRepository;
use CodeVault\Downloads\DownloadController;
use CodeVault\Downloads\DownloadRepository;
use CodeVault\Downloads\PublicDownloadController;
use CodeVault\Fraud\DeepSeekFraudTriageModule;
use CodeVault\Fraud\FraudService;
use CodeVault\Fraud\RuleBasedFraudModule;
use CodeVault\Modules\FraudModule;
use CodeVault\Knowledgebase\KbArticleRepository;
use CodeVault\Reports\ReportRepository;
use CodeVault\Reports\ServiceChurnReport;
use CodeVault\Reports\TopClientsWidget;
use CodeVault\Security\MotherMaidenNameQuestion;
use CodeVault\Cache\ArrayCache;
use CodeVault\Cache\Cache;
use CodeVault\Cache\RedisCache;
use CodeVault\Seo\AiVisibilityScorer;
use CodeVault\Seo\InProcessPageFetcher;
use CodeVault\Seo\PageFetcher;
use CodeVault\Seo\SeoTags;
use CodeVault\Knowledgebase\KbCategoryRepository;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\AnnouncementRepository;
use CodeVault\Support\CannedReplyRepository;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\ImapMailboxClient;
use CodeVault\Support\MailboxClient;
use CodeVault\Support\MailPipingJob;
use CodeVault\Support\NetworkIssueRepository;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketAutoCloseJob;
use CodeVault\Support\TicketEscalationJob;
use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Backup\BackupRunRepository;
use CodeVault\Backup\BackupService;
use CodeVault\Security\SecurityHeaders;
use CodeVault\Theme\ThemeSettings;
use CodeVault\Support\TicketService;
use CodeVault\Update\BackupManager;
use CodeVault\Update\ChecksumVerifier;
use CodeVault\Update\CurlDownloader;
use CodeVault\Update\Downloader;
use CodeVault\Update\UpdateChecker;
use CodeVault\Update\UpdateInstaller;
use Throwable;

/**
 * Application bootstrap: wires the container, loads routes, and turns a
 * Request into a Response. This is the one class `public/index.php` (and
 * the cron/worker CLI entry points) talk to.
 */
class Kernel
{
    public readonly Container $container;

    public function __construct(
        private readonly string $basePath
    ) {
        $this->container = new Container();
        $this->registerCoreBindings();
        \CodeVault\Support\App::setContainer($this->container);
        $this->applyTimezone();
    }

    /**
     * Puts PHP — and the database session — on the admin's timezone.
     *
     * Nothing set this before, so PHP used its ini default (UTC on most
     * hosts) while the business runs on local time. Every generated date was
     * an hour out for a UTC+1 install: the automation screen reported the
     * wrong "server time", and a daily run time of 10:10 fired at 11:10
     * locally.
     *
     * The database session is aligned to the same offset because a handful of
     * queries use MySQL's own NOW() rather than a PHP timestamp — without
     * that, those few rows would be written an hour apart from every other
     * date in the system.
     */
    private function applyTimezone(): void
    {
        $timezone = 'UTC';

        try {
            $configured = trim((string) $this->container->make(Config::class)->env('APP_TIMEZONE', ''));

            if ($configured === '') {
                // Settings live in the database, which may not exist yet during
                // install — hence the try/catch around the whole lookup.
                $configured = trim((string) ($this->container
                    ->make(\CodeVault\Settings\SettingsRepository::class)
                    ->get('general.timezone', '') ?? ''));
            }

            if ($configured !== '' && in_array($configured, \DateTimeZone::listIdentifiers(), true)) {
                $timezone = $configured;
            }
        } catch (\Throwable) {
            // No config/database yet — UTC is the safe default.
        }

        date_default_timezone_set($timezone);

        try {
            // A named zone would need MySQL's tz tables loaded, which many
            // shared hosts skip; the numeric offset always works.
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('P');
            $this->container->make(Database::class)->statement('SET time_zone = ?', [$offset]);
        } catch (\Throwable) {
            // Database unavailable, or the session variable is restricted —
            // PHP is still on the right zone, which covers almost every date.
        }
    }

    private function registerCoreBindings(): void
    {
        $basePath = $this->basePath;

        $this->container->instance(Kernel::class, $this);

        $this->container->singleton(Config::class, fn () => new Config($basePath));

        $this->container->singleton(Database::class, function (Container $c) {
            /** @var Config $config */
            $config = $c->make(Config::class);

            return new Database(
                (string) $config->env('DB_HOST', '127.0.0.1'),
                (string) $config->env('DB_PORT', '3306'),
                (string) $config->env('DB_DATABASE', 'codevault'),
                (string) $config->env('DB_USERNAME', 'root'),
                (string) $config->env('DB_PASSWORD', ''),
            );
        });

        $this->container->singleton(View::class, function (Container $c) use ($basePath) {
            return new View($basePath . '/resources/views', $c->make(ThemeSettings::class));
        });

        $this->container->singleton(Migrator::class, function (Container $c) use ($basePath) {
            return new Migrator($c->make(Database::class), $basePath . '/database/migrations');
        });

        $this->container->singleton(LocalizationService::class, function (Container $c) use ($basePath) {
            return new LocalizationService(
                $c->make(LanguageRepository::class),
                $c->make(TranslationOverrideRepository::class),
                $basePath . '/resources/lang'
            );
        });

        $this->container->singleton(IntegrityHttpClient::class, fn () => new CurlIntegrityHttpClient());

        $this->container->singleton(IntegrityManager::class, function (Container $c) use ($basePath) {
            /** @var Config $config */
            $config = $c->make(Config::class);
            $domain = $_SERVER['HTTP_HOST'] ?? null;
            if ($domain === null) {
                $domain = (string) (parse_url((string) $config->env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
            }
            $domain = explode(':', $domain)[0];

            return new IntegrityManager(
                $c->make(Database::class),
                $c->make(IntegrityHttpClient::class),
                new IntegrityTokenCipher((string) $config->env('APP_KEY', 'insecure-default-change-me-please')),
                $domain,
                'https://manager.pmhserver.name.ng/api.php',
                $basePath . '/storage/system/activation.token',
                $basePath . '/storage/system/.integrity.lock',
            );
        });

        $this->container->singleton(Downloader::class, fn () => new CurlDownloader());

        $this->container->singleton(ChecksumVerifier::class, fn () => new ChecksumVerifier());

        $this->container->singleton(BackupManager::class, function (Container $c) {
            return new BackupManager($c->make(Database::class));
        });

        $this->container->singleton(BackupService::class, function (Container $c) use ($basePath) {
            return new BackupService(
                $c->make(BackupManager::class),
                $c->make(BackupRunRepository::class),
                $basePath,
                $basePath . '/storage/backups',
                $c->make(\CodeVault\Settings\SettingsRepository::class)
            );
        });

        $this->container->singleton(UpdateInstaller::class, function (Container $c) use ($basePath) {
            return new UpdateInstaller(
                $c->make(Database::class),
                $c->make(BackupManager::class),
                $c->make(ChecksumVerifier::class),
                $basePath,
                $basePath . '/storage/backups',
                $basePath . '/storage/cache/update-staging',
            );
        });

        $this->container->singleton(UpdateChecker::class, function (Container $c) {
            return new UpdateChecker($c->make(IntegrityHttpClient::class), 'https://manager.pmhserver.name.ng/update-check.php');
        });

        $this->container->singleton(SessionManager::class, function (Container $c) {
            return new SessionManager($c->make(Config::class));
        });

        $this->container->singleton(CsrfToken::class, function (Container $c) {
            return new CsrfToken($c->make(SessionManager::class));
        });

        $this->container->singleton(LoginAttemptRepository::class, function (Container $c) {
            return new LoginAttemptRepository($c->make(Database::class));
        });

        $this->container->singleton(IpRuleRepository::class, function (Container $c) {
            return new IpRuleRepository($c->make(Database::class));
        });

        $this->container->singleton(CountryRuleRepository::class, function (Container $c) {
            return new CountryRuleRepository($c->make(Database::class));
        });

        $this->container->singleton(AccountLockRepository::class, function (Container $c) {
            return new AccountLockRepository($c->make(Database::class));
        });

        // No MaxMind .mmdb file is available yet — country rules are wired
        // but inert until a real GeoIpResolver is bound in its place.
        $this->container->singleton(GeoIpResolver::class, fn () => new NullGeoIpResolver());

        $this->container->singleton(BruteGuard::class, function (Container $c) {
            return new BruteGuard(
                $c->make(LoginAttemptRepository::class),
                $c->make(IpRuleRepository::class),
                $c->make(CountryRuleRepository::class),
                $c->make(AccountLockRepository::class),
                $c->make(GeoIpResolver::class),
                $c->make(HookDispatcher::class),
            );
        });

        $this->container->singleton(RoleRepository::class, function (Container $c) {
            return new RoleRepository($c->make(Database::class));
        });

        $this->container->singleton(AdminRepository::class, function (Container $c) {
            return new AdminRepository($c->make(Database::class));
        });

        $this->container->singleton(AuthGuard::class, function (Container $c) {
            return new AuthGuard($c->make(SessionManager::class), $c->make(AdminRepository::class), $c->make(RoleRepository::class));
        });

        $this->container->singleton(AuthManager::class, function (Container $c) {
            return new AuthManager(
                $c->make(AdminRepository::class),
                $c->make(BruteGuard::class),
                $c->make(AccountLockRepository::class),
                $c->make(HookDispatcher::class),
                $c->make(SettingsRepository::class)
            );
        });

        $this->container->singleton(ActivityLogger::class, function (Container $c) {
            return new ActivityLogger($c->make(Database::class));
        });

        $this->container->singleton(ClientRepository::class, function (Container $c) {
            return new ClientRepository($c->make(Database::class));
        });

        $this->container->singleton(ClientGroupRepository::class, function (Container $c) {
            return new ClientGroupRepository($c->make(Database::class));
        });

        $this->container->singleton(ClientContactRepository::class, function (Container $c) {
            return new ClientContactRepository($c->make(Database::class));
        });

        $this->container->singleton(ClientAuthGuard::class, function (Container $c) {
            return new ClientAuthGuard($c->make(SessionManager::class), $c->make(ClientRepository::class));
        });

        $this->container->singleton(ClientAuthManager::class, function (Container $c) {
            return new ClientAuthManager($c->make(ClientRepository::class), $c->make(BruteGuard::class), $c->make(SettingsRepository::class));
        });

        $this->container->singleton(CustomFieldRepository::class, function (Container $c) {
            return new CustomFieldRepository($c->make(Database::class));
        });

        $this->container->singleton(CustomFieldValueRepository::class, function (Container $c) {
            return new CustomFieldValueRepository($c->make(Database::class));
        });

        $this->container->singleton(ProductGroupRepository::class, function (Container $c) {
            return new ProductGroupRepository($c->make(Database::class));
        });

        $this->container->singleton(ProductRepository::class, function (Container $c) {
            return new ProductRepository($c->make(Database::class));
        });

        $this->container->singleton(ProductPricingRepository::class, function (Container $c) {
            return new ProductPricingRepository($c->make(Database::class));
        });

        $this->container->singleton(ConfigurableOptionGroupRepository::class, function (Container $c) {
            return new ConfigurableOptionGroupRepository($c->make(Database::class));
        });

        $this->container->singleton(ConfigurableOptionRepository::class, function (Container $c) {
            return new ConfigurableOptionRepository($c->make(Database::class));
        });

        $this->container->singleton(ConfigurableOptionPricingRepository::class, function (Container $c) {
            return new ConfigurableOptionPricingRepository($c->make(Database::class));
        });

        $this->container->singleton(Cart::class, function (Container $c) {
            return new Cart($c->make(SessionManager::class));
        });

        $this->container->singleton(CartService::class, function (Container $c) {
            return new CartService(
                $c->make(Cart::class),
                $c->make(ProductRepository::class),
                $c->make(ProductPricingRepository::class),
                $c->make(ConfigurableOptionRepository::class),
                $c->make(ConfigurableOptionPricingRepository::class),
                $c->make(PromotionService::class),
                $c->make(Database::class)
            );
        });

        $this->container->singleton(ServiceRepository::class, function (Container $c) {
            return new ServiceRepository($c->make(Database::class));
        });

        $this->container->singleton(TaxRuleRepository::class, function (Container $c) {
            return new TaxRuleRepository($c->make(Database::class));
        });

        $this->container->singleton(TaxCalculator::class, function (Container $c) {
            return new TaxCalculator(
                $c->make(TaxRuleRepository::class),
                $c->make(VatNumberValidator::class),
                $c->make(TaxSettings::class)
            );
        });

        $this->container->singleton(VatLookupService::class, function (Container $c) {
            return new ViesVatLookupService($c->make(HttpClient::class));
        });

        $this->container->singleton(ClientCreditRepository::class, function (Container $c) {
            return new ClientCreditRepository($c->make(Database::class));
        });

        $this->container->singleton(CreditService::class, function (Container $c) {
            return new CreditService(
                $c->make(ClientCreditRepository::class),
                $c->make(InvoiceRepository::class),
                $c->make(TransactionRepository::class),
                $c->make(PaymentService::class),
                $c->make(HookDispatcher::class),
            );
        });

        $this->container->singleton(CheckoutService::class, function (Container $c) {
            return new CheckoutService(
                $c->make(Cart::class),
                $c->make(CartService::class),
                $c->make(ProductRepository::class),
                $c->make(ClientRepository::class),
                $c->make(ServiceRepository::class),
                $c->make(TaxCalculator::class),
                $c->make(CurrencyService::class),
                $c->make(CurrencySelection::class),
                $c->make(PromotionRepository::class),
                $c->make(Database::class),
                $c->make(HookDispatcher::class),
                $c->make(DomainSettings::class),
            );
        });

        $this->container->singleton(ProrationService::class, function (Container $c) {
            return new ProrationService(
                $c->make(ServiceRepository::class),
                $c->make(ClientRepository::class),
                $c->make(TaxCalculator::class),
                $c->make(ClientCreditRepository::class),
                $c->make(CreditService::class),
                $c->make(CurrencyService::class),
                $c->make(Database::class),
                $c->make(HookDispatcher::class),
            );
        });

        $this->container->singleton(RecurringBillingService::class, function (Container $c) {
            return new RecurringBillingService(
                $c->make(ServiceRepository::class),
                $c->make(ClientRepository::class),
                $c->make(TaxCalculator::class),
                $c->make(CurrencyService::class),
                $c->make(Database::class),
                $c->make(HookDispatcher::class),
            );
        });

        $this->container->singleton(DunningJob::class, function (Container $c) {
            return new DunningJob(
                $c->make(InvoiceRepository::class),
                $c->make(ClientRepository::class),
                $c->make(EmailDispatcher::class),
                $c->make(HookDispatcher::class),
                $c->make(CurrencyService::class),
                $c->make(Database::class),
                $c->make(SettingsRepository::class),
                $c->make(ServiceLifecycleSettings::class),
            );
        });

        $this->container->singleton(CancellationCronJob::class, function (Container $c) {
            return new CancellationCronJob($c->make(CancellationRequestService::class));
        });

        $this->container->singleton(OrderCancellationService::class, function (Container $c) {
            return new OrderCancellationService(
                $c->make(OrderRepository::class),
                $c->make(EmailDispatcher::class),
                $c->make(Database::class)
            );
        });

        $this->container->singleton(InvoiceCancellationService::class, function (Container $c) {
            return new InvoiceCancellationService(
                $c->make(InvoiceRepository::class),
                $c->make(EmailDispatcher::class),
                $c->make(Database::class)
            );
        });

        // No SMTP server is configured in this environment — LogMailer
        // writes rendered emails to storage/cache/mail.log instead of
        // transmitting them. Swap for a real SMTP-backed Mailer once
        // credentials exist; nothing else in the pipeline changes.
        $this->container->singleton(Mailer::class, function (Container $c) use ($basePath) {
            $settings = $c->make(\CodeVault\Settings\SettingsRepository::class);
            $config = $c->make(\CodeVault\Config::class);
            $driver = strtolower((string) ($settings->get('mail.driver') ?: $config->env('MAIL_DRIVER', 'smtp')));
            if ($driver === 'log') {
                return new LogMailer($basePath . '/storage/cache/mail.log');
            }
            return new SmtpMailer($settings, $config);
        });

        $this->container->singleton(EmailTemplateRepository::class, function (Container $c) {
            return new EmailTemplateRepository($c->make(Database::class));
        });

        $this->container->singleton(EmailLogRepository::class, function (Container $c) {
            return new EmailLogRepository($c->make(Database::class));
        });

        $this->container->singleton(EmailDispatcher::class, function (Container $c) {
            return new EmailDispatcher(
                $c->make(EmailTemplateRepository::class),
                $c->make(EmailLogRepository::class),
                $c->make(QueueInterface::class),
                $c->make(\CodeVault\Settings\SettingsRepository::class),
                $c->make(\CodeVault\Config::class),
                $c->make(\CodeVault\Notifications\ClientNotificationRepository::class),
            );
        });

        $this->container->singleton(HookDispatcher::class, fn () => new HookDispatcher());

        $this->container->singleton(ServerGroupRepository::class, function (Container $c) {
            return new ServerGroupRepository($c->make(Database::class));
        });

        $this->container->singleton(ServerRepository::class, function (Container $c) {
            return new ServerRepository($c->make(Database::class));
        });

        $this->container->singleton(LocalProvisioningModule::class, fn () => new LocalProvisioningModule(
            $basePath . '/storage/cache/local-provisioning'
        ));

        // 120s, not the 15s default — confirmed live against a real WHM
        // server that a real createacct call (DNS zone, mail, AutoSSL)
        // can comfortably exceed 60s. Cheap calls (version, suspend) still
        // return in well under a second either way.
        $this->container->singleton(HttpClient::class, fn () => new CurlHttpClient(timeoutSeconds: 120));

        $this->container->singleton(CpanelProvisioningModule::class, function (Container $c) {
            return new CpanelProvisioningModule(new CurlHttpClient(timeoutSeconds: 120, verifySsl: false));
        });

        $this->container->singleton(\CodeVault\CpanelTools\CpanelUapiClient::class, function (Container $c) {
            return new \CodeVault\CpanelTools\CpanelUapiClient(new CurlHttpClient(timeoutSeconds: 120, verifySsl: false));
        });

        $this->container->singleton(CyberPanelProvisioningModule::class, function (Container $c) {
            return new CyberPanelProvisioningModule($c->make(HttpClient::class));
        });

        $this->container->singleton(InterServerVpsProvisioningModule::class, function (Container $c) {
            return new InterServerVpsProvisioningModule($c->make(HttpClient::class));
        });

        $this->container->singleton(InterServerDedicatedProvisioningModule::class, function (Container $c) {
            return new InterServerDedicatedProvisioningModule($c->make(HttpClient::class));
        });

        $this->container->singleton(ResellerClubEmailProvisioningModule::class, function (Container $c) {
            return new ResellerClubEmailProvisioningModule($c->make(HttpClient::class), $c->make(RegistrarRepository::class));
        });

        $this->container->singleton(NocixDedicatedServerModule::class, function (Container $c) {
            return new NocixDedicatedServerModule($c->make(HttpClient::class));
        });

        $this->container->singleton(LocalRegistrarModule::class, fn () => new LocalRegistrarModule(
            $basePath . '/storage/cache/local-registrar'
        ));

        $this->container->singleton(UpperlinkRegistrarModule::class, function (Container $c) {
            return new UpperlinkRegistrarModule($c->make(HttpClient::class));
        });

        $this->container->singleton(ConnectResellerRegistrarModule::class, function (Container $c) {
            return new ConnectResellerRegistrarModule($c->make(HttpClient::class));
        });

        $this->container->singleton(ResellerClubRegistrarModule::class, function (Container $c) {
            return new ResellerClubRegistrarModule($c->make(HttpClient::class));
        });

        $this->container->singleton(NamecheapRegistrarModule::class, function (Container $c) {
            return new NamecheapRegistrarModule($c->make(HttpClient::class));
        });

        $this->container->singleton(PaystackGateway::class, function (Container $c) {
            return new PaystackGateway($c->make(HttpClient::class));
        });

        $this->container->singleton(FlutterwaveGateway::class, function (Container $c) {
            return new FlutterwaveGateway($c->make(HttpClient::class));
        });

        $this->container->singleton(PayhubGateway::class, function (Container $c) {
            return new PayhubGateway($c->make(HttpClient::class));
        });

        $this->container->singleton(PlisioGateway::class, function (Container $c) {
            return new PlisioGateway($c->make(HttpClient::class));
        });

        $this->container->singleton(PaypalGateway::class, function (Container $c) {
            return new PaypalGateway($c->make(HttpClient::class));
        });

        $this->container->singleton(ModuleManager::class, function (Container $c) {
            $manager = new ModuleManager($c->make(HookDispatcher::class));
            $manager->register(GatewayModule::class, 'manual', $c->make(ManualGateway::class));
            $manager->register(GatewayModule::class, 'paystack', $c->make(PaystackGateway::class));
            $manager->register(GatewayModule::class, 'flutterwave', $c->make(FlutterwaveGateway::class));
            $manager->register(GatewayModule::class, 'payhub', $c->make(PayhubGateway::class));
            $manager->register(GatewayModule::class, 'plisio', $c->make(PlisioGateway::class));
            $manager->register(GatewayModule::class, 'paypal', $c->make(PaypalGateway::class));
            $manager->register(ProvisioningModule::class, 'local', $c->make(LocalProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'cpanel', $c->make(CpanelProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'cyberpanel', $c->make(CyberPanelProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'interserver-vps', $c->make(InterServerVpsProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'interserver_vps', $c->make(InterServerVpsProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'interserver-dedicated', $c->make(InterServerDedicatedProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'interserver_dedicated', $c->make(InterServerDedicatedProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'resellerclub-email', $c->make(ResellerClubEmailProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'resellerclub_email', $c->make(ResellerClubEmailProvisioningModule::class));
            $manager->register(ProvisioningModule::class, 'nocix-dedicated', $c->make(NocixDedicatedServerModule::class));
            $manager->register(ProvisioningModule::class, 'nocix_dedicated', $c->make(NocixDedicatedServerModule::class));
            $manager->register(ProvisioningModule::class, 'nocix', $c->make(NocixDedicatedServerModule::class));
            $manager->register(RegistrarModule::class, 'local', $c->make(LocalRegistrarModule::class));
            $manager->register(RegistrarModule::class, 'upperlink', $c->make(UpperlinkRegistrarModule::class));
            $manager->register(RegistrarModule::class, 'connectreseller', $c->make(ConnectResellerRegistrarModule::class));
            $manager->register(RegistrarModule::class, 'resellerclub', $c->make(ResellerClubRegistrarModule::class));
            $manager->register(RegistrarModule::class, 'namecheap', $c->make(NamecheapRegistrarModule::class));
            $manager->register(FraudModule::class, 'rules', $c->make(RuleBasedFraudModule::class));
            $manager->register(FraudModule::class, 'deepseek', $c->make(DeepSeekFraudTriageModule::class));
            $manager->register(AddonModule::class, 'system-diagnostics', $c->make(SystemDiagnosticsAddon::class));
            $manager->register(AddonModule::class, 'domain-changer', $c->make(DomainChangerAddon::class));
            $manager->register(WidgetModule::class, 'top-clients', $c->make(TopClientsWidget::class));
            $manager->register(ReportModule::class, 'service-churn', $c->make(ServiceChurnReport::class));
            $manager->register(SecurityQuestionModule::class, 'mother-maiden-name', $c->make(MotherMaidenNameQuestion::class));
            $manager->register(NotificationModule::class, 'discord', $c->make(DiscordNotificationModule::class));

            return $manager;
        });

        $this->container->singleton(ProvisioningService::class, function (Container $c) {
            return new ProvisioningService(
                $c->make(ServiceRepository::class),
                $c->make(ProductRepository::class),
                $c->make(ServerRepository::class),
                $c->make(ModuleManager::class),
                $c->make(HookDispatcher::class),
            );
        });

        $this->container->singleton(InvoiceRepository::class, function (Container $c) {
            return new InvoiceRepository($c->make(Database::class));
        });

        $this->container->singleton(CancellationRequestRepository::class, function (Container $c) {
            return new CancellationRequestRepository($c->make(Database::class));
        });

        $this->container->singleton(CancellationRequestService::class, function (Container $c) {
            return new CancellationRequestService(
                $c->make(CancellationRequestRepository::class),
                $c->make(ServiceRepository::class),
                $c->make(EmailDispatcher::class),
                $c->make(Database::class)
            );
        });

        $this->container->singleton(TransactionRepository::class, function (Container $c) {
            return new TransactionRepository($c->make(Database::class));
        });

        $this->container->singleton(PaymentGatewayRepository::class, function (Container $c) {
            return new PaymentGatewayRepository($c->make(Database::class));
        });

        $this->container->singleton(PaymentService::class, function (Container $c) {
            return new PaymentService(
                $c->make(InvoiceRepository::class),
                $c->make(TransactionRepository::class),
                $c->make(HookDispatcher::class),
                // Needed to turn a settled deposit invoice into wallet credit;
                // PaymentService used to reach for these through the global
                // container at payment time instead of being handed them.
                $c->make(Database::class),
                $c->make(ClientCreditRepository::class),
            );
        });

        $this->container->singleton(ManualGateway::class, fn () => new ManualGateway());

        $this->container->singleton(OrderRepository::class, function (Container $c) {
            return new OrderRepository($c->make(Database::class));
        });

        $this->container->singleton(CronScheduler::class, fn () => new CronScheduler(
            $basePath . '/storage/cache/cron-state.json'
        ));

        $this->container->singleton(SystemDiagnosticsAddon::class, function (Container $c) use ($basePath) {
            return new SystemDiagnosticsAddon($c->make(Database::class), $c->make(Config::class), $basePath, $c->make(Migrator::class));
        });

        $this->container->singleton(DomainChangerAddon::class, function (Container $c) {
            return new DomainChangerAddon($c->make(Database::class));
        });

        $this->container->singleton(Router::class, function (Container $c) {
            return new Router($c);
        });

        $this->container->singleton(QueueInterface::class, function (Container $c) {
            /** @var Config $config */
            $config = $c->make(Config::class);

            if ($config->env('QUEUE_DRIVER', 'sync') === 'redis' && extension_loaded('redis')) {
                try {
                    return new RedisQueue(
                        (string) $config->env('REDIS_HOST', '127.0.0.1'),
                        (int) $config->env('REDIS_PORT', 6379),
                        (string) $config->env('REDIS_PASSWORD', ''),
                        (int) $config->env('REDIS_DB', 0),
                    );
                } catch (Throwable) {
                    // Redis unreachable — fall through to the sync queue.
                }
            }

            return new SyncQueue();
        });

        $this->container->singleton(Cache::class, function (Container $c) {
            /** @var Config $config */
            $config = $c->make(Config::class);

            if ($config->env('CACHE_DRIVER', 'array') === 'redis' && extension_loaded('redis')) {
                try {
                    return new RedisCache(
                        (string) $config->env('REDIS_HOST', '127.0.0.1'),
                        (int) $config->env('REDIS_PORT', 6379),
                        (string) $config->env('REDIS_PASSWORD', ''),
                        (int) $config->env('REDIS_DB', 0),
                    );
                } catch (Throwable) {
                    // Redis unreachable — fall through to the array cache.
                }
            }

            return new ArrayCache();
        });

        $this->container->singleton(DomainRepository::class, function (Container $c) {
            return new DomainRepository($c->make(Database::class));
        });

        $this->container->singleton(DomainPricingRepository::class, function (Container $c) {
            return new DomainPricingRepository($c->make(Database::class));
        });

        $this->container->singleton(RegistrarRepository::class, function (Container $c) {
            return new RegistrarRepository($c->make(Database::class));
        });

        $this->container->singleton(WhmcsImportService::class, function (Container $c) {
            return new WhmcsImportService(
                $c->make(Database::class),
                $c->make(DomainPricingRepository::class),
                $c->make(RegistrarRepository::class),
                $c->make(ProductPricingRepository::class),
                $c->make(DomainSettings::class)
            );
        });

        $this->container->singleton(DomainService::class, function (Container $c) {
            return new DomainService(
                $c->make(DomainRepository::class),
                $c->make(RegistrarRepository::class),
                $c->make(ModuleManager::class),
                $c->make(HookDispatcher::class),
                $c->make(ClientRepository::class),
                $c->make(Database::class),
                $c->make(ActivityLogger::class),
            );
        });

        $this->container->singleton(DomainRenewalBillingService::class, function (Container $c) {
            return new DomainRenewalBillingService(
                $c->make(DomainRepository::class),
                $c->make(ClientRepository::class),
                $c->make(TaxCalculator::class),
                $c->make(Database::class),
                $c->make(HookDispatcher::class),
                $c->make(CurrencyService::class),
            );
        });

        $this->container->singleton(DomainRenewalBillingJob::class, function (Container $c) {
            return new DomainRenewalBillingJob($c->make(DomainRenewalBillingService::class));
        });

        $this->container->singleton(DomainSyncJob::class, function (Container $c) {
            return new DomainSyncJob($c->make(DomainRepository::class), $c->make(DomainService::class));
        });

        // Domain renewal is payment-gated: the renewal invoice generates
        // ahead of expiry (DomainRenewalBillingJob), but the actual
        // registrar renew() call — which can cost real money — only fires
        // once that invoice is paid, not before.
        $hooks = $this->container->make(HookDispatcher::class);
        $hooks->register(HookPoints::INVOICE_PAID, function (array $payload) {
            $invoiceId = $payload['invoiceId'] ?? null;

            if ($invoiceId === null) {
                return;
            }

            $invoice = $this->container->make(InvoiceRepository::class)->find((int) $invoiceId);

            if ($invoice === null || $invoice['domain_id'] === null) {
                return;
            }

            $this->container->make(DomainService::class)->renew((int) $invoice['domain_id']);
        });

        // Paying a renewal invoice is what lifts a suspension: the service
        // rolls to its next due date and, if it was suspended for non-payment,
        // is unsuspended on the control panel too. Without this a client who
        // paid stayed locked out until an admin noticed, which is the whole
        // point of "renew to continue using the service".
        //
        // Independent of the domain listener above so a failure in one can't
        // swallow the other, and wrapped because a payment must never be
        // rejected just because the reactivation step had a problem — the
        // money is already taken by this point.
        $hooks->register(HookPoints::INVOICE_PAID, function (array $payload) {
            $invoiceId = $payload['invoiceId'] ?? null;

            if ($invoiceId === null) {
                return;
            }

            try {
                $invoice = $this->container->make(InvoiceRepository::class)->find((int) $invoiceId);

                if ($invoice === null || ($invoice['service_id'] ?? null) === null) {
                    return;
                }

                $this->container->make(ServiceRenewalService::class)->renewPaidService((int) $invoice['service_id']);
            } catch (\Throwable) {
                // Reactivation is retried by the admin or the next payment;
                // never fail the payment itself.
            }
        });

        $this->container->singleton(AffiliateRepository::class, function (Container $c) {
            return new AffiliateRepository($c->make(Database::class));
        });

        $this->container->singleton(AffiliateReferralRepository::class, function (Container $c) {
            return new AffiliateReferralRepository($c->make(Database::class));
        });

        $this->container->singleton(AffiliateCommissionRepository::class, function (Container $c) {
            return new AffiliateCommissionRepository($c->make(Database::class));
        });

        $this->container->singleton(AffiliatePayoutRequestRepository::class, function (Container $c) {
            return new AffiliatePayoutRequestRepository($c->make(Database::class));
        });

        $this->container->singleton(AffiliateService::class, function (Container $c) {
            return new AffiliateService(
                $c->make(AffiliateRepository::class),
                $c->make(AffiliateReferralRepository::class),
                $c->make(AffiliateCommissionRepository::class),
                $c->make(AffiliatePayoutRequestRepository::class),
                $c->make(InvoiceRepository::class),
            );
        });

        // Commission accrues on every paid invoice from a referred
        // client — a second, independent INVOICE_PAID listener alongside
        // the domain-renewal one above.
        $hooks->register(HookPoints::INVOICE_PAID, function (array $payload) {
            $invoiceId = $payload['invoiceId'] ?? null;

            if ($invoiceId === null) {
                return;
            }

            $this->container->make(AffiliateService::class)->accrueCommission((int) $invoiceId);
        });

        $this->container->singleton(DepartmentRepository::class, function (Container $c) {
            return new DepartmentRepository($c->make(Database::class));
        });

        $this->container->singleton(TicketRepository::class, function (Container $c) {
            return new TicketRepository($c->make(Database::class));
        });

        $this->container->singleton(TicketReplyRepository::class, function (Container $c) {
            return new TicketReplyRepository($c->make(Database::class));
        });

        $this->container->singleton(CannedReplyRepository::class, function (Container $c) {
            return new CannedReplyRepository($c->make(Database::class));
        });

        $this->container->singleton(TicketService::class, function (Container $c) {
            return new TicketService(
                $c->make(TicketRepository::class),
                $c->make(TicketReplyRepository::class),
                $c->make(HookDispatcher::class),
                $c->make(TicketAttachmentRepository::class),
            );
        });

        $this->container->singleton(TicketEscalationJob::class, function (Container $c) {
            return new TicketEscalationJob($c->make(TicketRepository::class), $c->make(HookDispatcher::class));
        });

        $this->container->singleton(TicketAutoCloseJob::class, function (Container $c) {
            return new TicketAutoCloseJob($c->make(TicketRepository::class), $c->make(TicketService::class));
        });

        $this->container->singleton(BillableItemRepository::class, function (Container $c) {
            return new BillableItemRepository($c->make(Database::class));
        });

        $this->container->singleton(BillableItemInvoicingJob::class, function (Container $c) {
            return new BillableItemInvoicingJob(
                $c->make(BillableItemRepository::class),
                $c->make(ClientRepository::class),
                $c->make(TaxCalculator::class),
                $c->make(CurrencyService::class),
                $c->make(Database::class),
                $c->make(HookDispatcher::class),
            );
        });

        $this->container->singleton(KbCategoryRepository::class, function (Container $c) {
            return new KbCategoryRepository($c->make(Database::class));
        });

        $this->container->singleton(KbArticleRepository::class, function (Container $c) {
            return new KbArticleRepository($c->make(Database::class));
        });

        $this->container->singleton(DownloadCategoryRepository::class, function (Container $c) {
            return new DownloadCategoryRepository($c->make(Database::class));
        });

        $this->container->singleton(DownloadRepository::class, function (Container $c) {
            return new DownloadRepository($c->make(Database::class));
        });

        $this->container->singleton(DownloadController::class, function (Container $c) use ($basePath) {
            return new DownloadController(
                $c->make(AuthGuard::class),
                $c->make(View::class),
                $c->make(DownloadRepository::class),
                $c->make(DownloadCategoryRepository::class),
                $basePath . '/storage/downloads',
            );
        });

        $this->container->singleton(PublicDownloadController::class, function (Container $c) use ($basePath) {
            return new PublicDownloadController(
                $c->make(View::class),
                $c->make(DownloadRepository::class),
                $c->make(DownloadCategoryRepository::class),
                $basePath . '/storage/downloads',
                $c->make(SeoTags::class),
            );
        });

        $this->container->singleton(AnnouncementRepository::class, function (Container $c) {
            return new AnnouncementRepository($c->make(Database::class));
        });

        $this->container->singleton(NetworkIssueRepository::class, function (Container $c) {
            return new NetworkIssueRepository($c->make(Database::class));
        });

        $this->container->singleton(SettingsRepository::class, function (Container $c) {
            return new SettingsRepository($c->make(Database::class));
        });

        $this->container->singleton(MailboxClient::class, fn () => new ImapMailboxClient());

        $this->container->singleton(MailPipingJob::class, function (Container $c) {
            return new MailPipingJob(
                $c->make(MailboxClient::class),
                $c->make(SettingsRepository::class),
                $c->make(DepartmentRepository::class),
                $c->make(TicketRepository::class),
                $c->make(TicketService::class),
                $c->make(ClientRepository::class),
            );
        });

        $this->container->singleton(ReportRepository::class, function (Container $c) {
            return new ReportRepository($c->make(Database::class));
        });

        $this->container->singleton(SeoTags::class, function (Container $c) {
            return new SeoTags($c->make(Config::class));
        });

        $this->container->singleton(PageFetcher::class, fn () => new InProcessPageFetcher($this));

        $this->container->singleton(AiVisibilityScorer::class, function (Container $c) {
            return new AiVisibilityScorer($c->make(PageFetcher::class));
        });

        $this->container->singleton(AiProvider::class, function (Container $c) {
            /** @var \CodeVault\Ai\AiSettings $ai */
            $ai = $c->make(\CodeVault\Ai\AiSettings::class);

            // Key/model come from the admin-managed AI settings (which fall
            // back to DEEPSEEK_API_KEY in .env); every call is metered into
            // ai_usage_log for the AI dashboard.
            return new DeepSeekProvider(
                $c->make(HttpClient::class),
                $ai->apiKey(),
                $ai->model(),
                'https://api.deepseek.com',
                $c->make(\CodeVault\Ai\AiUsageRepository::class),
            );
        });

        $this->container->singleton(DeepSeekFraudTriageModule::class, function (Container $c) {
            return new DeepSeekFraudTriageModule($c->make(AiProvider::class));
        });

        $this->container->singleton(FraudService::class, function (Container $c) {
            return new FraudService(
                $c->make(ModuleManager::class),
                $c->make(OrderRepository::class),
                $c->make(ClientRepository::class),
                $c->make(HookDispatcher::class),
            );
        });

        // Fraud scoring runs right after an order lands, before an admin
        // ever sees it — matches WHMCS's "score at order time, hold if
        // risky" flow rather than a separate batch sweep.
        $this->container->make(HookDispatcher::class)->register(HookPoints::ORDER_PLACED, function (array $payload) {
            $orderId = $payload['orderId'] ?? null;

            if ($orderId === null) {
                return;
            }

            $this->container->make(FraudService::class)->evaluate((int) $orderId);
        });

        $this->registerNotificationListeners();
    }

    /**
     * Notification providers (blueprint §5): fans a curated set of events
     * out to any admin-configured Slack/webhook endpoint subscribed to
     * them — see NotificationEndpointController::WIRED_EVENTS for the
     * exact list a saved endpoint can subscribe to.
     */
    private function registerNotificationListeners(): void
    {
        $hooks = $this->container->make(HookDispatcher::class);

        $hooks->register(HookPoints::ORDER_PLACED, function (array $payload) {
            $orderId = $payload['orderId'] ?? null;

            if ($orderId === null) {
                return;
            }

            $order = $this->container->make(OrderRepository::class)->find((int) $orderId);

            if ($order === null) {
                return;
            }

            $this->container->make(NotificationDispatcher::class)->dispatch(
                HookPoints::ORDER_PLACED,
                "New order #{$orderId} placed — \${$order['total']} ({$order['client_email']})",
                ['orderId' => $orderId, 'total' => $order['total']]
            );
        });

        $hooks->register(HookPoints::INVOICE_PAID, function (array $payload) {
            $invoiceId = $payload['invoiceId'] ?? null;

            if ($invoiceId === null) {
                return;
            }

            $invoice = $this->container->make(InvoiceRepository::class)->find((int) $invoiceId);

            if ($invoice === null) {
                return;
            }

            $this->container->make(NotificationDispatcher::class)->dispatch(
                HookPoints::INVOICE_PAID,
                "Invoice INV-{$invoiceId} paid — \${$invoice['total']}",
                ['invoiceId' => $invoiceId, 'total' => $invoice['total']]
            );

            // Auto-provision services and domains associated with paid order if autosetup === 'payment'
            if (!empty($invoice['order_id'])) {
                $orderId = (int) $invoice['order_id'];
                $serviceRepo = $this->container->make(\CodeVault\Billing\ServiceRepository::class);
                $productRepo = $this->container->make(\CodeVault\Catalog\ProductRepository::class);
                $provisioning = $this->container->make(\CodeVault\Provisioning\ProvisioningService::class);
                $domainRepo = $this->container->make(\CodeVault\Domains\DomainRepository::class);
                $domainPricingRepo = $this->container->make(\CodeVault\Domains\DomainPricingRepository::class);
                $domainService = $this->container->make(\CodeVault\Domains\DomainService::class);

                $hasPendingApproval = false;

                foreach ($serviceRepo->forOrder($orderId) as $service) {
                    if ($service['status'] !== 'pending') {
                        continue;
                    }

                    $product = $productRepo->find((int) $service['product_id']);
                    $autosetup = $product['autosetup'] ?? 'payment';

                    if ($autosetup === 'payment') {
                        $provisioning->provision((int) $service['id']);
                    } elseif (in_array($autosetup, ['on_accept', 'off'], true)) {
                        $hasPendingApproval = true;
                    }
                }

                foreach ($domainRepo->forOrder($orderId) as $domain) {
                    if ($domain['status'] !== 'pending') {
                        continue;
                    }

                    $tldPricing = $domainPricingRepo->findByTld((string) $domain['tld']);
                    $autosetup = $tldPricing['autosetup_registration'] ?? 'payment';

                    if ($autosetup === 'payment') {
                        $domainService->register((int) $domain['id']);
                    } elseif (in_array($autosetup, ['on_accept', 'off'], true)) {
                        $hasPendingApproval = true;
                    }
                }

                if ($hasPendingApproval) {
                    try {
                        $client = $this->container->make(\CodeVault\Clients\ClientRepository::class)->find((int) $invoice['client_id']);
                        $adminRepo = $this->container->make(\CodeVault\Auth\AdminRepository::class);
                        $dispatcher = $this->container->make(\CodeVault\Mail\EmailDispatcher::class);

                        $clientName = $client ? trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')) : 'Client';
                        $clientEmail = $client['email'] ?? '';

                        foreach ($adminRepo->all() as $admin) {
                            if (!empty($admin['email'])) {
                                $dispatcher->sendTemplate('admin_pending_order_approval', (string) $admin['email'], [
                                    'order_id' => (string) $orderId,
                                    'client_name' => $clientName,
                                    'client_email' => $clientEmail,
                                    'order_total' => number_format((float) $invoice['total'], 2),
                                    'company_name' => 'CodeVault',
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        // Ignore email errors
                    }
                }
            }
        });

        $ensureTemplate = function(string $key, string $name, string $subject, string $bodyHtml) {
            $db = $this->container->make(Database::class);
            $exists = $db->selectOne('SELECT id FROM email_templates WHERE `key` = ?', [$key]);
            if ($exists === null) {
                $now = date('Y-m-d H:i:s');
                $db->insert(
                    'INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$key, $name, $subject, $bodyHtml, $now, $now]
                );
            }
        };

        $hooks->register(HookPoints::TICKET_OPEN, function (array $payload) use ($ensureTemplate) {
            $ticketId = $payload['ticketId'] ?? null;

            if ($ticketId === null) {
                return;
            }

            $ticket = $this->container->make(TicketRepository::class)->find((int) $ticketId);

            if ($ticket === null) {
                return;
            }

            $this->container->make(NotificationDispatcher::class)->dispatch(
                HookPoints::TICKET_OPEN,
                "New support ticket #{$ticketId}: {$ticket['subject']}",
                ['ticketId' => $ticketId]
            );

            // Seed ticket_opened template
            $ensureTemplate(
                'ticket_opened',
                'Ticket Opened Confirmation',
                '[Ticket #{{ticket_id}}] {{ticket_subject}}',
                '<p>Dear {{client_name}},</p><p>Thank you for contacting our Support Department. A support ticket has been opened for you.</p><p><strong>Ticket Details:</strong><br>Ticket ID: #{{ticket_id}}<br>Subject: {{ticket_subject}}<br>Department: {{department_name}}</p><p>Our staff will review your request and reply shortly. You can view or update your ticket at any time by logging into the client area.</p><p>Thanks,<br>{{company_name}}</p>'
            );

            // Seed admin_ticket_opened template
            $ensureTemplate(
                'admin_ticket_opened',
                'Admin Ticket Opened Notification',
                '[New Ticket #{{ticket_id}}] {{ticket_subject}}',
                '<p>Hello Admin,</p><p>A new support ticket has been opened by <strong>{{client_name}}</strong> ({{client_email}}).</p><p><strong>Ticket Details:</strong><br>Ticket ID: #{{ticket_id}}<br>Subject: {{ticket_subject}}<br>Department: {{department_name}}</p><p>Please log in to the admin panel to view and reply to the ticket.</p><p>Thanks,<br>{{company_name}} System</p>'
            );

            $dispatcher = $this->container->make(EmailDispatcher::class);
            $settings = $this->container->make(SettingsRepository::class);
            $companyName = $settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault';

            // Send to client
            $clientName = $ticket['email'];
            if (!empty($ticket['client_id'])) {
                $client = $this->container->make(ClientRepository::class)->find((int) $ticket['client_id']);
                if ($client) {
                    $clientName = $client['first_name'] . ' ' . $client['last_name'];
                }
            }

            $dispatcher->sendTemplate('ticket_opened', $ticket['email'], [
                'ticket_id' => (string) $ticketId,
                'ticket_subject' => $ticket['subject'],
                'department_name' => $ticket['department_name'],
                'client_name' => $clientName,
                'company_name' => $companyName,
            ], !empty($ticket['client_id']) ? (int) $ticket['client_id'] : null);

            // Send to all admins
            $admins = $this->container->make(AdminRepository::class)->all();
            foreach ($admins as $admin) {
                if (!empty($admin['email'])) {
                    $dispatcher->sendTemplate('admin_ticket_opened', $admin['email'], [
                        'ticket_id' => (string) $ticketId,
                        'ticket_subject' => $ticket['subject'],
                        'department_name' => $ticket['department_name'],
                        'client_name' => $clientName,
                        'client_email' => $ticket['email'],
                        'company_name' => $companyName,
                    ]);
                }
            }
        });

        $hooks->register(HookPoints::TICKET_REPLY, function (array $payload) use ($ensureTemplate) {
            $ticketId = $payload['ticketId'] ?? null;
            $authorType = $payload['authorType'] ?? null;

            if ($ticketId === null) {
                return;
            }

            $ticket = $this->container->make(TicketRepository::class)->find((int) $ticketId);
            if ($ticket === null) {
                return;
            }

            // Fetch the latest reply
            $db = $this->container->make(Database::class);
            $reply = $db->selectOne('SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', [$ticketId]);
            if ($reply === null) {
                return;
            }

            // Seed reply templates
            $ensureTemplate(
                'ticket_reply',
                'Ticket Reply Notification',
                '[Ticket #{{ticket_id}}] Re: {{ticket_subject}}',
                '<p>Dear {{client_name}},</p><p>A staff member has replied to your support ticket.</p><p><strong>Latest Response:</strong><br>{{reply_message}}</p><p>You can view the full ticket and reply to it by logging into the client area.</p><p>Thanks,<br>{{company_name}}</p>'
            );

            $ensureTemplate(
                'admin_ticket_reply',
                'Admin Ticket Reply Notification',
                '[Ticket Update #{{ticket_id}}] Client Reply: {{ticket_subject}}',
                '<p>Hello Admin,</p><p>Client <strong>{{client_name}}</strong> has replied to support ticket #{{ticket_id}}.</p><p><strong>Latest Response:</strong><br>{{reply_message}}</p><p>Please log in to the admin panel to view and reply to the ticket.</p><p>Thanks,<br>{{company_name}} System</p>'
            );

            $dispatcher = $this->container->make(EmailDispatcher::class);
            $settings = $this->container->make(SettingsRepository::class);
            $companyName = $settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault';

            $clientName = $ticket['email'];
            if (!empty($ticket['client_id'])) {
                $client = $this->container->make(ClientRepository::class)->find((int) $ticket['client_id']);
                if ($client) {
                    $clientName = $client['first_name'] . ' ' . $client['last_name'];
                }
            }

            if ($authorType === 'admin') {
                // Send reply notification to client
                $dispatcher->sendTemplate('ticket_reply', $ticket['email'], [
                    'ticket_id' => (string) $ticketId,
                    'ticket_subject' => $ticket['subject'],
                    'client_name' => $clientName,
                    'reply_message' => nl2br(e($reply['message'])),
                    'company_name' => $companyName,
                ], !empty($ticket['client_id']) ? (int) $ticket['client_id'] : null);
            } elseif ($authorType === 'client') {
                // Send reply notification to all admins
                $admins = $this->container->make(AdminRepository::class)->all();
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $dispatcher->sendTemplate('admin_ticket_reply', $admin['email'], [
                            'ticket_id' => (string) $ticketId,
                            'ticket_subject' => $ticket['subject'],
                            'client_name' => $clientName,
                            'reply_message' => nl2br(e($reply['message'])),
                            'company_name' => $companyName,
                        ]);
                    }
                }
            }
        });

        $hooks->register(HookPoints::ORDER_FRAUD_FLAGGED, function (array $payload) {
            $orderId = $payload['orderId'] ?? null;

            if ($orderId === null) {
                return;
            }

            $score = $payload['score'] ?? 0;

            $this->container->make(NotificationDispatcher::class)->dispatch(
                HookPoints::ORDER_FRAUD_FLAGGED,
                "Order #{$orderId} held for fraud review — score {$score}/100",
                ['orderId' => $orderId, 'score' => $score, 'reasons' => $payload['reasons'] ?? []]
            );
        });
    }

    public function loadRoutes(string ...$routeFiles): void
    {
        /** @var Router $router */
        $router = $this->container->make(Router::class);

        foreach ($routeFiles as $file) {
            $path = $this->basePath . '/routes/' . $file;

            if (is_file($path)) {
                (function (string $__path) use ($router) {
                    require $__path;
                })($path);
            }
        }
    }

    public function handle(Request $request): Response
    {
        if ($this->needsInstallRedirect($request)) {
            return Response::redirect('/install');
        }

        if (is_file($this->basePath('.installed.lock'))) {
            if ($this->systemSuspended() && $request->path() !== '/install') {
                return Response::html('503 System Suspended', 503);
            }

            // Check maintenance mode setting (admin routes /admin* and /login bypass maintenance mode)
            try {
                $settingsRepo = $this->container->make(\CodeVault\Settings\SettingsRepository::class);
                if ($settingsRepo->get('system.maintenance_mode', '0') === '1') {
                    $path = $request->path();
                    if (!str_starts_with($path, '/admin') && !str_starts_with($path, '/login') && !str_starts_with($path, '/assets')) {
                        return Response::html('<!DOCTYPE html><html><head><title>Maintenance Mode</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background:#0f172a;color:#e2e8f0;}h1{color:#f59e0b;}</style></head><body><h1>🛠️ System Maintenance</h1><p>We are currently performing scheduled maintenance. Please check back shortly.</p></body></html>', 530);
                    }
                }
            } catch (\Throwable) {
                // Ignore errors during bootstrap
            }

            try {
                $this->container->make(Migrator::class)->run();
                $this->container->make(AddonModuleService::class)->bootActiveAddons();
            } catch (\Throwable $e) {
                // Prevent database failures from crashing the system during early bootstrap
            }
        }

        /** @var SessionManager $session */
        $session = $this->container->make(SessionManager::class);
        $session->start();

        if ($this->requiresCsrfCheck($request)) {
            /** @var CsrfToken $csrf */
            $csrf = $this->container->make(CsrfToken::class);

            if (!$csrf->verify($request->input('_token'))) {
                // An AJAX caller (fetch/XHR) can't do anything useful with
                // a 403 HTML body — it tries to JSON.parse it and reports a
                // generic failure. Hand those callers a JSON error with an
                // actionable message (the token goes stale when a session
                // rolls over, which is common on long-lived admin pages
                // like the WHMCS migrator) so the real cause is visible.
                $isAjax = $request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1';

                if ($isAjax) {
                    // Pinpoint WHY the check failed so we stop guessing. The
                    // three booleans distinguish the real root causes:
                    //  - cookie_received false  => the browser never stored/
                    //    sent a session cookie (Secure flag over plain HTTP,
                    //    headers-already-sent, blocked cookie) — a fresh
                    //    token can never survive, which is why reloading
                    //    doesn't help.
                    //  - cookie_received true but session_has_token false =>
                    //    the cookie round-trips but the session STORE isn't
                    //    persisting data (broken Redis, unwritable save path).
                    //  - both true but submitted differs => a genuine stale/
                    //    mismatched token (the benign "reload fixes it" case).
                    $submitted = $request->input('_token');
                    $debug = [
                        'submitted_token_present' => is_string($submitted) && $submitted !== '',
                        'session_has_token' => $session->has('_csrf_token'),
                        'cookie_received' => isset($_COOKIE[session_name()]),
                        'session_id_present' => session_id() !== '',
                        'session_save_handler' => (string) ini_get('session.save_handler'),
                    ];

                    return SecurityHeaders::apply(Response::json([
                        'success' => false,
                        'message' => 'Security token expired or invalid — reload this page to get a fresh token, then submit again. (Nothing was changed.)',
                        // Retained for troubleshooting: distinguishes a lost
                        // session cookie (cookie_received=false) from a
                        // non-persisting session store (session_has_token=
                        // false) from a form that simply didn't submit the
                        // token (submitted_token_present=false).
                        'debug' => $debug,
                    ], 403));
                }

                return SecurityHeaders::apply(Response::html('403 Forbidden — invalid or missing CSRF token', 403));
            }
        }

        /** @var Router $router */
        $router = $this->container->make(Router::class);

        // Force existing clients without a Security PIN to set one before accessing the client portal
        $path = $request->path();
        if (str_starts_with($path, '/client') && !str_starts_with($path, '/client/login') && !str_starts_with($path, '/client/logout') && !str_starts_with($path, '/client/register') && !str_starts_with($path, '/client/recover-pin') && !str_starts_with($path, '/client/set-pin')) {
            /** @var \CodeVault\Clients\ClientAuthGuard $clientGuard */
            $clientGuard = $this->container->make(\CodeVault\Clients\ClientAuthGuard::class);
            $currentClient = $clientGuard->currentClient();
            if ($currentClient !== null && empty($currentClient['security_pin_hash'])) {
                return SecurityHeaders::apply(Response::redirect('/client/set-pin'));
            }
        }

        return SecurityHeaders::apply($router->dispatch($request));
    }

    /**
     * Fail-closed double-submit CSRF check (blueprint R11): every
     * state-changing request needs a `_token` field matching the
     * session's CsrfToken, checked before the request ever reaches a
     * route handler. SameSite=Lax cookies (SessionManager) already block
     * the primary CSRF vector in modern browsers — this is the fallback
     * for older browsers and edge cases (subdomain takeover, etc.) that
     * SameSite alone doesn't cover.
     *
     * Exempt: /api/* authenticates via a Bearer token (ApiAuthenticator),
     * not the ambient session cookie, so there's no session credential to
     * forge; /install/* runs before any account exists for an attacker to
     * act as; /pay/*\/webhook is called directly by the payment gateway's
     * own servers (Paystack/Flutterwave), which never have our session
     * cookie or CSRF token — PaymentCallbackController::webhook() verifies
     * each provider's own HMAC/shared-secret signature instead, which is
     * that route's actual anti-forgery protection.
     */
    private function requiresCsrfCheck(Request $request): bool
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'DELETE'], true)) {
            return false;
        }

        $path = $request->path();

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return false;
        }

        if ($path === '/install' || str_starts_with($path, '/install/')) {
            return false;
        }

        if (str_starts_with($path, '/pay/') && str_ends_with($path, '/webhook')) {
            return false;
        }

        return true;
    }

    /**
     * Install-redirect gate (ported from DGV7.11's `bc-connect.php`
     * pattern, blueprint §5): anything outside /install or /assets is
     * bounced to the installer until `.installed.lock` exists.
     */
    private function needsInstallRedirect(Request $request): bool
    {
        if (is_file($this->basePath('.installed.lock'))) {
            return false;
        }

        $path = $request->path();

        return $path !== '/install' && !str_starts_with($path, '/install/') && !str_starts_with($path, '/assets/');
    }

    /**
     * Cheap, DB-free check so the kill-switch works even if the database
     * is unreachable — a file existence check, not an IntegrityManager::check() call.
     */
    private function systemSuspended(): bool
    {
        return is_file($this->basePath('storage/system/.integrity.lock'));
    }

    public function basePath(string $path = ''): string
    {
        return $path === '' ? $this->basePath : $this->basePath . '/' . ltrim($path, '/');
    }
}
