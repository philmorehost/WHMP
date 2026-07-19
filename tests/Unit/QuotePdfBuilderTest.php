<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Database\Migrator;
use CodeVault\Pdf\QuotePdfBuilder;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Validated against poppler's pdftotext, same technique used for the R11
 * invoice PDF and R18 credit note PDF — not just "the code ran without
 * throwing."
 */
final class QuotePdfBuilderTest extends DatabaseTestCase
{
    private QuotePdfBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->builder = new QuotePdfBuilder(new CurrencyService(new CurrencyRepository($this->db)));
    }

    public function test_build_produces_a_valid_pdf_containing_the_expected_text(): void
    {
        $quote = [
            'id' => 42,
            'subject' => 'Website hosting + SSL bundle',
            'total' => 135.00,
            'currency_id' => null,
            'currency_rate' => 1.0,
            'valid_until' => '2027-01-01',
            'created_at' => '2026-01-15 10:00:00',
        ];
        $items = [
            ['description' => 'Hosting (annual)', 'amount' => 120.00],
            ['description' => 'SSL certificate', 'amount' => 15.00],
        ];
        $client = ['first_name' => 'Nora', 'last_name' => 'Client', 'email' => 'nora@example.test', 'company_name' => null];

        $bytes = $this->builder->build($quote, $items, $client);

        $this->assertStringStartsWith('%PDF', $bytes);

        if (!$this->pdftotextAvailable()) {
            $this->markTestSkipped('pdftotext (poppler) not available in this environment.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'cv_quote_') . '.pdf';
        file_put_contents($tmpFile, $bytes);

        $text = shell_exec('pdftotext ' . escapeshellarg($tmpFile) . ' - 2>&1');
        unlink($tmpFile);

        $this->assertStringContainsString('QUOTE', $text);
        $this->assertStringContainsString('Q-42', $text);
        $this->assertStringContainsString('Website hosting', $text);
        $this->assertStringContainsString('Hosting (annual)', $text);
        $this->assertStringContainsString('Nora Client', $text);
        $this->assertStringContainsString('135.00', $text);
    }

    private function pdftotextAvailable(): bool
    {
        $path = shell_exec('which pdftotext 2>/dev/null') ?: shell_exec('where pdftotext 2>NUL');

        return $path !== null && trim((string) $path) !== '';
    }
}
