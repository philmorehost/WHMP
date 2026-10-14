<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Database;

/**
 * The add-on's email templates. Seeded with INSERT IGNORE on activation and before
 * the first send, so an admin's edits in Email Templates are never overwritten.
 *
 * Wording never names Cloudflare's partner programmes or the platform: store
 * customers get these under their store's brand ({{company_name}} is rebranded by
 * EmailDispatcher), and every link points at the client's own site.
 */
final class CloudflareTemplates
{
    public const PENDING = 'cloudflare_zone_pending';
    public const ACTIVE = 'cloudflare_zone_active';
    public const REMINDER = 'cloudflare_zone_reminder';
    public const REMOVED = 'cloudflare_zone_removal_scheduled';

    /** @return array<string, array{name: string, subject: string, body: string}> */
    public static function all(): array
    {
        $sign = '<p>Thank you,<br>{{company_name}}</p>';
        $nsList = '<ul><li><strong>{{ns1}}</strong></li><li><strong>{{ns2}}</strong></li></ul>';

        return [
            self::PENDING => [
                'name' => 'Cloudflare: Domain Added (Action Needed)',
                'subject' => 'One step left to activate free Cloudflare for {{domain}}',
                'body' => '<p>Hello {{client_name}},</p>'
                    . '<p>Free Cloudflare CDN &amp; security has been set up for <strong>{{domain}}</strong>. We copied your existing DNS records, so nothing changes until you switch your nameservers.</p>'
                    . '<p>To activate it, change the nameservers for {{domain}} to:</p>' . $nsList
                    . '<p>{{ns_hint}}</p>'
                    . '<p><a href="{{manage_url}}">Manage Cloudflare for {{domain}}</a></p>' . $sign,
            ],
            self::ACTIVE => [
                'name' => 'Cloudflare: Domain Active',
                'subject' => 'Cloudflare is now protecting {{domain}}',
                'body' => '<p>Hello {{client_name}},</p>'
                    . '<p>Good news — <strong>{{domain}}</strong> is now active on Cloudflare. Your site is served through Cloudflare\'s global network with free SSL, caching and DDoS protection.</p>'
                    . '<p>You can manage DNS, SSL, caching and security settings any time:</p>'
                    . '<p><a href="{{manage_url}}">Manage Cloudflare for {{domain}}</a></p>' . $sign,
            ],
            self::REMINDER => [
                'name' => 'Cloudflare: Nameserver Reminder',
                'subject' => 'Reminder: finish activating Cloudflare for {{domain}}',
                'body' => '<p>Hello {{client_name}},</p>'
                    . '<p>Cloudflare for <strong>{{domain}}</strong> is still waiting for its nameservers to be changed. Until then your site works exactly as before, but without Cloudflare\'s protection.</p>'
                    . '<p>Change the nameservers for {{domain}} to:</p>' . $nsList
                    . '<p>{{ns_hint}}</p>'
                    . '<p><a href="{{manage_url}}">Manage Cloudflare for {{domain}}</a></p>' . $sign,
            ],
            self::REMOVED => [
                'name' => 'Cloudflare: Removal Scheduled',
                'subject' => 'Cloudflare will be removed from {{domain}} on {{delete_date}}',
                'body' => '<p>Hello {{client_name}},</p>'
                    . '<p>Cloudflare for <strong>{{domain}}</strong> is being turned off ({{reason}}). It will be removed on <strong>{{delete_date}}</strong>.</p>'
                    . '<p>{{ns_note}}</p>'
                    . '<p>Changed your mind? You can keep Cloudflare until that date from <a href="{{manage_url}}">your client area</a>.</p>' . $sign,
            ],
        ];
    }

    /** Inserts any missing template; never overwrites an edited one. */
    public static function ensure(Database $db): void
    {
        foreach (self::all() as $key => $template) {
            $db->insert(
                'INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$key, $template['name'], $template['subject'], $template['body']]
            );
        }
    }
}
