<?php
/**
 * Modular Screenshot & Image Analysis Service
 * Cryptographic deduplication (SHA-256), Perceptual visual hashing (dHash),
 * image tampering/aspect anomaly heuristics, and pluggable OCR verification.
 */

declare(strict_types=1);

namespace Fraud;

use PDO;
use Exception;

interface OcrProviderInterface {
    public function extractTransactionData(string $filePath): array;
}

/**
 * Built-in Lightweight Pattern & Binary Stream OCR Provider
 * Zero external dependencies; safely extracts structured banking patterns from image streams.
 */
class LocalPatternOcrProvider implements OcrProviderInterface {
    public function extractTransactionData(string $filePath): array {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'provider' => 'local_pattern',
                'confidence' => 0.0,
                'extracted_amount' => null,
                'extracted_utr' => null,
                'extracted_date' => null,
                'extracted_status' => null,
                'raw_text' => '',
                'notes' => 'File not found'
            ];
        }

        $content = (string)file_get_contents($filePath, false, null, 0, 512 * 1024); // read up to 512KB
        $extractedAmount = null;
        $extractedUtr = null;
        $extractedStatus = null;
        $extractedDate = null;
        $confidence = 0.35;

        // 1. Scan for 12-digit UPI RRN / UTR pattern
        if (preg_match('/\b([0-9]{12})\b/', $content, $m)) {
            $extractedUtr = $m[1];
            $confidence += 0.20;
        }

        // 2. Scan for Amount patterns (₹, INR, Rs., Rs)
        if (preg_match('/(?:₹|INR|RS\.?)\s*([0-9]+(?:\.[0-9]{2})?)/i', $content, $m)) {
            $extractedAmount = (float)$m[1];
            $confidence += 0.20;
        }

        // 3. Scan for transaction status keywords
        if (preg_match('/(success|successful|completed|paid|transferred)/i', $content)) {
            $extractedStatus = 'SUCCESS';
            $confidence += 0.15;
        } elseif (preg_match('/(failed|declined|cancelled|reversed)/i', $content)) {
            $extractedStatus = 'FAILED';
            $confidence += 0.25;
        }

        // 4. Scan for Date formats (dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd)
        if (preg_match('/(\b\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}\b)/', $content, $m)) {
            $extractedDate = $m[1];
        }

        return [
            'success' => true,
            'provider' => 'local_pattern',
            'confidence' => min(0.90, $confidence),
            'extracted_amount' => $extractedAmount,
            'extracted_utr' => $extractedUtr,
            'extracted_date' => $extractedDate,
            'extracted_status' => $extractedStatus ?: 'UNKNOWN',
            'raw_text' => 'Local pattern scanned ' . strlen($content) . ' bytes',
            'notes' => 'Local fallback pattern parsing completed'
        ];
    }
}

/**
 * Optional Google Gemini Vision OCR Provider
 * Activated when GEMINI_API_KEY is configured in platform_settings or environment.
 */
class GeminiVisionOcrProvider implements OcrProviderInterface {
    private string $apiKey;

    public function __construct(string $apiKey) {
        $this->apiKey = trim($apiKey);
    }

    public function extractTransactionData(string $filePath): array {
        if (empty($this->apiKey) || !file_exists($filePath)) {
            $fallback = new LocalPatternOcrProvider();
            return $fallback->extractTransactionData($filePath);
        }

        $mimeType = mime_content_type($filePath) ?: 'image/jpeg';
        $imgData = base64_encode((string)file_get_contents($filePath));

        $prompt = "Extract payment transaction details from this UPI payment screenshot. Return strictly valid JSON with keys: " .
                  "\"amount\" (numeric float or null), \"utr\" (string 8-35 chars or null), \"status\" (\"SUCCESS\", \"FAILED\", \"PENDING\", or \"UNKNOWN\"), " .
                  "\"date\" (string or null), \"tampering_indicators\" (string or null), \"confidence\" (float 0.0-1.0).";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($this->apiKey);

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imgData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'response_mime_type' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, // 6s timeout to protect user response SLA
            CURLOPT_CONNECTTIMEOUT => 3
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $httpCode !== 200 || empty($res)) {
            // Graceful fallback to local provider on API failure or timeout
            $fallback = new LocalPatternOcrProvider();
            $res = $fallback->extractTransactionData($filePath);
            $res['notes'] = "Gemini Vision unavailable (HTTP {$httpCode}: {$err}). Fallback to local parsing.";
            return $res;
        }

