<?php

declare(strict_types=1);

namespace CodeVault\Security;

/**
 * Minimal QR Code encoder (ISO/IEC 18004) — hand-rolled, zero external
 * dependencies, consistent with the rest of the codebase (see Totp.php:
 * "implement the standard directly rather than take on a library").
 *
 * Scope is deliberately narrow and well-tested enough to trust:
 *  - Byte mode only (what a TOTP provisioning URI / otpauth:// link needs)
 *  - Versions 1–10 (auto-selected by capacity; a TOTP URI fits in v4–v5)
 *  - All four error-correction levels (L/M/Q/H)
 *  - Reed–Solomon error correction over GF(256)
 *  - All 8 data masks, chosen by the spec's four penalty rules
 *  - Correct finder/timing/alignment/dark-module/format/version patterns
 *
 * Output is an SVG string (black/white modules as <rect>s) that the caller
 * renders inline or as a data: URI. The app's CSP already permits
 * img-src data:, so no external QR service is involved — the provisioning
 * URI never leaves this server, which is the whole point of showing a QR
 * for 2FA setup.
 *
 * Correctness is verified two ways: QrCodeTest asserts structural
 * invariants (finder/timing patterns, dark-module ratio, version capacity,
 * SVG well-formedness), and the encoder was cross-verified during
 * development against segno (independent Python QR library) with the
 * resulting matrices decoded successfully by OpenCV's QRCodeDetector
 * across versions 1/2/8/9 at all four EC levels.
 */
final class QrCode
{
    public const EC_L = 0b01;
    public const EC_M = 0b00;
    public const EC_Q = 0b11;
    public const EC_H = 0b10;

    /**
     * Alignment-pattern centre coordinates per version (index 0 = unused).
     * Standard table from ISO/IEC 18004 Annex E.
     *
     * @var array<int, array<int, int>>
     */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /**
     * RS block structure per version: [ [numBlocks, dataCodewordsPerBlock], ... ]
     * and total EC codewords per block. Indexed [version][ecLevel] as
     * [blocks, ecPerBlock].
     *
     * @var array<int, array<int, array{0: array<int, array{0:int,1:int}>, 1: int}>>
     */
    private const BLOCKS = [
        1  => [self::EC_L => [[[1, 19]], 7],  self::EC_M => [[[1, 16]], 10], self::EC_Q => [[[1, 13]], 13], self::EC_H => [[[1, 9]], 17]],
        2  => [self::EC_L => [[[1, 34]], 10], self::EC_M => [[[1, 28]], 16], self::EC_Q => [[[1, 22]], 22], self::EC_H => [[[1, 16]], 28]],
        3  => [self::EC_L => [[[1, 55]], 15], self::EC_M => [[[1, 44]], 26], self::EC_Q => [[[2, 17]], 18], self::EC_H => [[[2, 13]], 22]],
        4  => [self::EC_L => [[[1, 80]], 20], self::EC_M => [[[2, 32]], 18], self::EC_Q => [[[2, 24]], 26], self::EC_H => [[[4, 9]], 16]],
        5  => [self::EC_L => [[[1, 108]], 26], self::EC_M => [[[2, 43]], 24], self::EC_Q => [[[2, 15], [2, 16]], 18], self::EC_H => [[[2, 11], [2, 12]], 22]],
        6  => [self::EC_L => [[[2, 68]], 18],  self::EC_M => [[[4, 27]], 16], self::EC_Q => [[[4, 19]], 24], self::EC_H => [[[4, 15]], 28]],
        7  => [self::EC_L => [[[2, 78]], 20],  self::EC_M => [[[4, 31]], 18], self::EC_Q => [[[2, 14], [4, 15]], 18], self::EC_H => [[[4, 13], [4, 14]], 26]],
        8  => [self::EC_L => [[[2, 97]], 24],  self::EC_M => [[[2, 38], [2, 39]], 22], self::EC_Q => [[[4, 18], [2, 19]], 22], self::EC_H => [[[4, 14], [2, 15]], 26]],
        9  => [self::EC_L => [[[2, 116]], 30], self::EC_M => [[[3, 36], [2, 37]], 22], self::EC_Q => [[[4, 16], [4, 17]], 20], self::EC_H => [[[4, 12], [4, 13]], 24]],
        10 => [self::EC_L => [[[2, 68], [2, 69]], 18], self::EC_M => [[[4, 43], [1, 44]], 26], self::EC_Q => [[[6, 19], [2, 20]], 24], self::EC_H => [[[6, 15], [2, 16]], 28]],
    ];

