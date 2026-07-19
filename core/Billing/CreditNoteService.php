<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use RuntimeException;

/**
 * Issues a formal Credit Note (blueprint §4.3 Billing "Credit & Debit
 * Notes") — a line-itemized document that grants account credit, distinct
 * from the client-profile "Grant Credit" form (which is still just a bare
 * ledger entry, unchanged) by having a real document/PDF and an audit
 * trail linking the ledger entry back to it.
 *
 * Deliberately immutable once issued — there is no void()/reverse(). A
 * partial-reversal feature would need to track how much of the note's
 * granted credit is still unspent (the ledger only tracks a running total,
 * not per-source remaining balances), which is real complexity a v1
 * shouldn't guess at for a financial document. If an admin makes a
 * mistake, the existing "Grant Credit" negative-amount path already
 * covers a manual correction — this mirrors how Invoice::cancel() is
 * one-directional too.
 */
final class CreditNoteService
{
    public function __construct(
        private readonly Database $db,
        private readonly CreditNoteRepository $creditNotes,
        private readonly ClientCreditRepository $ledger,
        private readonly ClientRepository $clients,
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * @param array<int, array{description: string, amount: float}> $items
     * @return array{success: bool, id?: int, error?: string}
     */
    public function issue(int $clientId, ?int $invoiceId, string $reason, array $items, ?int $adminId): array
    {
        $client = $this->clients->find($clientId);

        if ($client === null) {
            return ['success' => false, 'error' => 'Client not found.'];
        }

        if ($reason === '') {
            return ['success' => false, 'error' => 'A reason is required.'];
        }

        $items = array_values(array_filter(
            $items,
            static fn (array $item) => trim($item['description']) !== '' && $item['amount'] > 0
        ));

        if ($items === []) {
            return ['success' => false, 'error' => 'At least one line item with a positive amount is required.'];
        }

        $total = round(array_sum(array_column($items, 'amount')), 2);
        $lock = $this->currency->lockedColumnsFor($client);

        $id = $this->db->transaction(function () use ($clientId, $invoiceId, $reason, $total, $lock, $adminId, $items) {
            $creditNoteId = $this->creditNotes->create(
                $clientId,
                $invoiceId,
                $reason,
                $total,
                $lock['currency_id'],
                $lock['currency_rate'],
                $adminId,
                $items
            );

            $this->ledger->add($clientId, $total, "Credit note #{$creditNoteId}: {$reason}", $invoiceId, $adminId, $creditNoteId);

            return $creditNoteId;
        });

        return ['success' => true, 'id' => $id];
    }
}
