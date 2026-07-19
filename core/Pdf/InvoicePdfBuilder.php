<?php

declare(strict_types=1);

namespace CodeVault\Pdf;

use CodeVault\Billing\CurrencyService;

/**
 * Lays out an invoice onto a single-page PdfDocument (blueprint §5 "My
 * Invoices +PDF"). Amounts render at the invoice's own locked currency
 * (blueprint §4.4 multi-currency), matching what the client sees on the
 * invoice's HTML view — never today's live rate.
 */
final class InvoicePdfBuilder
{
    public function __construct(
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $client
     */
    public function build(array $invoice, array $items, array $client): string
    {
        $pdf = new PdfDocument();
        $rate = (float) $invoice['currency_rate'];
        $currencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;
        $money = fn (float $amount): string => $this->currency->formatLocked($amount, $currencyId, $rate);

        $y = 800.0;

        $pdf->text(50, $y, 'CodeVault', 20, true);
        $pdf->text(400, $y, 'INVOICE', 20, true);
        $y -= 30;

        $pdf->text(50, $y, "Invoice #INV-{$invoice['id']}", 12, true);
        $y -= 16;
        $pdf->text(50, $y, 'Status: ' . ucfirst((string) $invoice['status']));
        $y -= 14;
        $pdf->text(50, $y, "Due Date: {$invoice['due_date']}");
        $y -= 14;
        $pdf->text(50, $y, "Issued: " . substr((string) $invoice['created_at'], 0, 10));
        $y -= 30;

        $pdf->text(50, $y, 'Bill To:', 11, true);
        $y -= 14;
        $pdf->text(50, $y, trim("{$client['first_name']} {$client['last_name']}"));
        $y -= 14;
        $pdf->text(50, $y, (string) $client['email']);

        if (!empty($client['company_name'])) {
            $y -= 14;
            $pdf->text(50, $y, (string) $client['company_name']);
        }

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

        $pdf->text(400, $y, 'Total:', 11, true);
        $pdf->text(480, $y, $money((float) $invoice['total']), 11, true);

        return $pdf->render();
    }
}