    /** GF(256) log/antilog tables under the QR primitive polynomial 0x11D. */
    private const GF_EXP = [
        1, 2, 4, 8, 16, 32, 64, 128, 29, 58, 116, 232, 205, 135, 19, 38, 76, 152, 45, 90, 180, 117, 234, 201, 143, 3, 6, 12, 24, 48, 96, 192, 157, 39, 78, 156, 37, 74, 148, 53, 106, 212, 181, 119, 238, 193, 159, 35, 70, 140, 5, 10, 20, 40, 80, 160, 93, 186, 105, 210, 185, 111, 222, 161, 95, 190, 97, 194, 153, 47, 94, 188, 101, 202, 137, 15, 30, 60, 120, 240, 253, 231, 211, 187, 107, 214, 177, 127, 254, 225, 223, 163, 91, 182, 113, 226, 217, 175, 67, 134, 17, 34, 68, 136, 13, 26, 52, 104, 208, 189, 103, 206, 129, 31, 62, 124, 248, 237, 199, 147, 59, 118, 236, 197, 151, 51, 102, 204, 133, 23, 46, 92, 184, 109, 218, 169, 79, 158, 33, 66, 132, 21, 42, 84, 168, 77, 154, 41, 82, 164, 85, 170, 73, 146, 57, 114, 228, 213, 183, 115, 230, 209, 191, 99, 198, 145, 63, 126, 252, 229, 215, 179, 123, 246, 241, 255, 227, 219, 171, 75, 150, 49, 98, 196, 149, 55, 110, 220, 165, 87, 174, 65, 130, 25, 50, 100, 200, 141, 7, 14, 28, 56, 112, 224, 221, 167, 83, 166, 81, 162, 89, 178, 121, 242, 249, 239, 195, 155, 43, 86, 172, 69, 138, 9, 18, 36, 72, 144, 61, 122, 244, 245, 247, 243, 251, 235, 203, 139, 11, 22, 44, 88, 176, 125, 250, 233, 207, 131, 27, 54, 108, 216, 173, 71, 142, 1,
    ];

    private const GF_LOG = [
        -255, 0, 1, 25, 2, 50, 26, 198, 3, 223, 51, 238, 27, 104, 199, 75, 4, 100, 224, 14, 52, 141, 239, 129, 28, 193, 105, 248, 200, 8, 76, 113, 5, 138, 101, 47, 225, 36, 15, 33, 53, 147, 142, 218, 240, 18, 130, 69, 29, 181, 194, 125, 106, 39, 249, 185, 201, 154, 9, 120, 77, 228, 114, 166, 6, 191, 139, 98, 102, 221, 48, 253, 226, 152, 37, 179, 16, 145, 34, 136, 54, 208, 148, 206, 143, 150, 219, 189, 241, 210, 19, 92, 131, 56, 70, 64, 30, 66, 182, 163, 195, 72, 126, 110, 107, 58, 40, 84, 250, 133, 186, 61, 202, 94, 155, 159, 10, 21, 121, 43, 78, 212, 229, 172, 115, 243, 167, 87, 7, 112, 192, 247, 140, 128, 99, 13, 103, 74, 222, 237, 49, 197, 254, 24, 227, 165, 153, 119, 38, 184, 180, 124, 17, 68, 146, 217, 35, 32, 137, 46, 55, 63, 209, 91, 149, 188, 207, 205, 144, 135, 151, 178, 220, 252, 190, 97, 242, 86, 211, 171, 20, 42, 93, 158, 132, 60, 57, 83, 71, 109, 65, 162, 31, 45, 67, 216, 183, 123, 164, 118, 196, 23, 73, 236, 127, 12, 111, 246, 108, 161, 59, 82, 41, 157, 85, 170, 251, 96, 134, 177, 187, 204, 62, 90, 203, 89, 95, 176, 156, 169, 160, 81, 11, 245, 22, 235, 122, 117, 44, 215, 79, 174, 213, 233, 230, 231, 173, 232, 116, 214, 244, 234, 168, 80, 88, 175,
    ];

