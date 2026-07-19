<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Database\Migrator;
use CodeVault\Pdf\CreditNotePdfBuilder;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Validated against poppler's pdftotext (an independent real-world PDF
 * parser), same technique used for the invoice PDF in R11 — not just "the
 * code ran without throwing."
 */
final class CreditNotePdfBuilderTest extends DatabaseTestCase
{
    private CreditNotePdfBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->builder = new CreditNotePdfBuilder(new CurrencyService(new CurrencyRepository($this->db)));
    }

    public function test_build_produces_a_valid_pdf_containing_the_expected_text(): void
    {
        $creditNote = [
            'id' => 42,
            'invoice_id' => 7,
            'reason' => 'Service cancellation refund',
            'total' => 25.49,
            'currency_id' => null,
            'currency_rate' => 1.0,
            'created_at' => '2026-01-15 10:00:00',
        ];
        $items = [
            ['description' => 'Unused hosting time', 'amount' => 15.50],
            ['description' => 'Setup fee refund', 'amount' => 9.99],
        ];
        $client = ['first_name' => 'Nora', 'last_name' => 'Client', 'email' => 'nora@example.test', 'company_name' => null];

        $bytes = $this->builder->build($creditNote, $items, $client);

        $this->assertStringStartsWith('%PDF', $bytes);

        if (!$this->pdftotextAvailable()) {
            $this->markTestSkipped('pdftotext (poppler) not available in this environment.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'cv_credit_note_') . '.pdf';
        file_put_contents($tmpFile, $bytes);

        $text = shell_exec('pdftotext ' . escapeshellarg($tmpFile) . ' - 2>&1');
        unlink($tmpFile);

        $this->assertStringContainsString('CREDIT NOTE', $text);
        $this->assertStringContainsString('CN-42', $text);
        $this->assertStringContainsString('INV-7', $text);
        $this->assertStringContainsString('Service cancellation refund', $text);
        $this->assertStringContainsString('Unused hosting time', $text);
        $this->assertStringContainsString('Nora Client', $text);
        $this->assertStringContainsString('25.49', $text);
    }

    private function pdftotextAvailable(): bool
    {
        $path = shell_exec('which pdftotext 2>/dev/null') ?: shell_exec('where pdftotext 2>NUL');

        return $path !== null && trim((string) $path) !== '';
    }
}