        try {
            $json = json_decode($res, true);
            $rawText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $parsed = json_decode($rawText, true) ?: [];

            return [
                'success' => true,
                'provider' => 'gemini_vision',
                'confidence' => (float)($parsed['confidence'] ?? 0.85),
                'extracted_amount' => isset($parsed['amount']) ? (float)$parsed['amount'] : null,
                'extracted_utr' => isset($parsed['utr']) ? trim((string)$parsed['utr']) : null,
                'extracted_date' => $parsed['date'] ?? null,
                'extracted_status' => strtoupper((string)($parsed['status'] ?? 'UNKNOWN')),
                'tampering_indicators' => $parsed['tampering_indicators'] ?? null,
                'raw_text' => $rawText,
                'notes' => 'Gemini 1.5 Flash Vision OCR inspection completed'
            ];
        } catch (Exception $e) {
            $fallback = new LocalPatternOcrProvider();
            return $fallback->extractTransactionData($filePath);
        }
    }
}

/**
 * Main Image Analysis Service
 */
class ImageAnalysisService {

    /**
     * Convenience alias for analyzeFile
     */
    public static function analyzeImage(
        string $absolutePath,
        float $claimedAmount,
        string $claimedUtr,
        ?OcrProviderInterface $ocrProvider = null
    ): array {
        return self::analyzeFile($absolutePath, $claimedAmount, $claimedUtr, null, $ocrProvider);
    }

    /**
     * Analyze uploaded screenshot file for security, tampering, duplicates, and OCR details.
     *
     * @param string $absolutePath
     * @param float $claimedAmount
     * @param string $claimedUtr
     * @param string|null $claimedDate
     * @param OcrProviderInterface|null $ocrProvider
     * @return array
     */
    public static function analyzeFile(
        string $absolutePath,
        float $claimedAmount,
        string $claimedUtr,
        ?string $claimedDate = null,
        ?OcrProviderInterface $ocrProvider = null
    ): array {
        if (!file_exists($absolutePath)) {
            return [
                'success' => false,
                'error' => 'Screenshot file not found on server.',
                'is_valid_format' => false
            ];
        }

        $fileSize = filesize($absolutePath);
        $maxBytes = 25 * 1024 * 1024; // 25MB

        // 1. File size guard
        if ($fileSize > $maxBytes || $fileSize < 20) {
            return [
                'success' => false,
                'error' => 'File size invalid or corrupt (' . round($fileSize / 1024, 1) . ' KB).',
                'is_valid_format' => false
            ];
        }

        // 2. Format & MIME validation
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $absolutePath) ?: 'application/octet-stream';
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $isValidFormat = in_array($mime, $allowedMimes, true);

        if (!$isValidFormat) {
            return [
                'success' => false,
                'error' => "Unsupported file MIME type: {$mime}. Only JPG, PNG, and WebP screenshots are allowed.",
                'is_valid_format' => false,
                'mime' => $mime
            ];
        }

        // 3. Exact Cryptographic Hash (SHA-256)
        $sha256 = hash_file('sha256', $absolutePath);

        // 4. Perceptual Visual Hash (dHash)
        $phash = self::computePerceptualHash($absolutePath, $mime);

        // 5. Image Dimension & Aspect Ratio Analysis
        $imageDims = self::getImageDimensions($absolutePath);
        $aspectRatioAnomaly = false;
        $aspectRatio = 1.0;
        if ($imageDims['width'] > 0 && $imageDims['height'] > 0) {
            $aspectRatio = round($imageDims['height'] / $imageDims['width'], 2);
            // Mobile screenshots are typically vertical with aspect ratio between 1.40 and 2.40
            // Banner/square images or desktop horizontal screenshots have aspect ratio < 1.10
            if ($aspectRatio < 1.10 && $imageDims['width'] > 400) {
                $aspectRatioAnomaly = true;
            }
        }