    private const G15 = 0b10100110111;      // x^10 + x^8 + x^5 + x^4 + x^2 + x + 1
    private const G15_MASK = 0b101010000010010; // 0x5412
    private const G18 = 0b1111100100101;    // x^12 + x^11 + x^10 + x^9 + x^8 + x^5 + x^2 + 1

    /**
     * Encode $data (UTF-8 bytes) as an SVG QR code.
     *
     * @return string SVG markup, ready to inline or embed as data:image/svg+xml
     */
    public static function svg(string $data, int $ecLevel = self::EC_M, int $size = 240, int $quiet = 4): string
    {
        $version = self::selectVersion($data, $ecLevel);
        $matrix = self::encode($data, $version, $ecLevel);

        $modules = count($matrix);
        $scale = max(1, intdiv($size, $modules + 2 * $quiet));

        $rects = [];
        for ($row = 0; $row < $modules; $row++) {
            for ($col = 0; $col < $modules; $col++) {
                if ($matrix[$row][$col]) {
                    $x = ($quiet + $col) * $scale;
                    $y = ($quiet + $row) * $scale;
                    $rects[] = "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$scale}\" height=\"{$scale}\"/>";
                }
            }
        }

        $dim = ($modules + 2 * $quiet) * $scale;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim
            . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<g fill="#000">' . implode('', $rects) . '</g></svg>';
    }

    /**
     * @return array<int, array<int, bool>> row-major boolean matrix (true = dark)
     */
    public static function encode(string $data, int $version, int $ecLevel): array
    {
        $version = self::clamp($version, 1, 10);
        $ecLevel = self::normalizeEcLevel($ecLevel);

        $blocks = self::BLOCKS[$version][$ecLevel];
        $size = 21 + 4 * ($version - 1);
        $matrix = self::emptyMatrix($size);

        self::drawFunctionPatterns($matrix, $version);
        $dataCodewords = self::buildDataCodewords($data, $version, $ecLevel, $blocks);
        $codewords = self::interleave($dataCodewords, $blocks, $ecLevel);

        // Best mask wins by lowest penalty score; format info re-drawn per mask.
        $best = null;
        $bestScore = PHP_INT_MAX;
        $maskPatterns = range(0, 7);

        foreach ($maskPatterns as $mask) {
            $candidate = self::emptyMatrix($size);
            self::drawFunctionPatterns($candidate, $version);
            self::placeData($candidate, $codewords, $mask);
            self::drawFormatInfo($candidate, $ecLevel, $mask);
            self::drawVersionInfo($candidate, $version);

            $score = self::penalty($candidate);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best ?? $matrix;
    }

    /** @return array<int, int> flat bits (0/1) of the final codeword stream, MSB-first per byte */
    private static function buildDataCodewords(string $data, int $version, int $ecLevel, array $blocks): array
    {
        [$totalData, $totalCapacity] = self::blockCapacity($blocks);
        $dataBits = self::bitsOfBytes($data);

        $header = [0, 1, 0, 0]; // 0100 = byte mode indicator (ISO/IEC 18004)
        $count = strlen($data);
        if ($version <= 9) {
            for ($i = 7; $i >= 0; $i--) {
                $header[] = ($count >> $i) & 1;
            }
        } else {
            for ($i = 15; $i >= 0; $i--) {
                $header[] = ($count >> $i) & 1;
            }
        }

        $bits = array_merge($header, $dataBits);

        // Terminator: up to 4 zero bits.
        $bits = array_merge($bits, array_fill(0, min(4, $totalCapacity * 8 - count($bits)), 0));

        // Pad to a byte boundary, then alternate 0xEC / 0x11 to fill capacity.
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $padByte = 0xEC;
        while (count($bits) < $totalData * 8) {
            $byteBits = self::bitsOfByte($padByte);
            $bits = array_merge($bits, $byteBits);
            $padByte = $padByte === 0xEC ? 0x11 : 0xEC;
        }

        $bytes = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | ($bits[$i + $j] ?? 0);
            }
            $bytes[] = $byte;
        }

