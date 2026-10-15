<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Payment gateway modules — both tokenized merchant gateways and
 * third-party/redirect gateways (blueprint §4.4 payment engine). PCI scope:
 * implementations tokenize; nothing in the core ever stores a raw PAN.
 */
interface GatewayModule extends Module
{
    /**
     * True for gateways that redirect off-site (PayPal-style); false for
     * gateways that capture a token in-page (Stripe Elements-style).
     */
    public function isOffsite(): bool;

    /** @return array{success: bool, redirectUrl?: string, transactionId?: string, message: string} */
    public function capture(array $params): array;

    public function refund(array $params): array;

    public function void(array $params): array;

    /**
     * Store a reusable payment-method token for recurring capture.
     */
    public function tokenize(array $params): array;

    public function chargeToken(array $params): array;

    /**
     * Ask the provider what actually happened to a reference, server-side.
     *
     * A callback body is attacker-controlled; only this answer is trusted to
     * mark an invoice paid. Manual/offline gateways return success=false rather
     * than pretending, so a stray callback can never settle one.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, status: string, reference: string, amount: float, metadata: array<string, mixed>}
     */
    public function verifyTransaction(string $reference, array $config): array;

    /**
     * Verify + parse an inbound IPN/webhook callback.
     *
     * @return array{valid: bool, event: string, data: array}
     */
    public function handleCallback(array $rawPayload, array $headers): array;
}