        // 6. Metadata / EXIF Inspection (Detect editing tools e.g. Photoshop/PicsArt)
        $tamperingHints = self::detectEditingArtifacts($absolutePath);

        // 7. OCR Transaction Details Extraction
        if ($ocrProvider === null) {
            $ocrProvider = self::resolveDefaultOcrProvider();
        }
        $ocrResult = $ocrProvider->extractTransactionData($absolutePath);

        // 8. Cross-reference OCR findings against claimed order details
        $claimedNormUtr = UTRNormalizer::normalize($claimedUtr)['normalized'];
        $extractedNormUtr = !empty($ocrResult['extracted_utr']) ? UTRNormalizer::normalize($ocrResult['extracted_utr'])['normalized'] : null;

        $amountMatched = null;
        $amountDiff = 0.0;
        if ($ocrResult['extracted_amount'] !== null) {
            $amountDiff = abs((float)$ocrResult['extracted_amount'] - $claimedAmount);
            $amountMatched = ($amountDiff < 0.05); // Within 5 paise tolerance
        }

        $utrMatched = null;
        if ($extractedNormUtr !== null) {
            $utrMatched = ($extractedNormUtr === $claimedNormUtr);
        }

        $statusMatched = null;
        if (!empty($ocrResult['extracted_status'])) {
            $statusMatched = ($ocrResult['extracted_status'] === 'SUCCESS');
        }

