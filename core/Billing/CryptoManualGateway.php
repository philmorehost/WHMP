<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;

/**
 * Manual cryptocurrency payments — USDT (TRC-20) — the human-reviewed
 * alternative to the Plisio gateway. There is no API: the admin pastes a
 * TRON wallet address, the client is shown the invoice balance as a USD
 * figure (USD is the quote currency exactly as it is for Plisio — crypto
 * is counted in dollars regardless of the invoice's own currency), the
 * client sends the USDT and uploads proof, and an admin approves before
 * anything is recorded as paid.
 *
 * The payment initiation (PaymentCallbackController::initiate) branches on
 * this class BEFORE capture(): instead of asking the module to charge, the
 * client is redirected to the instructions + proof-upload page. capture()
 * stays honest ("processed manually") so no code path can ever think this
 * gateway captured money by itself.
 *
 * Audience toggles (admin-configurable per the request): the gateway can be
 * enabled/disabled for PLATFORM CLIENTS and for RESELLERS independently.
 * A reseller's own storefront customers are governed by the standard
 * reseller_enabled column (seeded OFF here) — resellers keep accepting
 * orders through the admin's normal gateways either way; these toggles are
 * about who may pay the PLATFORM by manual crypto.
 */
final class CryptoManualGateway implements GatewayModule
{
    public const SLUG = 'crypto_manual';

    /** TRC-20 addresses: T + 33 base58 characters (no 0, O, I, l). */
    private const TRC20_PATTERN = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/';

    public function metadata(): array
    {
        return [
            'name' => 'Crypto — USDT (TRC-20) · Manual review',
            'description' => 'Clients send USDT on TRON to your wallet and upload payment proof; an admin approves before the invoice is paid. No API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'wallet_address' => ['type' => 'text', 'label' => 'USDT (TRC-20) Wallet Address', 'default' => ''],
            'instructions' => ['type' => 'textarea', 'label' => 'Payment Instructions Shown to Clients', 'default' => ''],
            'enable_for_clients' => ['type' => 'checkbox', 'label' => 'Available to platform clients', 'default' => '1'],
            'enable_for_resellers' => ['type' => 'checkbox', 'label' => 'Available to resellers', 'default' => '1'],
        ];
    }

    public function isOffsite(): bool
    {
        return false;
    }

    public function capture(array $params): array
    {
        return ['success' => false, 'message' => 'Manual crypto payments are confirmed by an admin after the client uploads proof — nothing is captured automatically.'];
    }

    public function refund(array $params): array
    {
        return ['success' => false, 'message' => 'Refunds of manual crypto payments are handled by an admin on the blockchain, not through this gateway.'];
    }

    public function void(array $params): array
    {
        return ['success' => false, 'message' => 'Not applicable to manual crypto payments.'];
    }

    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Manual crypto payments do not support tokenized recurring capture.'];
    }

    public function chargeToken(array $params): array
    {
        return ['success' => false, 'message' => 'Manual crypto payments do not support tokenized recurring capture.'];
    }

    public function handleCallback(array $rawPayload, array $headers): array
    {
        return ['valid' => false, 'event' => 'unsupported', 'data' => []];
    }

    /**
     * The audience gate, shared by the gateway listing (invoice page) and
     * the payment initiation choke point so a hand-crafted URL cannot
     * resurrect a hidden option:
     *
     *  - a reseller's STOREFRONT customer follows the standard
     *    reseller_enabled column, checked before this runs (seeded OFF);
     *  - a platform client follows `enable_for_clients`;
     *  - a reseller (store owner) paying their own invoices follows
     *    `enable_for_resellers`.
     *
     * Defaults are ON: missing config (never saved yet) means available —
     * the global is_enabled switch and a configured wallet are the real
     * gates; these toggles are the admin's fine-tuning.
     *
     * @param array<string, mixed> $config the gateway's decoded config
     */
    public static function audienceAllows(array $config, bool $isStorefrontCustomer, bool $isResellerOwner): bool
    {
        if ($isStorefrontCustomer) {
            // Storefront customers never pass through here — the caller has
            // already applied reseller_enabled. Kept explicit so a future
            // caller cannot forget the distinction.
            return true;
        }

        $key = $isResellerOwner ? 'enable_for_resellers' : 'enable_for_clients';

        return (int) ($config[$key] ?? 1) === 1;
    }

    /**
     * The wallet the admin configured, or '' when unset. Whitespace-only
     * config counts as unset.
     *
     * @param array<string, mixed> $config
     */
    public static function walletAddress(array $config): string
    {
        return trim((string) ($config['wallet_address'] ?? ''));
    }

    public static function walletAddressIsValid(string $address): bool
    {
        return preg_match(self::TRC20_PATTERN, $address) === 1;
    }

    /**
     * A payment must not be reviewed forever: the USD figure, the wallet it
     * was to be sent to, and the rate it was locked at are recorded on the
     * submission row at submit time (see the repository), so a rate move or
     * a wallet change afterwards can never re-price or mis-attribute a
     * payment an admin is about to approve.
     */
    public static function isManualCrypto(array $gatewayRow): bool
    {
        return (string) ($gatewayRow['slug'] ?? '') === self::SLUG;
    }
}
