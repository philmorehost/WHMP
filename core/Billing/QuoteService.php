<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;

/**
 * Draft-quote → client-decision → real-invoice lifecycle (blueprint §4.1
 * client-area "My Quotes", §4.3 admin Billing menu). total is always
 * computed from quote_items, never trusted as separately-submitted input,
 * same discipline CreditNoteService/InvoiceRepository already use elsewhere.
 *
 * accept() is the one place a quote becomes a real invoice — via
 * InvoiceRepository::createFromItems(), reusing the quote's own locked
 * currency so the invoice never re-prices relative to what the client saw
 * and accepted.
 */
final class QuoteService
{
    public function __construct(
        private readonly Database $db,
        private readonly QuoteRepository $quotes,
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly CurrencyService $currency,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * @param array<int, array{description: string, amount: float}> $items
     * @return array{success: bool, id?: int, error?: string}
     */
    public function create(int $clientId, string $subject, ?string $validUntil, array $items, ?int $adminId): array
    {
        $client = $this->clients->find($clientId);

        if ($client === null) {
            return ['success' => false, 'error' => 'Client not found.'];
        }

        $subject = trim($subject);

        if ($subject === '') {
            return ['success' => false, 'error' => 'A subject is required.'];
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

        $id = $this->quotes->create($clientId, $subject, $validUntil, $total, $lock['currency_id'], $lock['currency_rate'], $adminId, $items);

        return ['success' => true, 'id' => $id];
    }

    /** @return array{success: bool, error?: string} */
    public function send(int $quoteId): array
    {
        $quote = $this->quotes->find($quoteId);

        if ($quote === null || $quote['status'] !== 'draft') {
            return ['success' => false, 'error' => 'Only a draft quote can be sent.'];
        }

        $this->quotes->updateStatus($quoteId, 'sent');

        return ['success' => true];
    }

    /**
     * @return array{success: bool, invoiceId?: int, error?: string}
     */
    public function accept(int $quoteId, int $clientId): array
    {
        $quote = $this->quotes->find($quoteId);

        if ($quote === null || (int) $quote['client_id'] !== $clientId) {
            return ['success' => false, 'error' => 'Quote not found.'];
        }

        if ($quote['status'] !== 'sent') {
            return ['success' => false, 'error' => 'Only a sent quote can be accepted.'];
        }

        $items = $this->quotes->items($quoteId);

        $invoiceId = $this->db->transaction(function () use ($quoteId, $clientId, $items, $quote) {
            $invoiceId = $this->invoices->createFromItems(
                $clientId,
                array_map(static fn (array $item) => ['description' => (string) $item['description'], 'amount' => (float) $item['amount']], $items),
                $quote['currency_id'] !== null ? (int) $quote['currency_id'] : null,
                (float) $quote['currency_rate']
            );

            $this->quotes->markConverted($quoteId, $invoiceId);

            return $invoiceId;
        });

        $this->hooks->fire(HookPoints::QUOTE_ACCEPTED, ['quoteId' => $quoteId, 'clientId' => $clientId, 'invoiceId' => $invoiceId]);

        return ['success' => true, 'invoiceId' => $invoiceId];
    }

    /** @return array{success: bool, error?: string} */
    public function decline(int $quoteId, int $clientId): array
    {
        $quote = $this->quotes->find($quoteId);

        if ($quote === null || (int) $quote['client_id'] !== $clientId) {
            return ['success' => false, 'error' => 'Quote not found.'];
        }

        if ($quote['status'] !== 'sent') {
            return ['success' => false, 'error' => 'Only a sent quote can be declined.'];
        }

        $this->quotes->updateStatus($quoteId, 'declined');
        $this->hooks->fire(HookPoints::QUOTE_DECLINED, ['quoteId' => $quoteId, 'clientId' => $clientId]);

        return ['success' => true];
    }
}