        return [
            'success' => true,
            'is_valid_format' => true,
            'mime' => $mime,
            'file_size' => $fileSize,
            'sha256' => $sha256,
            'phash' => $phash,
            'dimensions' => $imageDims,
            'aspect_ratio' => $aspectRatio,
            'aspect_ratio_anomaly' => $aspectRatioAnomaly,
            'tampering_hints' => $tamperingHints,
            'ocr' => $ocrResult,
            'comparison' => [
                'claimed_amount' => $claimedAmount,
                'extracted_amount' => $ocrResult['extracted_amount'],
                'amount_matched' => $amountMatched,
                'amount_difference' => $amountDiff,
                'claimed_utr' => $claimedNormUtr,
                'extracted_utr' => $extractedNormUtr,
                'utr_matched' => $utrMatched,
                'extracted_status' => $ocrResult['extracted_status'],
                'status_matched' => $statusMatched
            ]
        ];
    }

    /**
     * Compute visual perceptual hash (dHash - 64-bit gradient hash)
     * Pure-PHP algorithm with GD support when present; graceful binary sampling fallback when GD is absent.
     */
    public static function computePerceptualHash(string $filePath, string $mime): string {
        if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
            try {
                $raw = @file_get_contents($filePath);
                if ($raw !== false) {
                    $img = @imagecreatefromstring($raw);
                    if ($img !== false) {
                        // Resize to 9x8 grayscale
                        $w = 9;
                        $h = 8;
                        $small = imagecreatetruecolor($w, $h);
                        imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));

                        $bits = '';
                        for ($y = 0; $y < $h; $y++) {
                            for ($x = 0; $x < 8; $x++) {
                                $rgbLeft = imagecolorat($small, $x, $y);
                                $rgbRight = imagecolorat($small, $x + 1, $y);

                                // Grayscale luminance
                                $lumLeft = (($rgbLeft >> 16) & 0xFF) * 0.299 + (($rgbLeft >> 8) & 0xFF) * 0.587 + ($rgbLeft & 0xFF) * 0.114;
                                $lumRight = (($rgbRight >> 16) & 0xFF) * 0.299 + (($rgbRight >> 8) & 0xFF) * 0.587 + ($rgbRight & 0xFF) * 0.114;

                                $bits .= ($lumLeft > $lumRight) ? '1' : '0';
                            }
                        }

                        imagedestroy($small);
                        imagedestroy($img);

                        // Convert 64 bits binary to 16 hex chars
                        $hex = '';
                        for ($i = 0; $i < 64; $i += 4) {
                            $nibble = substr($bits, $i, 4);
                            $hex .= dechex(bindec($nibble));
                        }
                        return strtolower($hex);
                    }
                }
            } catch (Exception $e) {
                // Fallback below
            }
        }

        return self::computePurePhpPerceptualHash($filePath);
    }

    /**
     * Pure-PHP fallback gradient entropy fingerprint (works across any PHP CLI/Web build)
     */
    public static function computePurePhpPerceptualHash(string $filePath): string {
        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            return substr(hash_file('sha256', $filePath), 0, 16);
        }

        $fileSize = filesize($filePath);
        $chunkSize = max(1, (int)floor($fileSize / 64));
        $bits = '';

        $prevAvg = 0;
        for ($i = 0; $i < 64; $i++) {
            fseek($handle, $i * $chunkSize);
            $bytes = fread($handle, min(128, $chunkSize));
            $sum = 0;
            $len = strlen($bytes);
            if ($len > 0) {
                for ($j = 0; $j < $len; $j++) {
                    $sum += ord($bytes[$j]);
                }
                $avg = (int)($sum / $len);
            } else {
                $avg = 0;
            }

            $bits .= ($avg > $prevAvg) ? '1' : '0';
            $prevAvg = $avg;
        }
        fclose($handle);

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $nibble = substr($bits, $i, 4);
            $hex .= dechex(bindec($nibble));
        }
        return strtolower($hex);
    }

    /**
     * Compute Hamming distance between two 64-bit hex perceptual hashes.
     * Distance <= 5 indicates strong visual similarity / near-identical image.
     */
    public static function hammingDistance(string $hashA, string $hashB): int {
        $hashA = strtolower(trim($hashA));
        $hashB = strtolower(trim($hashB));

        if (strlen($hashA) !== strlen($hashB) || strlen($hashA) === 0) {
            return 64;
        }

        $distance = 0;
        $len = strlen($hashA);
        for ($i = 0; $i < $len; $i++) {
            $valA = hexdec($hashA[$i]);
            $valB = hexdec($hashB[$i]);
            $xor = $valA ^ $valB;
            // Count number of set bits in 4-bit nibble
            while ($xor > 0) {
                $distance += ($xor & 1);
                $xor >>= 1;
            }
        }

        return $distance;
    }

    /**
     * Alias for checkDuplicateImage
     */
    public static function findDuplicateImage(
        PDO $pdo,
        string $sha256,
        ?string $phash,
        int $currentOrderId,
        int $currentBuyerId = 0,
        int $tolerance = 5
    ): array {
        return self::checkDuplicateImage($pdo, $sha256, (string)$phash, $currentOrderId, $currentBuyerId, $tolerance);
    }

    /**
     * Check if this screenshot is an exact or perceptual duplicate of a previous submission.
     */
    public static function checkDuplicateImage(
        PDO $pdo,
        string $sha256,
        string $phash,
        int $currentOrderId,
        int $currentBuyerId,
        int $tolerance = 5
    ): array {
        // 1. Check exact cryptographic hash (SHA-256)
        $stmtExact = $pdo->prepare("
            SELECT fp.id, fp.child_order_id, fp.buyer_id, fp.file_path, fp.created_at, c.status as order_status
            FROM payment_fingerprints fp
            JOIN child_orders c ON fp.child_order_id = c.id
            WHERE fp.image_sha256 = ? AND fp.child_order_id != ?
            LIMIT 1
        ");
        $stmtExact->execute([$sha256, $currentOrderId]);
        $exactMatch = $stmtExact->fetch(PDO::FETCH_ASSOC);

        if ($exactMatch) {
            $conflictBuyerId = (int)$exactMatch['buyer_id'];
            $isCrossUser = ($conflictBuyerId !== $currentBuyerId);
            return [
                'is_duplicate' => true,
                'match_type' => 'EXACT_SHA256',
                'conflict_order_id' => (int)$exactMatch['child_order_id'],
                'conflict_buyer_id' => $conflictBuyerId,
                'conflict_order_status' => $exactMatch['order_status'],
                'is_cross_user' => $isCrossUser,
                'distance' => 0,
                'reason_code' => 'DUPLICATE_IMAGE_HASH',
                'description' => sprintf(
                    'Exact identical payment screenshot was previously submitted on Order #%04d (%s).',
                    (int)$exactMatch['child_order_id'],
                    $isCrossUser ? "User #{$conflictBuyerId}" : "your account"
                )
            ];
        }

        // 2. Check perceptual visual hash (Hamming distance)
        if (!empty($phash)) {
            $stmtPhash = $pdo->prepare("
                SELECT fp.id, fp.child_order_id, fp.buyer_id, fp.image_phash, c.status as order_status
                FROM payment_fingerprints fp
                JOIN child_orders c ON fp.child_order_id = c.id
                WHERE fp.image_phash IS NOT NULL AND fp.child_order_id != ?
                ORDER BY fp.id DESC
                LIMIT 100
            ");
            $stmtPhash->execute([$currentOrderId]);
            $candidates = $stmtPhash->fetchAll(PDO::FETCH_ASSOC);

            foreach ($candidates as $cand) {
                $dist = self::hammingDistance($phash, (string)$cand['image_phash']);
                if ($dist <= $tolerance) {
                    $conflictBuyerId = (int)$cand['buyer_id'];
                    $isCrossUser = ($conflictBuyerId !== $currentBuyerId);
                    return [
                        'is_duplicate' => true,
                        'match_type' => 'PERCEPTUAL_PHASH',
                        'conflict_order_id' => (int)$cand['child_order_id'],
                        'conflict_buyer_id' => $conflictBuyerId,
                        'conflict_order_status' => $cand['order_status'],
                        'is_cross_user' => $isCrossUser,
                        'distance' => $dist,
                        'reason_code' => 'PERCEPTUAL_SIMILAR_IMAGE',
                        'description' => sprintf(
                            'Visually identical or near-duplicate screenshot detected (pHash distance %d <= %d) from Order #%04d.',
                            $dist,
                            $tolerance,
                            (int)$cand['child_order_id']
                        )
                    ];
                }
            }
        }

        return [
            'is_duplicate' => false,
            'match_type' => null,
            'conflict_order_id' => null,
            'conflict_buyer_id' => null,
            'is_cross_user' => false,
            'distance' => null,
            'reason_code' => null,
            'description' => null
        ];
    }

    private static function getImageDimensions(string $filePath): array {
        $size = @getimagesize($filePath);
        if ($size && isset($size[0], $size[1])) {
            return ['width' => (int)$size[0], 'height' => (int)$size[1]];
        }
        return ['width' => 0, 'height' => 0];
    }

    private static function detectEditingArtifacts(string $filePath): array {
        $hints = [];
        $raw = (string)file_get_contents($filePath, false, null, 0, 64 * 1024);

        if (stripos($raw, 'Photoshop') !== false) {
            $hints[] = 'Adobe Photoshop signature detected in image header';
        }
        if (stripos($raw, 'Canva') !== false) {
            $hints[] = 'Canva graphics editor signature detected';
        }
        if (stripos($raw, 'PicsArt') !== false) {
            $hints[] = 'PicsArt mobile editor signature detected';
        }
        if (stripos($raw, 'GIMP') !== false) {
            $hints[] = 'GIMP open-source editor signature detected';
        }

        return $hints;
    }

    private static function resolveDefaultOcrProvider(): OcrProviderInterface {
        $geminiKey = getenv('GEMINI_API_KEY');
        if (!empty($geminiKey)) {
            return new GeminiVisionOcrProvider($geminiKey);
        }
        return new LocalPatternOcrProvider();
    }
}
