<?php

declare(strict_types=1);

namespace CodeVault\Staff;

/**
 * The permission-key catalog (blueprint §4.3 "permission matrix") — the
 * code-level source of truth a role's grants are checked against, the same
 * pattern HookPoints uses for hook names. A role with `is_super_admin = 1`
 * bypasses this list entirely and always passes every check.
 */
final class PermissionRegistry
{
    public const CLIENTS_VIEW = 'clients.view';
    public const CLIENTS_MANAGE = 'clients.manage';
    public const CLIENT_NOTIFICATIONS_MANAGE = 'client_notifications.manage';
    public const STAFF_MANAGE = 'staff.manage';
    public const ROLES_MANAGE = 'roles.manage';
    public const SECURITY_MANAGE = 'security.manage';
    public const CUSTOM_FIELDS_MANAGE = 'custom_fields.manage';
    public const EMAIL_TEMPLATES_MANAGE = 'email_templates.manage';
    public const ACTIVITY_LOG_VIEW = 'activity_log.view';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const PRODUCTS_MANAGE = 'products.manage';
    public const ORDERS_MANAGE = 'orders.manage';
    public const INVOICES_MANAGE = 'invoices.manage';
    public const GATEWAYS_MANAGE = 'gateways.manage';
    public const PROMOTIONS_MANAGE = 'promotions.manage';
    public const SERVERS_MANAGE = 'servers.manage';
    public const DOMAINS_MANAGE = 'domains.manage';
    public const TICKETS_MANAGE = 'tickets.manage';
    public const KB_MANAGE = 'kb.manage';
    public const DOWNLOADS_MANAGE = 'downloads.manage';
    public const ANNOUNCEMENTS_MANAGE = 'announcements.manage';
    public const AFFILIATES_MANAGE = 'affiliates.manage';
    public const REPORTS_VIEW = 'reports.view';
    public const REPORTS_MANAGE = 'reports.manage';
    public const PRIVACY_MANAGE = 'privacy.manage';
    public const ADDONS_MANAGE = 'addons.manage';
    public const WIDGETS_MANAGE = 'widgets.manage';
    public const SECURITY_QUESTIONS_MANAGE = 'security_questions.manage';

    /**
     * @return array<string, array{label: string, group: string}> permission
     *   key => display metadata, for rendering the permission matrix checkbox grid
     */
    public static function all(): array
    {
        return [
            self::CLIENTS_VIEW => ['label' => 'View clients', 'group' => 'Clients'],
            self::CLIENTS_MANAGE => ['label' => 'Add/edit clients', 'group' => 'Clients'],
            self::CLIENT_NOTIFICATIONS_MANAGE => ['label' => 'Send in-app client notifications', 'group' => 'Clients'],
            self::STAFF_MANAGE => ['label' => 'Manage staff accounts', 'group' => 'Staff'],
            self::ROLES_MANAGE => ['label' => 'Manage roles & permissions', 'group' => 'Staff'],
            self::SECURITY_MANAGE => ['label' => 'Manage BruteGuard rules', 'group' => 'Security'],
            self::CUSTOM_FIELDS_MANAGE => ['label' => 'Manage custom fields', 'group' => 'Configuration'],
            self::EMAIL_TEMPLATES_MANAGE => ['label' => 'Manage email templates', 'group' => 'Configuration'],
            self::ACTIVITY_LOG_VIEW => ['label' => 'View activity log', 'group' => 'Configuration'],
            self::SETTINGS_MANAGE => ['label' => 'Manage general settings', 'group' => 'Configuration'],
            self::PRODUCTS_MANAGE => ['label' => 'Manage products & pricing', 'group' => 'Billing'],
            self::ORDERS_MANAGE => ['label' => 'Manage orders', 'group' => 'Billing'],
            self::INVOICES_MANAGE => ['label' => 'Manage invoices', 'group' => 'Billing'],
            self::GATEWAYS_MANAGE => ['label' => 'Manage payment gateways', 'group' => 'Billing'],
            self::PROMOTIONS_MANAGE => ['label' => 'Manage promotions & coupon codes', 'group' => 'Billing'],
            self::SERVERS_MANAGE => ['label' => 'Manage servers & server groups', 'group' => 'Provisioning'],
            self::DOMAINS_MANAGE => ['label' => 'Manage domains & registrars', 'group' => 'Provisioning'],
            self::TICKETS_MANAGE => ['label' => 'Manage support tickets', 'group' => 'Support'],
            self::KB_MANAGE => ['label' => 'Manage knowledgebase', 'group' => 'Support'],
            self::DOWNLOADS_MANAGE => ['label' => 'Manage downloads', 'group' => 'Support'],
            self::ANNOUNCEMENTS_MANAGE => ['label' => 'Manage announcements & network issues', 'group' => 'Support'],
            self::AFFILIATES_MANAGE => ['label' => 'Manage affiliates & payouts', 'group' => 'Billing'],
            self::REPORTS_VIEW => ['label' => 'View reports', 'group' => 'Billing'],
            self::REPORTS_MANAGE => ['label' => 'Activate & run custom report modules', 'group' => 'Billing'],
            self::PRIVACY_MANAGE => ['label' => 'Process GDPR/privacy requests', 'group' => 'Configuration'],
            self::ADDONS_MANAGE => ['label' => 'Activate & configure addon modules', 'group' => 'Configuration'],
            self::WIDGETS_MANAGE => ['label' => 'Activate & configure dashboard widgets', 'group' => 'Configuration'],
            self::SECURITY_QUESTIONS_MANAGE => ['label' => 'Activate & configure security question modules', 'group' => 'Security'],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