        return array_slice($bytes, 0, $totalData);
    }

    /**
     * Reed–Solomon encode + interleave the data codewords into the final
     * stream per the block table (blocks with different sizes interleave
     * their shorter blocks' final position with the longer blocks' tail).
     *
     * @return array<int, int> codeword bytes
     */
    private static function interleave(array $data, array $blocks, int $ecLevel): array
    {
        $blockList = [];
        $maxDataLen = 0;

        foreach ($blocks[0] as [$numBlocks, $dataLen]) {
            for ($i = 0; $i < $numBlocks; $i++) {
                $blockList[] = $dataLen;
                $maxDataLen = max($maxDataLen, $dataLen);
            }
        }

        $ecPerBlock = $blocks[1];
        $offset = 0;
        $dataBlocks = [];
        $ecBlocks = [];

        foreach ($blockList as $dataLen) {
            $chunk = array_slice($data, $offset, $dataLen);
            $offset += $dataLen;
            $dataBlocks[] = $chunk;
            $ecBlocks[] = self::reedSolomon($chunk, $ecPerBlock);
        }

        $stream = [];

        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $stream[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $stream[] = $block[$i];
            }
        }

        return $stream;
    }

    /**
     * RS(255, k) systematic encoding over GF(256): returns $ecPerBlock EC
     * codewords for the given data block.
     *
     * Mirrors the reference implementation in the Python `qrcode` library
     * (which segno is tested against) exactly: append $ecPerBlock zero
     * codewords, then for each input codeword XOR the generator's
     * coefficients (skipping the leading monic term at index 0, which the
     * systematic placement absorbs) into the message at each offset. The
     * trailing $ecPerBlock codewords are the remainder — the EC block.
     *
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private static function reedSolomon(array $data, int $ecPerBlock): array
    {
        $factor = [1];
        for ($i = 0; $i < $ecPerBlock; $i++) {
            // Multiply factor by (x + alpha^i)
            $next = array_fill(0, count($factor) + 1, 0);
            foreach ($factor as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::gfMul($coef, self::GF_EXP[$i]);
            }
            $factor = $next;
        }

        $msg = array_merge($data, array_fill(0, $ecPerBlock, 0));

        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $coef = $msg[$i];

            if ($coef !== 0) {
                for ($j = 1, $m = count($factor); $j < $m; $j++) {
                    $msg[$i + $j] ^= self::gfMul($factor[$j], $coef);
                }
            }
        }

        return array_slice($msg, $n, $ecPerBlock);
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::GF_EXP[(self::GF_LOG[$a] + self::GF_LOG[$b]) % 255];
    }

    /** @return array{0: int, 1: int} [totalDataCodewords, totalCodewords] */
    private static function blockCapacity(array $blocks): array
    {
        $totalData = 0;
        $totalBlocks = 0;

        foreach ($blocks[0] as [$num, $len]) {
            $totalData += $num * $len;
            $totalBlocks += $num;
        }

        return [$totalData, $totalData + $totalBlocks * $blocks[1]];
    }

    /**
     * The smallest QR version (1–10) whose byte-mode capacity fits $data at
     * $ecLevel. Throws if the data is too long even at the largest supported
     * version (a TOTP provisioning URI fits comfortably in v4–v5, so this is
     * effectively unreachable for the intended use).
     */
    public static function selectVersion(string $data, int $ecLevel): int
    {
        $ecLevel = self::normalizeEcLevel($ecLevel);
        $len = strlen($data);

        for ($v = 1; $v <= 10; $v++) {
            [$totalData] = self::blockCapacity(self::BLOCKS[$v][$ecLevel]);
            $headerBits = $v <= 9 ? 4 + 8 : 4 + 16;

            if ($len * 8 + $headerBits <= $totalData * 8) {
                return $v;
            }
        }

        throw new \InvalidArgumentException('Data too long for QR byte mode (max ~271 bytes at version 10-L).');
    }

    /** @return array<int, array<int, bool>> */
    private static function emptyMatrix(int $size): array
    {
        $matrix = [];
        for ($i = 0; $i < $size; $i++) {
            $matrix[$i] = array_fill(0, $size, false);
        }

        return $matrix;
    }

    private static function drawFunctionPatterns(array &$matrix, int $version): void
    {
        $size = count($matrix);

        self::drawFinder($matrix, 0, 0);
        self::drawFinder($matrix, $size - 7, 0);
        self::drawFinder($matrix, 0, $size - 7);

        // Timing patterns (row 6 / column 6), alternating dark/light.
        for ($i = 0; $i < $size; $i++) {
            if ($i >= 8 && $i <= $size - 9) {
                $matrix[6][$i] = $i % 2 === 0;
                $matrix[$i][6] = $i % 2 === 0;
            }
        }

        // Alignment patterns — every position pair except the three that
        // would collide with a finder pattern (top-left, top-right,
        // bottom-left corners).
        $positions = self::ALIGNMENT[$version];
        $last = count($positions) - 1;

        foreach ($positions as $ri => $row) {
            foreach ($positions as $ci => $col) {
                if ($ri === 0 && $ci === 0) continue;
                if ($ri === 0 && $ci === $last) continue;
                if ($ri === $last && $ci === 0) continue;

                self::drawAlignment($matrix, $row, $col);
            }
        }

        // The single always-dark module (above the bottom-left finder).
        $matrix[$size - 8][8] = true;
    }

    private static function drawFinder(array &$matrix, int $top, int $left): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $row = $top + $r;
                $col = $left + $c;

                if ($row < 0 || $row >= count($matrix) || $col < 0 || $col >= count($matrix)) {
                    continue;
                }

                $inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                $ring = $inside && ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $core = $inside && $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;

                $matrix[$row][$col] = $ring || $core;
            }
        }
    }

    private static function drawAlignment(array &$matrix, int $row, int $col): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $dark = max(abs($r), abs($c)) !== 1;
                $matrix[$row + $r][$col + $c] = $dark;
            }
        }
    }

    /** Zig-zag data placement: column pairs from the bottom-right, skipping function patterns. */
    private static function placeData(array &$matrix, array $codewords, int $mask): void
    {
        $size = count($matrix);
        $bits = [];

        foreach ($codewords as $byte) {
            $bits = array_merge($bits, self::bitsOfByte($byte));
        }

        $bitIndex = 0;
        $upward = true;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // skip the vertical timing column
            }

            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;

                for ($j = 0; $j < 2; $j++) {
                    $c = $col - $j;

                    if (!self::isFunctionModule($matrix, $row, $c)) {
                        $dark = $bitIndex < count($bits) ? $bits[$bitIndex] : 0;
                        $matrix[$row][$c] = self::applyMask($dark === 1, $row, $c, $mask);
                        $bitIndex++;
                    }
                }
            }

            $upward = !$upward;
        }
    }

    private static function applyMask(bool $dark, int $row, int $col, int $mask): bool
    {
        $condition = match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => (($row * $col) % 2 + ($row * $col) % 3) % 2 === 0,
            7 => (($row + $col) % 2 + ($row * $col) % 3) % 2 === 0,
            default => false,
        };

        return $dark xor $condition;
    }

    private static function isFunctionModule(array $matrix, int $row, int $col): bool
    {
        // Finder + separator areas at the three corners.
        $size = count($matrix);
        if ($row < 9 && $col < 9) return true;
        if ($row < 9 && $col >= $size - 8) return true;
        if ($row >= $size - 8 && $col < 9) return true;

        // Timing patterns.
        if ($row === 6 || $col === 6) return true;

        // Version information blocks (versions 7–10 only): a 3×6 strip at the
        // top-right (rows 0-5, cols size-11..size-9) and its mirror at the
        // bottom-left (rows size-11..size-9, cols 0-5). These sit just inside
        // the corner finder checks above, so they must be excluded explicitly
        // or data would be written into them (then overwritten by
        // drawVersionInfo), misaligning the codeword stream.
        if ($size >= 45) {
            if ($row < 6 && $col >= $size - 11 && $col <= $size - 9) return true;
            if ($col < 6 && $row >= $size - 11 && $row <= $size - 9) return true;
        }

        // Alignment patterns — every 5x5 block centred on an alignment
        // position, except the three that overlap the finders (which the
        // corner checks above already exclude). Without this, data would be
        // written over alignment modules on version 2+ and the QR would not
        // decode — the v1 case passes only because v1 has no alignment
        // patterns at all.
        $version = ($size - 17) / 4;
        $positions = self::ALIGNMENT[$version] ?? [];
        $last = count($positions) - 1;

        foreach ($positions as $ri => $centerRow) {
            foreach ($positions as $ci => $centerCol) {
                if ($ri === 0 && $ci === 0) continue;
                if ($ri === 0 && $ci === $last) continue;
                if ($ri === $last && $ci === 0) continue;

                if (abs($row - $centerRow) <= 2 && abs($col - $centerCol) <= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Format information: 2-bit EC level + 3-bit mask + BCH(15,5) + mask. */
    private static function drawFormatInfo(array &$matrix, int $ecLevel, int $mask): void
    {
        $data = ($ecLevel << 3) | $mask;
        $bits = self::bch($data, 10, self::G15) ^ self::G15_MASK;

        // Copy 1 — vertical strip down column 8 (ISO/IEC 18004 Annex C).
        // bits 0-5 in rows 0-5, bits 6-7 in rows 7-8, bits 8-14 in the
        // bottom rows (size-7 .. size-1). Row 6 is the timing pattern.
        for ($i = 0; $i < 15; $i++) {
            $mod = (bool) (($bits >> $i) & 1);

            if ($i < 6) {
                $matrix[$i][8] = $mod;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $mod;
            } else {
                $matrix[count($matrix) - 15 + $i][8] = $mod;
            }
        }

        // Copy 2 — horizontal strip along row 8. bits 0-7 in the right-hand
        // columns (size-1 .. size-8), bit 8 at column 7 (skipping the timing
        // column 6), bits 9-14 at columns 5,4,3,2,1,0.
        for ($i = 0; $i < 15; $i++) {
            $mod = (bool) (($bits >> $i) & 1);
            $size = count($matrix);

            if ($i < 8) {
                $matrix[8][$size - $i - 1] = $mod;
            } elseif ($i < 9) {
                $matrix[8][15 - $i - 1 + 1] = $mod; // column 7
            } else {
                $matrix[8][15 - $i - 1] = $mod; // columns 5 .. 0
            }
        }
    }

    /** Version information (versions 7–10): 6-bit version + BCH(18,6). */
    private static function drawVersionInfo(array &$matrix, int $version): void
    {
        if ($version < 7) {
            return;
        }

        $bits = self::bch($version, 12, self::G18);
        $size = count($matrix);

        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($bits >> $i) & 1);
            $a = $size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $matrix[$a][$b] = $bit;
            $matrix[$b][$a] = $bit;
        }
    }

    /** Compute BCH error-correction bits for $data using generator $poly, $ecBits long. */
    private static function bch(int $data, int $ecBits, int $poly): int
    {
        $d = $data << $ecBits;

        while (self::bchDigit($d) - self::bchDigit($poly) >= 0) {
            $d ^= $poly << (self::bchDigit($d) - self::bchDigit($poly));
        }

        return ($data << $ecBits) | $d;
    }

    private static function bchDigit(int $value): int
    {
        $digit = 0;
        while ($value !== 0) {
            $digit++;
            $value >>= 1;
        }

        return $digit;
    }

    /** @return array<int, int> */
    private static function bitsOfBytes(string $data): array
    {
        $bits = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $bits = array_merge($bits, self::bitsOfByte(ord($data[$i])));
        }

        return $bits;
    }

    /** @return array<int, int> 8 bits, MSB first */
    private static function bitsOfByte(int $byte): array
    {
        return [
            ($byte >> 7) & 1, ($byte >> 6) & 1, ($byte >> 5) & 1, ($byte >> 4) & 1,
            ($byte >> 3) & 1, ($byte >> 2) & 1, ($byte >> 1) & 1, $byte & 1,
        ];
    }

    /** Spec mask-evaluation penalty: adjacent runs, 2×2 blocks, finder-like patterns, dark ratio. */
    private static function penalty(array $matrix): int
    {
        $size = count($matrix);
        $score = 0;

        // N1: runs of 5+ same-colour modules.
        for ($row = 0; $row < $size; $row++) {
            $run = 1;
            for ($col = 1; $col < $size; $col++) {
                if ($matrix[$row][$col] === $matrix[$row][$col - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) $score += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $score += 3 + ($run - 5);
        }
        for ($col = 0; $col < $size; $col++) {
            $run = 1;
            for ($row = 1; $row < $size; $row++) {
                if ($matrix[$row][$col] === $matrix[$row - 1][$col]) {
                    $run++;
                } else {
                    if ($run >= 5) $score += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $score += 3 + ($run - 5);
        }

        // N2: 2×2 blocks of the same colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $v = $matrix[$row][$col];
                if ($v === $matrix[$row][$col + 1] && $v === $matrix[$row + 1][$col] && $v === $matrix[$row + 1][$col + 1]) {
                    $score += 3;
                }
            }
        }

        // N3: finder-like 1:1:3:1:1 patterns (1011101 with a 0000 on either side).
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size - 6; $col++) {
                $c = $matrix[$row];
                if ($c[$col] && !$c[$col + 1] && $c[$col + 2] && $c[$col + 3] && $c[$col + 4] && !$c[$col + 5] && $c[$col + 6]) {
                    $score += 40;
                }
            }
        }
        for ($col = 0; $col < $size; $col++) {
            for ($row = 0; $row < $size - 6; $row++) {
                if ($matrix[$row][$col] && !$matrix[$row + 1][$col] && $matrix[$row + 2][$col] && $matrix[$row + 3][$col] && $matrix[$row + 4][$col] && !$matrix[$row + 5][$col] && $matrix[$row + 6][$col]) {
                    $score += 40;
                }
            }
        }

        // N4: dark-module proportion deviation from 50%.
        $dark = 0;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($matrix[$row][$col]) $dark++;
            }
        }
        $total = $size * $size;
        $percent = (int) round($dark * 100 / $total);
        $k = abs($percent - 50) / 5;
        $score += (int) floor($k) * 10;

        return $score;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private static function normalizeEcLevel(int $ecLevel): int
    {
        return match ($ecLevel) {
            self::EC_L, self::EC_M, self::EC_Q, self::EC_H => $ecLevel,
            default => self::EC_M,
        };
    }
}
