<?php

declare(strict_types=1);

namespace CodeVault\Hooks;

/**
 * The initial named hook-point catalog (blueprint §7 — frozen early so
 * later phases can build against it without churn). Grows over time, but
 * renaming an existing constant is a breaking change for every module/addon
 * that listens to it — treat these as a public API.
 */
final class HookPoints
{
    // --- Auth / security --------------------------------------------------
    public const PRE_LOGIN = 'PreLogin';
    public const CLIENT_LOGIN = 'ClientLogin';
    public const CLIENT_LOGIN_FAILED = 'ClientLoginFailed';
    public const CLIENT_LOGOUT = 'ClientLogout';
    public const ADMIN_LOGIN = 'AdminLogin';
    public const ADMIN_LOGIN_FAILED = 'AdminLoginFailed';
    public const BRUTEGUARD_IP_BLOCKED = 'BruteGuardIpBlocked';
    public const BRUTEGUARD_ACCOUNT_LOCKED = 'BruteGuardAccountLocked';
    public const BRUTEGUARD_IP_WHITELISTED = 'BruteGuardIpWhitelisted';

    // --- Clients -------------------------------------------------------
    public const CLIENT_ADD = 'ClientAdd';
    public const CLIENT_EDIT = 'ClientEdit';
    public const CLIENT_DELETE = 'ClientDelete';
    public const CLIENT_LOGIN_LOCKED = 'ClientLoginLocked';

    // --- Orders / cart ---------------------------------------------------
    public const ORDER_PLACED = 'OrderPlaced';
    public const ORDER_ACCEPTED = 'OrderAccepted';
    public const ORDER_CANCELLED = 'OrderCancelled';
    public const ORDER_FRAUD_FLAGGED = 'OrderFraudFlagged';

    // --- Billing / invoicing --------------------------------------------
    public const INVOICE_CREATED = 'InvoiceCreated';
    public const INVOICE_CREATE_PRE_EMAIL = 'InvoiceCreatePreEmail';
    public const INVOICE_PAID = 'InvoicePaid';
    public const INVOICE_REFUNDED = 'InvoiceRefunded';
    public const INVOICE_CANCELLED = 'InvoiceCancelled';
    public const CREDIT_APPLIED = 'CreditApplied';
    public const TRANSACTION_ADDED = 'TransactionAdded';
    public const INVOICE_OVERDUE = 'InvoiceOverdue';
    public const QUOTE_ACCEPTED = 'QuoteAccepted';
    public const QUOTE_DECLINED = 'QuoteDeclined';

    // --- Upgrade/downgrade -------------------------------------------------
    public const UPGRADE_REQUESTED = 'UpgradeRequested';
    public const UPGRADE_COMPLETED = 'UpgradeCompleted';

    // --- Provisioning / services -----------------------------------------
    public const BEFORE_MODULE_CREATE = 'BeforeModuleCreate';
    public const AFTER_MODULE_CREATE = 'AfterModuleCreate';
    public const AFTER_MODULE_SUSPEND = 'AfterModuleSuspend';
    public const AFTER_MODULE_UNSUSPEND = 'AfterModuleUnsuspend';
    public const AFTER_MODULE_TERMINATE = 'AfterModuleTerminate';
    public const AFTER_MODULE_CHANGE_DOMAIN = 'AfterModuleChangeDomain';
    public const SERVICE_STATUS_CHANGED = 'ServiceStatusChanged';

    // --- Domains ---------------------------------------------------------
    public const DOMAIN_REGISTERED = 'DomainRegistered';
    public const DOMAIN_TRANSFERRED = 'DomainTransferred';
    public const DOMAIN_RENEWED = 'DomainRenewed';
    public const DOMAIN_EXPIRED = 'DomainExpired';

    // --- Support -----------------------------------------------------------
    public const TICKET_OPEN = 'TicketOpen';
    public const TICKET_REPLY = 'TicketReply';
    public const TICKET_CLOSE = 'TicketClose';
    public const TICKET_ESCALATED = 'TicketEscalated';
    public const TICKET_MERGED = 'TicketMerged';
    public const TICKET_SPLIT = 'TicketSplit';

    // --- Cron / system -----------------------------------------------------
    public const DAILY_CRON_JOB = 'DailyCronJob';
    public const CRON_JOB_STARTED = 'CronJobStarted';
    public const CRON_JOB_FINISHED = 'CronJobFinished';

    // --- Affiliates / marketing -------------------------------------------
    public const AFFILIATE_SIGNUP = 'AffiliateSignup';
    public const AFFILIATE_COMMISSION_ACCRUED = 'AffiliateCommissionAccrued';

    /**
     * @return array<int, string> every registered constant name, for
     *   an admin "hook catalog" debug page
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
