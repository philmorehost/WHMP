<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * The numbered statements that have been issued, and the allocation of their
 * numbers.
 *
 * Deliberately dumb SQL, like the other repositories in this namespace: it applies
 * no money rule and decides no period. It writes what it is given and hands back
 * what was frozen.
 *
 * WHY THE NUMBER FORMAT LIVES IN *THIS* CLASS
 *
 * The allocator and the format have to agree, so they sit together. The unique key
 * on (reseller_id, period_year, seq) makes two rows with the same sequence
 * impossible, but it cannot stop a bug in the FORMATTING turning two different
 * sequences into the same string — 'STMT-2026-1' and 'STMT-2026-01' are different
 * seqs and one number. The (reseller_id, number) key catches that, and keeping the
 * format next to the allocator is what makes the two easy to read together.
 *
 * WHY THE ROWS ARE THE COUNTER
 *
 * The next sequence is MAX(seq) + 1 over this store's own rows, not a value in a
 * counter table. A separate counter can drift out of step with the documents, and
 * the one thing a number sequence must never do is disagree with the documents
 * carrying it. Counting the documents cannot drift from the documents.
 */
final class ResellerStatementRepository
{
    /**
     * Every column, including both JSON payloads — there is no reason to read a
     * document without the content that makes it frozen.
     */
    private const COLUMNS = 'id, reseller_id, client_id, number, seq, period_year, period_from, period_to,
        opening_base, credits_base, debits_base, closing_base, withdrawable_base, entry_count,
        currency_id, currency_code, currency_rate, closing_converted, withdrawable_converted,
        line_items, our_identity, issued_at, issued_by';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The statement already issued for this exact period, if any.
     *
     * This is the idempotency question, and it must be asked BEFORE a number is
     * allocated: asking afterwards would burn a sequence value on every repeated
     * click, and a burned value looks exactly like a lost document.
     *
     * @return array<string, mixed>|null
     */
    public function forPeriod(int $resellerId, string $from, string $to): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM reseller_statements
             WHERE reseller_id = ? AND period_from = ? AND period_to = ? LIMIT 1',
            [$resellerId, $from, $to]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM reseller_statements WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * The store's most recent statement — what "last issued" on a page means.
     *
     * Ordered by issued_at and then id, NOT by seq: the sequence resets each year,
     * so once a year has rolled over the highest seq is no longer the newest
     * document.
     *
     * @return array<string, mixed>|null
     */
    public function latestFor(int $resellerId): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM reseller_statements
             WHERE reseller_id = ?
             ORDER BY issued_at DESC, id DESC LIMIT 1',
            [$resellerId]
        );
    }

    /**
     * Every statement issued to a store, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forReseller(int $resellerId, int $limit = 100): array
    {
        return $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM reseller_statements
             WHERE reseller_id = ?
             ORDER BY issued_at DESC, id DESC
             LIMIT ' . max(1, $limit),
            [$resellerId]
        );
    }

    /**
     * Allocate the next number for this store and year, then write the row — in
     * ONE transaction, so a failure between the two can neither burn a sequence
     * value nor leave a document stored without its content.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, number: string, seq: int}
     */
    public function create(array $data): array
    {
        $resellerId = (int) $data['reseller_id'];
        $year = (int) $data['period_year'];

        return $this->db->transaction(function () use ($resellerId, $year, $data): array {
            $seq = max(1, (int) ($this->db->selectOne(
                'SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq FROM reseller_statements
                 WHERE reseller_id = ? AND period_year = ?',
                [$resellerId, $year]
            )['next_seq'] ?? 1));

            $number = self::formatNumber($year, $seq);

            $id = (int) $this->db->insert(
                'INSERT INTO reseller_statements
                    (reseller_id, client_id, number, seq, period_year, period_from, period_to,
                     opening_base, credits_base, debits_base, closing_base, withdrawable_base, entry_count,
                     currency_id, currency_code, currency_rate, closing_converted, withdrawable_converted,
                     line_items, our_identity, issued_at, issued_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $resellerId,
                    $data['client_id'] === null ? null : (int) $data['client_id'],
                    $number,
                    $seq,
                    $year,
                    (string) $data['period_from'],
                    (string) $data['period_to'],
                    (float) $data['opening_base'],
                    (float) $data['credits_base'],
                    (float) $data['debits_base'],
                    (float) $data['closing_base'],
                    (float) $data['withdrawable_base'],
                    (int) $data['entry_count'],
                    $data['currency_id'] === null ? null : (int) $data['currency_id'],
                    $data['currency_code'] === null ? null : (string) $data['currency_code'],
                    (float) ($data['currency_rate'] ?? 1.0),
                    (float) ($data['closing_converted'] ?? 0.0),
                    (float) ($data['withdrawable_converted'] ?? 0.0),
                    (string) $data['line_items'],
                    (string) $data['our_identity'],
                    (string) $data['issued_at'],
                    $data['issued_by'] === null ? null : (int) $data['issued_by'],
                ]
            );

            return ['id' => $id, 'number' => $number, 'seq' => $seq];
        });
    }

    /**
     * The human-facing number: per store, per year, zero-padded to four digits.
     *
     * Four digits is a width, not a limit — the 10,000th statement of a year
     * becomes STMT-2026-10000 rather than wrapping, because a wrapped number is a
     * duplicate number and the unique key would then refuse a legitimate document.
     */
    public static function formatNumber(int $year, int $seq): string
    {
        return 'STMT-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
