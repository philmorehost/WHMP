<?php

declare(strict_types=1);

namespace CodeVault\Pdf;

/**
 * A minimal hand-rolled single-page PDF writer (blueprint §5 "PDF engine")
 * — consistent with the rest of the codebase, this has zero third-party
 * dependencies. It supports exactly what a billing document needs: plain
 * text runs (Helvetica/Helvetica-Bold, the standard 14 fonts every PDF
 * reader ships — no font embedding required) and straight lines for table
 * borders. Coordinates are PDF-native: origin at the bottom-left, y
 * increases upward.
 *
 * This is not a general-purpose PDF library (no images, no word-wrap, no
 * multi-page flow) — callers lay out fixed-position content themselves,
 * which is enough for a one-page invoice/quote/credit-note.
 */
final class PdfDocument
{
    /** @var array<int, string> raw content-stream operators */
    private array $ops = [];

    public function __construct(
        private readonly float $width = 595.28,
        private readonly float $height = 841.89
    ) {
    }

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $escaped = $this->escape($text);
        $this->ops[] = sprintf('BT /%s %s Tf %s %s Td (%s) Tj ET', $font, $this->num($size), $this->num($x), $this->num($y), $escaped);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5): void
    {
        $this->ops[] = sprintf('%s w %s %s m %s %s l S', $this->num($width), $this->num($x1), $this->num($y1), $this->num($x2), $this->num($y2));
    }

    public function render(): string
    {
        $stream = implode("\n", $this->ops);
        $streamLength = strlen($stream);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
                $this->num($this->width),
                $this->num($this->height)
            ),
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => "<< /Length {$streamLength} >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    /**
     * WinAnsiEncoding (the standard-14-font default) only covers Latin-1 —
     * transliterate anything outside it rather than emitting bytes the
     * reader would render as garbage, and escape PDF string delimiters.
     */
    private function escape(string $text): string
    {
        $latin1 = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        $text = $latin1 !== false ? $latin1 : preg_replace('/[^\x20-\x7E]/', '?', $text);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $text);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
