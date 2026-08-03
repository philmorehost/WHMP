<?php

declare(strict_types=1);

namespace CodeVault\Pdf;

use CodeVault\Billing\CurrencyService;

/**
 * Lays out a credit note onto a single-page PdfDocument — mirrors
 * InvoicePdfBuilder's exact shape (blueprint §4.3 Billing "Credit & Debit
 * Notes", R18). Amounts render at the note's own locked currency, same
 * reasoning as invoices: a historical document must never re-price when
 * exchange rates later change.
 */
final class CreditNotePdfBuilder
{
    public function __construct(
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * @param array<string, mixed> $creditNote
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $client
     */
    public function build(array $creditNote, array $items, array $client): string
    {
        $pdf = new PdfDocument();
        $rate = (float) $creditNote['currency_rate'];
        $currencyId = $creditNote['currency_id'] !== null ? (int) $creditNote['currency_id'] : null;

        // Same rule as InvoicePdfBuilder: with no locked currency, fall back to
        // the client's own rather than the system default. formatLocked() would
        // print the default symbol, so a naira credit note downloaded as a PDF
        // read as dollars — on a document the client keeps and may hand to an
        // accountant.
        $clientCurrency = $client === [] ? null : $this->currency->resolveForClient($client);
        $money = fn (float $amount): string => $this->currency->formatDocument($amount, $currencyId, $rate, $clientCurrency);

        $y = 800.0;

        $pdf->text(50, $y, 'CodeVault', 20, true);
        $pdf->text(370, $y, 'CREDIT NOTE', 20, true);
        $y -= 30;

        $pdf->text(50, $y, "Credit Note #CN-{$creditNote['id']}", 12, true);
        $y -= 16;

        if ($creditNote['invoice_id'] !== null) {
            $pdf->text(50, $y, "Relates to Invoice #INV-{$creditNote['invoice_id']}");
            $y -= 14;
        }

        $pdf->text(50, $y, 'Issued: ' . substr((string) $creditNote['created_at'], 0, 10));
        $y -= 30;

        $pdf->text(50, $y, 'Issued To:', 11, true);
        $y -= 14;
        $pdf->text(50, $y, trim("{$client['first_name']} {$client['last_name']}"));
        $y -= 14;
        $pdf->text(50, $y, (string) $client['email']);

        if (!empty($client['company_name'])) {
            $y -= 14;
            $pdf->text(50, $y, (string) $client['company_name']);
        }

        $y -= 30;

        $pdf->text(50, $y, 'Reason:', 11, true);
        $pdf->text(120, $y, (string) $creditNote['reason']);
        $y -= 30;

        $pdf->line(50, $y, 545, $y);
        $y -= 16;
        $pdf->text(50, $y, 'Description', 10, true);
        $pdf->text(480, $y, 'Amount', 10, true);
        $y -= 6;
        $pdf->line(50, $y, 545, $y);
        $y -= 16;

        foreach ($items as $item) {
            $pdf->text(50, $y, (string) $item['description']);
            $pdf->text(480, $y, $money((float) $item['amount']));
            $y -= 16;
        }

        $y -= 4;
        $pdf->line(50, $y, 545, $y);
        $y -= 18;

        $pdf->text(400, $y, 'Total Credit:', 11, true);
        $pdf->text(480, $y, $money((float) $creditNote['total']), 11, true);

        return $pdf->render();
    }
}
