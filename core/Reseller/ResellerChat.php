<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * A store's own support chat: a WhatsApp button by default, Tawk.To if they want it.
 *
 * WHY A RESELLER GETS A CHAT AT ALL, AND WHY IT IS NOT THE PLATFORM'S
 *
 * The platform's Tawk.To widget is one global configuration rendered into
 * `layouts.client`, which is the layout behind every public page — so it appears
 * on a reseller's storefront too. That puts OUR live chat in the middle of
 * somebody else's business, where their customer can ask us about the reseller's
 * prices. Every store therefore gets its own answer to "how do I contact you",
 * and the platform's never appears on a host that matches a store.
 *
 * WHY WHATSAPP IS THE DEFAULT AND TAWK.TO IS OPTIONAL
 *
 * A reseller has a phone number from the day they sign up and a Tawk.To property
 * from... maybe never. Making the upgrade optional means the customer always has
 * a working way to reach the store, instead of a blank corner where a chatbox
 * should be.
 *
 * WHY ONLY THE TAWK PROPERTY ID IS STORED
 *
 * Because the id is the only part of Tawk's embed that belongs in a URL we
 * build. Accepting a pasted snippet would mean putting a reseller's arbitrary
 * JavaScript into a page served under this platform's security headers — a store
 * could then read the platform's own session on any host that resolves here.
 * An id validated as alphanumeric, interpolated by us into a snippet we wrote,
 * cannot do that. `normaliseTawkProperty()` therefore REFUSES anything that is
 * not id-shaped, which also catches the common mistake of pasting the whole
 * embed into the id field.
 */
final class ResellerChat
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_WHATSAPP = 'whatsapp';
    public const PROVIDER_TAWKTO = 'tawkto';

    /** WhatsApp's ceiling is 15 digits (E.164); 8 is the shortest real number. */
    private const MIN_WHATSAPP_DIGITS = 8;
    private const MAX_WHATSAPP_DIGITS = 15;

    /** Tawk uses 'default' for a property with a single widget. */
    public const DEFAULT_WIDGET = 'default';

    /**
     * Reduce whatever the reseller typed to the digits `wa.me` needs, or NULL if
     * it cannot be a phone number.
     *
     * A leading `+` or `00` is an international prefix and is dropped, because
     * `wa.me` takes neither. Everything else non-numeric (spaces, dashes,
     * brackets) is dropped too — people type phone numbers in whatever shape
     * their phone shows them, and refusing those would be pedantry.
     */
    public static function normaliseWhatsapp(string $number): ?string
    {
        $trimmed = trim($number);

        if ($trimmed === '') {
            return null;
        }

        $hadPlus = str_starts_with($trimmed, '+');
        $digits = (string) preg_replace('~\D+~', '', $trimmed);

        if (!$hadPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < self::MIN_WHATSAPP_DIGITS || strlen($digits) > self::MAX_WHATSAPP_DIGITS) {
            return null;
        }

        return $digits;
    }

    /**
     * The Tawk.To property id, or NULL if that is not what was given.
     *
     * Deliberately strict. The value ends up inside a `<script src>` we generate,
     * so it is validated as a plain alphanumeric token rather than escaped or
     * trusted — escaping would be the wrong tool for something that has to be
     * interpolated into JavaScript we are building.
     */
    public static function normaliseTawkProperty(string $propertyId): ?string
    {
        $propertyId = trim($propertyId);

        if ($propertyId === '') {
            return null;
        }

        return preg_match('~^[A-Za-z0-9]{6,64}$~', $propertyId) === 1 ? $propertyId : null;
    }

    /** The widget id, defaulting to Tawk's own `default` when unset. */
    public static function normaliseTawkWidget(string $widgetId): ?string
    {
        $widgetId = trim($widgetId);

        if ($widgetId === '') {
            return null;
        }

        return preg_match('~^[A-Za-z0-9_-]{1,64}$~', $widgetId) === 1 ? $widgetId : null;
    }

    /**
     * Does that look like a whole pasted embed rather than an id? Used to explain
     * the refusal, because "invalid" alone leaves somebody staring at a field
     * they filled in from Tawk's own instructions.
     */
    public static function looksLikePastedEmbed(string $value): bool
    {
        return stripos($value, 'tawk') !== false || str_contains($value, '<script');
    }

    /**
     * The store's chat settings as FORM VALUES — `''` rather than null, because
     * the only consumer is an `input value=""`.
     *
     * Shared by the reseller's page and the admin's, so the two cannot disagree
     * about what "not set" looks like in a field.
     *
     * @param array<string, mixed>|null $store a `resellers` row, or null
     * @return array{support_whatsapp: string, tawk_property_id: string, tawk_widget_id: string}
     */
    public static function formValues(?array $store): array
    {
        return [
            'support_whatsapp' => trim((string) ($store['support_whatsapp'] ?? '')),
            'tawk_property_id' => trim((string) ($store['tawk_property_id'] ?? '')),
            'tawk_widget_id' => trim((string) ($store['tawk_widget_id'] ?? '')),
        ];
    }

    /**
     * What this store should show. Tawk.To wins when it is configured, because a
     * reseller who has set it up has deliberately chosen it.
     *
     * @param array<string, mixed> $store a `resellers` row
     */
    public static function provider(array $store): string
    {
        if (trim((string) ($store['tawk_property_id'] ?? '')) !== '') {
            return self::PROVIDER_TAWKTO;
        }

        if (trim((string) ($store['support_whatsapp'] ?? '')) !== '') {
            return self::PROVIDER_WHATSAPP;
        }

        return self::PROVIDER_NONE;
    }

    /**
     * A click-to-chat link. The greeting is pre-filled so the conversation opens
     * with the customer knowing which shop they are talking to.
     */
    public static function whatsappUrl(string $digits, string $greeting): string
    {
        $url = 'https://wa.me/' . $digits;

        return trim($greeting) === '' ? $url : $url . '?text=' . rawurlencode($greeting);
    }

    /**
     * Tawk's own loader, built here rather than pasted by the reseller, with the
     * validated ids interpolated into the one place they are used.
     *
     * The inline `<script>` needs the per-response nonce to satisfy `script-src`;
     * the caller stamps it (SecurityHeaders::stampInlineScripts), because the
     * nonce only exists once a response is being built.
     */
    public static function tawkEmbed(string $propertyId, ?string $widgetId = null): string
    {
        $widget = $widgetId === null || trim($widgetId) === '' ? self::DEFAULT_WIDGET : trim($widgetId);

        return '<script type="text/javascript">'
            . 'var Tawk_API=Tawk_API||{},Tawk_LoadStart=new Date();'
            . '(function(){var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];'
            . 's1.async=true;'
            . 's1.src="https://embed.tawk.to/' . $propertyId . '/' . $widget . '";'
            . 's1.charset="UTF-8";s1.setAttribute("crossorigin","*");s0.parentNode.insertBefore(s1,s0);})();'
            . '</script>';
    }
}
