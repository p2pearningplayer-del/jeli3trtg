<?php
/**
 * Lightweight, Zero-Dependency Pure PHP QR Code SVG Generator
 * Generates standards-compliant QR Code Model 2 vector SVGs for authenticator provisioning URIs.
 * 100% offline, zero network requests, zero third-party leakage.
 */

declare(strict_types=1);

namespace Security;

class QRCodeGenerator {

    /**
     * Generate inline standalone vector SVG for any text/URI.
     *
     * @param string $text The text/URI to encode (e.g. otpauth://totp/...)
     * @param int $size Width & height in pixels (default 240px)
     * @param string $fgColor Foreground color (default #ffffff for dark mode or #0f172a)
     * @param string $bgColor Background color (default #020617)
     * @return string Valid XML <svg>...</svg> element
     */
    public static function generateSvg(
        string $text,
        int $size = 240,
        string $fgColor = '#ffffff',
        string $bgColor = '#020617'
    ): string {
        $matrix = self::createMatrix($text);
        $count = count($matrix);
        $margin = 4;
        $totalModules = $count + ($margin * 2);

        $rects = [];
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $rects[] = sprintf('<rect x="%d" y="%d" width="1" height="1" fill="%s"/>', $x, $y, htmlspecialchars($fgColor));
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" style="background:%s;border-radius:16px;padding:8px;box-sizing:border-box;">' .
            '%s' .
            '</svg>',
            $totalModules,
            $totalModules,
            $size,
            $size,
            htmlspecialchars($bgColor),
            implode('', $rects)
        );
    }

    /**
     * Create QR matrix using standard QR Code encoding (Byte mode, Error Correction Level L/M).
     */
    private static function createMatrix(string $text): array {
        // Determine version based on payload length
        $len = strlen($text);
        if ($len <= 32) {
            $version = 2; // 25x25
        } elseif ($len <= 62) {
            $version = 3; // 29x29
        } elseif ($len <= 90) {
            $version = 4; // 33x33
        } elseif ($len <= 122) {
            $version = 5; // 37x37
        } elseif ($len <= 154) {
            $version = 6; // 41x41
        } else {
            $version = 7; // 45x45
        }

        $size = 17 + (4 * $version);
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns at 3 corners
        self::placeFinderPattern($matrix, $isFunction, 0, 0);
        self::placeFinderPattern($matrix, $isFunction, $size - 7, 0);
        self::placeFinderPattern($matrix, $isFunction, 0, $size - 7);

        // 2. Alignment patterns
        $alignmentPositions = self::getAlignmentPatternPositions($version);
        $posCount = count($alignmentPositions);
        for ($i = 0; $i < $posCount; $i++) {
            for ($j = 0; $j < $posCount; $j++) {
                $r = $alignmentPositions[$i];
                $c = $alignmentPositions[$j];
                // Skip if overlaps with finder patterns
                if (($r <= 7 && $c <= 7) || ($r <= 7 && $c >= $size - 8) || ($r >= $size - 8 && $c <= 7)) {
                    continue;
                }
                self::placeAlignmentPattern($matrix, $isFunction, $r, $c);
            }
        }

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0);
            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = $val;
                $isFunction[6][$i] = true;
            }
            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = $val;
                $isFunction[$i][6] = true;
            }
        }

        // 4. Reserve format information areas
        for ($i = 0; $i < 9; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }
        // Dark module
        $matrix[(4 * $version) + 9][8] = true;
        $isFunction[(4 * $version) + 9][8] = true;

        // 5. Encode data and error correction codewords
        $bitStream = self::encodeData($text, $version);

        // 6. Place data bits in matrix with standard Mask Pattern 0 ( (row + col) % 2 == 0 )
        $bitIdx = 0;
        $bitCount = strlen($bitStream);
        $col = $size - 1;

        while ($col > 0) {
            if ($col === 6) {
                $col--; // Skip vertical timing column
            }

            for ($rowStep = 0; $rowStep < $size; $rowStep++) {
                // Alternating upward / downward column traversal
                $row = (intdiv($col + 1, 2) % 2 === 1) ? ($size - 1 - $rowStep) : $rowStep;

                for ($c = 0; $c < 2; $c++) {
                    $currCol = $col - $c;
                    if (!$isFunction[$row][$currCol]) {
                        $bit = ($bitIdx < $bitCount) ? ($bitStream[$bitIdx++] === '1') : false;
                        // Apply Mask Pattern 0: invert if (row + col) % 2 == 0
                        $mask = (($row + $currCol) % 2 === 0);
                        $matrix[$row][$currCol] = $bit ^ $mask;
                    }
                }
            }
            $col -= 2;
        }

        // 7. Write Format Information (ECC Level L, Mask 0 = 0x77C4)
        $formatBits = '111011111000100'; // Precomputed BCH code for ECC L, Mask 0
        self::placeFormatInfo($matrix, $size, $formatBits);

        return $matrix;
    }

    private static function placeFinderPattern(array &$matrix, array &$isFunction, int $startRow, int $startCol): void {
        $size = count($matrix);
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $row = $startRow + $r;
                $col = $startCol + $c;
                if ($row >= 0 && $row < $size && $col >= 0 && $col < $size) {
                    $isFunction[$row][$col] = true;
                    if ($r === -1 || $r === 7 || $c === -1 || $c === 7) {
                        $matrix[$row][$col] = false; // Separator
                    } elseif ($r === 0 || $r === 6 || $c === 0 || $c === 6) {
                        $matrix[$row][$col] = true; // Outer square
                    } elseif ($r === 1 || $r === 5 || $c === 1 || $c === 5) {
                        $matrix[$row][$col] = false; // Inner ring
                    } else {
                        $matrix[$row][$col] = true; // Center solid 3x3
                    }
                }
            }
        }
    }

    private static function placeAlignmentPattern(array &$matrix, array &$isFunction, int $centerRow, int $centerCol): void {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $row = $centerRow + $r;
                $col = $centerCol + $c;
                $isFunction[$row][$col] = true;
                if (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0)) {
                    $matrix[$row][$col] = true;
                } else {
                    $matrix[$row][$col] = false;
                }
            }
        }
    }

    private static function getAlignmentPatternPositions(int $version): array {
        $table = [
            1 => [],
            2 => [6, 18],
            3 => [6, 22],
            4 => [6, 26],
            5 => [6, 30],
            6 => [6, 34],
            7 => [6, 22, 38]
        ];
        return $table[$version] ?? [];
    }

    private static function placeFormatInfo(array &$matrix, int $size, string $bits): void {
        // Top-left corner
        $coordsTopLeft = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8],
            [2, 8], [1, 8], [0, 8]
        ];
        // Split bottom-left and top-right
        $coordsSplit = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]
        ];

        for ($i = 0; $i < 15; $i++) {
            $val = ($bits[$i] === '1');
            $matrix[$coordsTopLeft[$i][0]][$coordsTopLeft[$i][1]] = $val;
            $matrix[$coordsSplit[$i][0]][$coordsSplit[$i][1]] = $val;
        }
    }

    private static function encodeData(string $text, int $version): string {
        // Mode indicator: 0100 for Byte mode
        $bits = '0100';

        // Character count indicator (8 bits for Version 1-9 byte mode)
        $len = strlen($text);
        $bits .= str_pad(decbin($len), 8, '0', STR_PAD_LEFT);

        // Data bytes (8 bits each)
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Capacity and codeword counts for Error Correction Level L
        $dataCodewordsCountTable = [
            1 => 19,
            2 => 34,
            3 => 55,
            4 => 80,
            5 => 108,
            6 => 136,
            7 => 156
        ];
        $totalDataCodewords = $dataCodewordsCountTable[$version] ?? 80;
        $totalDataBits = $totalDataCodewords * 8;

        // Terminator (up to 4 zero bits)
        $diff = $totalDataBits - strlen($bits);
        if ($diff > 0) {
            $bits .= str_repeat('0', min(4, $diff));
        }

        // Pad to 8-bit boundary
        $remainder = strlen($bits) % 8;
        if ($remainder !== 0) {
            $bits .= str_repeat('0', 8 - $remainder);
        }

        // Pad codewords (alternating 0xEC and 0x11)
        $padBytes = ['11101100', '00010001'];
        $padIdx = 0;
        while (strlen($bits) < $totalDataBits) {
            $bits .= $padBytes[$padIdx % 2];
            $padIdx++;
        }

        // Convert data bits to byte array
        $dataBytes = [];
        $chunks = str_split($bits, 8);
        foreach ($chunks as $chunk) {
            $dataBytes[] = bindec($chunk);
        }

        // Calculate Reed-Solomon Error Correction Codewords
        $ecCodewordsCountTable = [
            1 => 7,
            2 => 10,
            3 => 15,
            4 => 20,
            5 => 26,
            6 => 36,
            7 => 40
        ];
        $ecCount = $ecCodewordsCountTable[$version] ?? 20;
        $ecBytes = self::calculateReedSolomon($dataBytes, $ecCount);

        // Combine data and EC codewords into final bitstream
        $finalBitStream = '';
        foreach ($dataBytes as $b) {
            $finalBitStream .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        foreach ($ecBytes as $b) {
            $finalBitStream .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        return $finalBitStream;
    }

    /**
     * Reed-Solomon Error Correction encoder for QR codes (Galois Field GF(256), poly 0x11D).
     */
    private static function calculateReedSolomon(array $data, int $ecCount): array {
        // Precomputed GF(256) tables with generator 2
        static $gfExp = null;
        static $gfLog = null;

        if ($gfExp === null) {
            $gfExp = array_fill(0, 512, 0);
            $gfLog = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $gfExp[$i] = $x;
                $gfLog[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D; // Primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $gfExp[$i] = $gfExp[$i - 255];
            }
        }

        // Generate generator polynomial for $ecCount
        $genPoly = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $factor = [1, $gfExp[$i]];
            $newPoly = array_fill(0, count($genPoly) + 1, 0);
            for ($j = 0; $j < count($genPoly); $j++) {
                $newPoly[$j] ^= $genPoly[$j];
                $term = ($genPoly[$j] === 0 || $factor[1] === 0) ? 0 : $gfExp[$gfLog[$genPoly[$j]] + $gfLog[$factor[1]]];
                $newPoly[$j + 1] ^= $term;
            }
            $genPoly = $newPoly;
        }

        // Synthetic polynomial division
        $remainder = array_merge($data, array_fill(0, $ecCount, 0));
        $dataLen = count($data);

        for ($i = 0; $i < $dataLen; $i++) {
            $coef = $remainder[$i];
            if ($coef !== 0) {
                for ($j = 0; $j < count($genPoly); $j++) {
                    $term = ($genPoly[$j] === 0) ? 0 : $gfExp[$gfLog[$coef] + $gfLog[$genPoly[$j]]];
                    $remainder[$i + $j] ^= $term;
                }
            }
        }

        return array_slice($remainder, $dataLen);
    }
}
