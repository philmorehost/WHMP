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

        // 1. Logo & Document Title
        $pdf->text(50, $y, 'CodeVault', 24, true);
        
        $statusStr = strtoupper((string) $invoice['status']);
        $pdf->text(400, $y, $statusStr, 20, true);
        $y -= 30;

        // 2. Metadata Columns
        $pdf->text(50, $y, "Proforma Invoice #INV-{$invoice['id']}", 12, true);
        $pdf->text(400, $y, "Due Date: {$invoice['due_date']}", 10, true);
        $y -= 16;
        $pdf->text(50, $y, "Issued: " . substr((string) $invoice['created_at'], 0, 10), 10);
        $y -= 35;

        // 3. Billing Addresses
        $pdf->text(50, $y, 'INVOICED TO', 9, true);
        $pdf->text(300, $y, 'PAY TO', 9, true);
        $y -= 16;

        $clientName = trim("{$client['first_name']} {$client['last_name']}");
        $pdf->text(50, $y, $clientName, 10, true);
        $pdf->text(300, $y, 'CodeVault Limited', 10, true);
        $y -= 14;

        $company = !empty($client['company_name']) ? (string) $client['company_name'] : '';
        $pdf->text(50, $y, $company ?: $client['email'], 10);
        $pdf->text(300, $y, 'Payments Dept.', 10);
        $y -= 14;

        if ($company) {
            $pdf->text(50, $y, $client['email'], 10);
            $y -= 14;
        }
        $y -= 20;

        // 4. Invoice Items Table Header
        $pdf->line(50, $y, 545, $y, 1.0);
        $y -= 15;
        $pdf->text(50, $y, 'Description', 10, true);
        $pdf->text(460, $y, 'Amount', 10, true);
        $y -= 6;
        $pdf->line(50, $y, 545, $y, 0.5);
        $y -= 18;

        // 5. Render Line Items with Word Wrapping
        foreach ($items as $item) {
            $description = (string) $item['description'];
            $lines = explode("\n", wordwrap($description, 65, "\n", true));
            
            foreach ($lines as $i => $line) {
                if ($y < 120) {
                    break; // simple layout safety
                }
                
                if ($i === 0) {
                    $pdf->text(50, $y, $line, 9);
                    $pdf->text(460, $y, $money((float) $item['amount']), 9);
                } else {
                    $pdf->text(65, $y, $line, 9); // indent wrapped lines
                }
                $y -= 14;
            }
            $y -= 4;
        }

        $y -= 4;
        $pdf->line(50, $y, 545, $y, 0.5);
        $y -= 16;

        // 6. Totals & Summaries
        $pdf->text(370, $y, 'Sub Total:', 10);
        $pdf->text(460, $y, $money((float) $invoice['subtotal']), 10);
        $y -= 14;

        if ((float) $invoice['discount_amount'] > 0) {
            $pdf->text(370, $y, 'Discount:', 10);
            $pdf->text(460, $y, '-' . $money((float) $invoice['discount_amount']), 10);
            $y -= 14;
        }

        if ((float) $invoice['tax_amount'] > 0) {
            $pdf->text(370, $y, 'Tax:', 10);
            $pdf->text(460, $y, $money((float) $invoice['tax_amount']), 10);
            $y -= 14;
        }

        $pdf->line(370, $y, 545, $y, 0.5);
        $y -= 16;

        $pdf->text(370, $y, 'Total Due:', 11, true);
        $pdf->text(460, $y, $money((float) $invoice['total']), 11, true);

        return $pdf->render();
    }
}
