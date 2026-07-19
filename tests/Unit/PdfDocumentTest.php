<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Pdf\PdfDocument;
use PHPUnit\Framework\TestCase;

final class PdfDocumentTest extends TestCase
{
    public function test_render_produces_a_well_formed_pdf_header_and_trailer(): void
    {
        $pdf = new PdfDocument();
        $pdf->text(50, 800, 'Hello');

        $bytes = $pdf->render();

        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertStringEndsWith('%%EOF', $bytes);
        $this->assertStringContainsString('/Type /Catalog', $bytes);
        $this->assertStringContainsString('/Type /Page', $bytes);
        $this->assertStringContainsString('(Hello) Tj', $bytes);
    }

    public function test_render_with_no_content_still_produces_valid_structure(): void
    {
        $bytes = (new PdfDocument())->render();

        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertStringContainsString('xref', $bytes);
        $this->assertStringContainsString('trailer', $bytes);
    }

    public function test_text_escapes_parentheses_and_backslashes(): void
    {
        $pdf = new PdfDocument();
        $pdf->text(0, 0, 'A (test) with \\backslash\\');

        $bytes = $pdf->render();

        $this->assertStringContainsString('A \\(test\\) with \\\\backslash\\\\', $bytes);
    }

    public function test_text_transliterates_non_latin1_characters_rather_than_emitting_raw_utf8(): void
    {
        // Standard-14 fonts (Helvetica) only cover WinAnsiEncoding — a raw
        // UTF-8 multi-byte sequence in the content stream would render as
        // garbage in any real PDF reader, so this must never appear as-is.
        $pdf = new PdfDocument();
        $pdf->text(0, 0, 'Café');

        $bytes = $pdf->render();

        $this->assertStringNotContainsString("Caf\xC3\xA9", $bytes);
        $this->assertStringContainsString('Caf', $bytes);
    }

    public function test_stream_length_matches_the_declared_length(): void
    {
        $pdf = new PdfDocument();
        $pdf->text(50, 800, 'Line one');
        $pdf->line(50, 790, 500, 790);

        $bytes = $pdf->render();

        preg_match('/\/Length (\d+)/', $bytes, $lengthMatch);
        preg_match('/stream\n(.*)\nendstream/s', $bytes, $streamMatch);

        $this->assertNotEmpty($lengthMatch);
        $this->assertNotEmpty($streamMatch);
        $this->assertSame((int) $lengthMatch[1], strlen($streamMatch[1]));
    }

    public function test_xref_offsets_point_at_the_correct_object_start(): void
    {
        $pdf = new PdfDocument();
        $pdf->text(50, 800, 'Offsets test');
        $bytes = $pdf->render();

        preg_match('/xref\n0 \d+\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)/', $bytes, $xrefMatch);
        $this->assertNotEmpty($xrefMatch, 'xref table not found');

        $offsets = array_map('intval', array_map('trim', explode("\n", trim($xrefMatch[1]))));
        // Every offset must land exactly on "N 0 obj" for object N (1-indexed).
        foreach ($offsets as $index => $offset) {
            $objectNumber = $index + 1;
            $this->assertSame("{$objectNumber} 0 obj", substr($bytes, $offset, strlen("{$objectNumber} 0 obj")));
        }
    }
}
