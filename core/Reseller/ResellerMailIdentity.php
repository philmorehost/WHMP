<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * Which sender a store's customer sees on email.
 *
 * THE NAME IS ALWAYS THE STORE'S. THE ADDRESS ONLY WHEN THEY HAVE A USABLE ONE.
 *
 * A display name is decoration — it cannot make a message undeliverable, so it is
 * always safe to put the store's name on a message we send. An address is not: mail
 * sent as `support@their-domain` from our server, when that domain has not authorised
 * us in SPF and DKIM, fails DMARC alignment and is filtered or refused. That is a
 * delivery failure the reseller never sees, which is why the alignment state is
 * surfaced in the portal (see warning()) rather than being assumed to be fine.
 *
 * WHY THE ADDRESS IS USED EVEN WHEN THE CHECK HAS FAILED OR NEVER RAN
 *
 * Because the alternative is worse and more silent. Gating on the check would mean a
 * resolver outage — or a provider that rate-limits DNS — quietly reverts the message to
 * the PLATFORM's address, which is the exact white-label leak this whole feature exists
 * to close. A reseller would have to be told that, and nothing would tell them. So the
 * address is used, and the portal carries a loud, DATED warning telling them exactly
 * which DNS records to publish.
 *
 * A malformed address is the one case that does fall back, and for a mechanical reason
 * rather than a delivery one: SmtpMailer validates the sender, so a typo would throw
 * and the customer would receive NOTHING. Trading a cosmetic flaw for a lost message
 * is not a trade worth making.
 */
final class ResellerMailIdentity
{
    /**
     * The sender to use for a message about this store's customer.
     *
     * Returns ['name' => ...] alone when there is no usable address, which tells the
     * transport to keep the configured platform address and only take the name.
     *
     * @param array<string, mixed>|null $store
     * @return array{name: string, email?: string}
     */
    public static function forStore(?array $store, string $platformName): array
    {
        $name = trim((string) ($store['brand_name'] ?? ''));
        $name = $name !== '' ? $name : $platformName;

        $email = self::address($store);

        return $email === null ? ['name' => $name] : ['name' => $name, 'email' => $email];
    }

    /**
     * The COMPLETE sender for mail written on a store's behalf: always a name and
     * always an address, neither of them ours.
     *
     * forStore() above is allowed to return a name alone, which tells the transport
     * to keep the configured platform address — and that address is exactly what a
     * store's customer must not see. So when the store has no usable support address
     * of its own, this falls back to `noreply@` on the STORE's host: the verified
     * custom domain if it has one, else its platform subdomain (an address the
     * customer already sees in their browser, so it reveals nothing new).
     *
     * Same trade-off as the class docblock describes, on purpose: an address the
     * domain has not authorised can be filtered, but reverting to our address is a
     * silent white-label leak. The reseller mail page tells them which DNS records
     * make it deliverable.
     *
     * @param array<string, mixed> $store
     * @param string               $storeHost the host the store is served on (ResellerStoreLocator::hostFor)
     * @return array{name: string, email: string}
     */
    public static function senderFor(array $store, string $storeHost): array
    {
        $email = self::address($store);

        if ($email === null) {
            $candidate = 'noreply@' . strtolower(trim($storeHost));
            $email = filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
        }

        return [
            'name' => self::displayName($store),
            // Last resort for a host so odd it is not a legal address (an IP-only
            // dev install): still not ours by name.
            'email' => $email ?? 'noreply@localhost.localdomain',
        ];
    }

    /**
     * The name a store is known by — its brand, else its slug made readable.
     *
     * NEVER the platform's name. forStore() falls back to ours because a ticket reply
     * has to be signed with SOMETHING, but on a store that something must be the store:
     * "acme-hosting" becomes "Acme Hosting", which is at least the name in the address
     * bar.
     *
     * @param array<string, mixed> $store
     */
    public static function displayName(array $store): string
    {
        $brand = trim((string) ($store['brand_name'] ?? ''));

        if ($brand !== '') {
            return $brand;
        }

        $slug = trim((string) ($store['slug'] ?? ''));

        return $slug !== '' ? ucwords(str_replace(['-', '_'], ' ', $slug)) : 'Support';
    }

    /**
     * The store's own support address, or null when there is not a usable one.
     *
     * @param array<string, mixed>|null $store
     */
    public static function address(?array $store): ?string
    {
        $email = trim((string) ($store['support_email'] ?? ''));

        if ($email === '') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Has the store's domain been CONFIRMED to authorise us for this address?
     *
     * False is not the same as "do not use it" — see the class docblock. It means the
     * portal must warn.
     *
     * @param array<string, mixed>|null $store
     */
    public static function isAligned(?array $store): bool
    {
        return (string) ($store['support_email_status'] ?? '') === 'aligned';
    }

    /**
     * What to tell the reseller, or null when there is nothing to say.
     *
     * Three distinct states, and they are NOT the same message: no address (nothing to
     * warn about), an address that failed the check, and an address nobody has checked
     * yet. The last one is the dangerous one precisely because it looks fine.
     *
     * @param array<string, mixed>|null $store
     */
    public static function warning(?array $store): ?string
    {
        if (self::address($store) === null) {
            return null;
        }

        if (self::isAligned($store)) {
            return null;
        }

        $checkedAt = trim((string) ($store['support_email_checked_at'] ?? ''));
        $status = (string) ($store['support_email_status'] ?? '');

        if ($status === 'misaligned') {
            return 'Your support address is not yet authorised to send for your domain'
                . ($checkedAt !== '' ? ' (last checked ' . $checkedAt . ')' : '')
                . '. Until the DNS records below are added, replies to your customers will be treated as '
                . 'unauthenticated mail and may land in their spam folder.';
        }

        return 'Your support address has not been checked yet. Until your domain authorises our server to '
            . 'send for it, replies to your customers may be treated as unauthenticated mail and land in their '
            . 'spam folder.';
    }

    /**
     * The records to publish, when we have them.
     *
     * Stored verbatim at check time, because a warning that does not say what to DO is
     * only half a warning.
     *
     * @param array<string, mixed>|null $store
     */
    public static function remediation(?array $store): ?string
    {
        $detail = trim((string) ($store['support_email_detail'] ?? ''));

        return $detail !== '' ? $detail : null;
    }
}
