<?php

declare(strict_types=1);

namespace CodeVault\Billing;

/**
 * Validates and stores manual-crypto payment proofs (the screenshot or
 * receipt a client uploads after sending USDT to the admin's wallet).
 * Modeled on TicketAttachmentService: extension-allowlisted, random stored
 * name (the original name only ever lives in the DB row), size-capped, so
 * a hostile filename can neither traverse paths nor execute.
 *
 * Proofs are stricter than ticket attachments — they exist to be LOOKED at
 * by a reviewing admin, so only images and PDF are accepted.
 */
final class CryptoManualProofService
{
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    /** extension => canonical mime type served back on review */
    private const ALLOWED = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'heic' => 'image/heic', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'pdf' => 'application/pdf',
    ];

    private readonly string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? dirname(__DIR__, 2) . '/storage/crypto_proofs';
    }

    /**
     * Store one uploaded proof. Returns the row fields on success or a
     * human reason on failure — the caller decides how to surface it.
     *
     * @param array<string, mixed>|null $filesEntry the raw $request->file('proof') entry
     * @return array{ok: bool, error?: string, path?: string, name?: string, mime?: string, size?: int}
     */
    public function store(?array $filesEntry): array
    {
        if ($filesEntry === null || ($filesEntry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'A payment proof screenshot (or PDF) is required — the admin has nothing to review without it.'];
        }

        if (($filesEntry['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The proof file failed to upload — please try again.'];
        }

        if ((int) ($filesEntry['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'The proof file is larger than 10 MB.'];
        }

        $ext = strtolower(pathinfo((string) ($filesEntry['name'] ?? ''), PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$ext])) {
            return ['ok' => false, 'error' => 'The proof must be an image (jpg, png, gif, webp, bmp, heic, tiff) or a PDF.'];
        }

        if (!is_uploaded_file((string) ($filesEntry['tmp_name'] ?? ''))) {
            return ['ok' => false, 'error' => 'The proof file could not be verified as a real upload.'];
        }

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $this->storageDir . '/' . $storedName;

        if (!move_uploaded_file((string) $filesEntry['tmp_name'], $destination)) {
            return ['ok' => false, 'error' => 'The proof file could not be saved — please try again.'];
        }

        return [
            'ok' => true,
            'path' => $storedName,
            'name' => mb_substr(trim((string) ($filesEntry['name'] ?? 'proof')), 0, 255),
            'mime' => self::ALLOWED[$ext],
            'size' => (int) ($filesEntry['size'] ?? 0),
        ];
    }

    /**
     * Absolute path + canonical mime for serving a proof to a reviewing
     * admin, or null when the row/file is missing. basename() keeps the
     * lookup inside the storage dir even for a tampered row.
     *
     * @param array<string, mixed> $payment a manual_crypto_payments row
     * @return array{path: string, mime: string, name: string}|null
     */
    public function fileFor(array $payment): ?array
    {
        $stored = (string) ($payment['proof_path'] ?? '');

        if ($stored === '') {
            return null;
        }

        $path = $this->storageDir . '/' . basename($stored);

        if (!is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => (string) ($payment['proof_mime'] ?? 'application/octet-stream'),
            'name' => (string) ($payment['proof_name'] ?? $stored),
        ];
    }
}
