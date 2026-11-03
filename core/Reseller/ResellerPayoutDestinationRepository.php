<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Where a store's money is sent: storage, and the one place that describes a
 * destination in words.
 *
 * Storage-only, like the other reseller repositories — the rules about when a
 * destination may be changed or verified live in the service/controller that
 * calls this, and every reader goes through describe() so the frozen snapshot on
 * a payout and the live row on the account page cannot be worded differently.
 *
 * One destination per store (UNIQUE on reseller_id), so save() is an upsert and
 * there is no "which one" question at payout time.
 */
final class ResellerPayoutDestinationRepository
{
    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    /** Payout in USDT to a TRON (TRC-20) wallet address — pays out worldwide. */
    public const METHOD_CRYPTO_TRC20 = 'crypto_trc20';

    /** Payout to the reseller's Bybit account (UID or account email). */
    public const METHOD_BYBIT = 'bybit';

    /** Every payout method a reseller may choose from, with display names. */
    public const METHODS = [
        self::METHOD_BANK_TRANSFER => 'Bank transfer',
        self::METHOD_CRYPTO_TRC20 => 'Crypto — USDT (TRC-20)',
        self::METHOD_BYBIT => 'Bybit account',
    ];

    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $resellerId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM reseller_payout_destinations WHERE reseller_id = ?',
            [$resellerId]
        );
    }

    /**
     * Create or replace the store's destination.
     *
     * An edit clears the verification: the verified flag is a statement about a
     * set of details, so a changed account number has not been verified by
     * whoever verified the old one. Leaving the mark standing would be the
     * dangerous direction — it would let a reseller get verified, change the
     * number, and keep the tick.
     *
     * @param array<string, mixed> $fields
     */
    public function save(int $resellerId, ?int $clientId, array $fields, ?string $now = null): void
    {
        $now ??= (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->statement(
            'INSERT INTO reseller_payout_destinations
                (reseller_id, client_id, method, account_name, account_number, bank_name, bank_code,
                 currency_id, verified_at, verified_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id),
                method = VALUES(method),
                account_name = VALUES(account_name),
                account_number = VALUES(account_number),
                bank_name = VALUES(bank_name),
                bank_code = VALUES(bank_code),
                currency_id = VALUES(currency_id),
                verified_at = NULL,
                verified_by = NULL,
                updated_at = VALUES(updated_at)',
            [
                $resellerId,
                $clientId,
                (string) ($fields['method'] ?? self::METHOD_BANK_TRANSFER),
                (string) $fields['account_name'],
                (string) $fields['account_number'],
                $fields['bank_name'] === null || $fields['bank_name'] === '' ? null : (string) $fields['bank_name'],
                $fields['bank_code'] === null || $fields['bank_code'] === '' ? null : (string) $fields['bank_code'],
                $fields['currency_id'] === null ? null : (int) $fields['currency_id'],
                $now,
                $now,
            ]
        );
    }

    /** Remove the store's destination. Returns whether one was there. */
    public function forget(int $resellerId): bool
    {
        return $this->db->update(
            'DELETE FROM reseller_payout_destinations WHERE reseller_id = ?',
            [$resellerId]
        ) > 0;
    }

    /**
     * Record that a human checked these details.
     *
     * Nothing here can prove a bank account exists, so this is an attestation,
     * and `verified_by` is what keeps it from being anonymous.
     */
    public function markVerified(int $resellerId, ?int $adminId, ?string $now = null): bool
    {
        return $this->db->update(
            'UPDATE reseller_payout_destinations
                SET verified_at = ?, verified_by = ?, updated_at = ?
              WHERE reseller_id = ?',
            [
                $now ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $adminId,
                $now ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $resellerId,
            ]
        ) > 0;
    }

    public function clearVerification(int $resellerId): bool
    {
        return $this->db->update(
            'UPDATE reseller_payout_destinations
                SET verified_at = NULL, verified_by = NULL, updated_at = ?
              WHERE reseller_id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $resellerId]
        ) > 0;
    }

    /**
     * The destination in words — the same wording the account page shows and the
     * payout freezes, so a support conversation about a payout quotes one string.
     *
     * @param array<string, mixed>|null $destination
     */
    public static function describe(?array $destination): ?string
    {
        if ($destination === null) {
            return null;
        }

        $name = trim((string) ($destination['account_name'] ?? ''));
        $number = trim((string) ($destination['account_number'] ?? ''));
        $bank = trim((string) ($destination['bank_name'] ?? ''));
        $code = trim((string) ($destination['bank_code'] ?? ''));
        $method = (string) ($destination['method'] ?? self::METHOD_BANK_TRANSFER);

        // Crypto and Bybit destinations word differently: the "account number"
        // column carries a wallet address or a UID/email there, and bank
        // fields mean nothing. The frozen payout snapshot and the account
        // page both go through here, so they can never disagree.
        if ($method === self::METHOD_CRYPTO_TRC20) {
            $parts = array_filter([
                $name === '' ? null : $name,
                $number === '' ? null : 'USDT (TRC-20) ' . $number,
            ]);

            return $parts === [] ? null : implode(' · ', $parts);
        }

        if ($method === self::METHOD_BYBIT) {
            $parts = array_filter([
                $name === '' ? null : $name,
                $number === '' ? null : 'Bybit account ' . $number,
            ]);

            return $parts === [] ? null : implode(' · ', $parts);
        }

        $parts = array_filter([
            $name === '' ? null : $name,
            $number === '' ? null : 'account ' . $number,
            $bank === '' ? null : $bank,
            $code === '' ? null : 'code ' . $code,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Whether $number is a well-formed credential for $method — shape only:
     * nothing here can prove a wallet or account exists (verification stays a
     * human's attestation, exactly as for bank details).
     */
    public static function isValidDetail(string $method, string $number): bool
    {
        return match ($method) {
            // TRC-20 addresses: base58check, always start with T, 34 chars.
            self::METHOD_CRYPTO_TRC20 => preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $number) === 1,
            // A Bybit account is identified by its UID (8-12 digits) or the
            // account's email address — either reaches the same wallet.
            self::METHOD_BYBIT => preg_match('/^\d{8,12}$/', $number) === 1
                || filter_var($number, FILTER_VALIDATE_EMAIL) !== false,
            self::METHOD_BANK_TRANSFER => mb_strlen($number) <= 64,
            default => false,
        };
    }
}
