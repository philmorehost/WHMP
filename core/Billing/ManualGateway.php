<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Modules\GatewayModule;

/**
 * Bank Transfer / Manual Payment — the always-available, no-API-keys
 * gateway (blueprint §10: real gateway choice for R4 was left open).
 * There's nothing to "capture": the client sees payment instructions, and
 * an admin confirms receipt via PaymentService directly once the transfer
 * lands — not through this class at all. tokenize/chargeToken/callback
 * aren't meaningful for a manual gateway, matching plenty of real
 * offline-payment gateways.
 */
final class ManualGateway implements GatewayModule
{
    public function metadata(): array
    {
        return [
            'name' => 'Bank Transfer / Manual Payment',
            'description' => 'Client pays by bank transfer; an admin confirms receipt manually.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'bank_details' => ['type' => 'textarea', 'label' => 'Bank Transfer Instructions', 'default' => ''],
        ];
    }

    public function isOffsite(): bool
    {
        return false;
    }

    public function capture(array $params): array
    {
        return ['success' => false, 'message' => 'Awaiting manual confirmation from an admin after bank transfer.'];
    }

    public function refund(array $params): array
    {
        return ['success' => false, 'message' => 'Manual gateway refunds are recorded by an admin, not processed automatically.'];
    }

    public function void(array $params): array
    {
        return ['success' => false, 'message' => 'Not applicable to manual payments.'];
    }

    public function tokenize(array $params): array
    {
        return ['success' => false, 'message' => 'Manual gateway does not support tokenized recurring capture.'];
    }

    public function chargeToken(array $params): array
    {
        return ['success' => false, 'message' => 'Manual gateway does not support tokenized recurring capture.'];
    }

    public function handleCallback(array $rawPayload, array $headers): array
    {
        return ['valid' => false, 'event' => 'unsupported', 'data' => []];
    }
}
