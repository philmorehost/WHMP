<?php

declare(strict_types=1);

namespace CodeVault\Support;

/**
 * Validates and stores ticket file uploads. Accepts common image and
 * document types; rejects anything executable/unknown. Files are stored
 * under storage/ticket_attachments/ with a random name (the original name
 * is kept in the DB for display) so a malicious filename can never traverse
 * paths or be executed by the web server.
 */
final class TicketAttachmentService
{
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB per file
    private const MAX_FILES = 6;

    /** extension => canonical mime type served back on preview/download */
    private const ALLOWED = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml', 'heic' => 'image/heic', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'rtf' => 'application/rtf',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'zip' => 'application/zip',
    ];

    private readonly string $storageDir;

    public function __construct(
        private readonly TicketAttachmentRepository $attachments,
        ?string $storageDir = null
    ) {
        $this->storageDir = $storageDir ?? dirname(__DIR__, 2) . '/storage/ticket_attachments';
    }

    /**
     * Store every valid uploaded file from a $_FILES entry against a ticket
     * (and optionally a specific reply). Invalid files are silently skipped
     * with their reason returned, so one bad file doesn't lose the good ones.
     *
     * @param array<string, mixed>|null $filesEntry the raw $request->file('attachments') entry
     * @return array{stored: int, errors: array<int, string>}
     */
    public function storeFromFilesEntry(?array $filesEntry, int $ticketId, ?int $replyId, string $uploadedBy): array
    {
        $errors = [];
        $stored = 0;

        if ($filesEntry === null) {
            return ['stored' => 0, 'errors' => []];
        }

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $count = 0;
        foreach ($this->normalize($filesEntry) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if (++$count > self::MAX_FILES) {
                $errors[] = 'Only ' . self::MAX_FILES . ' files can be attached at once — extra files were skipped.';
                break;
            }

            if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $errors[] = "\"{$file['name']}\" failed to upload.";
                continue;
            }

            if ((int) $file['size'] > self::MAX_BYTES) {
                $errors[] = "\"{$file['name']}\" is larger than 10 MB.";
                continue;
            }

            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

            if (!isset(self::ALLOWED[$ext])) {
                $errors[] = "\"{$file['name']}\" is not an allowed file type.";
                continue;
            }

            if (!is_uploaded_file((string) $file['tmp_name'])) {
                continue;
            }

            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destination = $this->storageDir . '/' . $storedName;

            if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
                $errors[] = "\"{$file['name']}\" could not be saved.";
                continue;
            }

            $this->attachments->create(
                $ticketId,
                $replyId,
                $uploadedBy,
                $this->safeOriginalName((string) $file['name']),
                $storedName,
                self::ALLOWED[$ext],
                (int) $file['size']
            );
            $stored++;
        }

        return ['stored' => $stored, 'errors' => $errors];
    }

    /** True if the $_FILES entry contains at least one actually-uploaded file. */
    public function hasRealUpload(?array $filesEntry): bool
    {
        if ($filesEntry === null) {
            return false;
        }

        foreach ($this->normalize($filesEntry) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                return true;
            }
        }

        return false;
    }

    /**
     * Absolute path + canonical mime for serving an attachment, or null if
     * the row/file is missing.
     *
     * @param array<string, mixed> $attachment a ticket_attachments row
     * @return array{path: string, mime: string, name: string}|null
     */
    public function fileFor(array $attachment): ?array
    {
        $path = $this->storageDir . '/' . basename((string) $attachment['stored_name']);

        if (!is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => (string) $attachment['mime_type'],
            'name' => (string) $attachment['original_name'],
        ];
    }

    /**
     * Removes uploaded files from disk.
     *
     * The ticket_attachments rows disappear on their own when a ticket is
     * deleted (the foreign key cascades), but the files they point at do not —
     * without this every deleted ticket would leave its uploads behind
     * forever, consuming disk with no record that they exist.
     *
     * basename() is applied because stored_name is the only part of the path
     * that comes from the database: it confines the unlink to the storage
     * directory even if a row were ever crafted with traversal in the name.
     *
     * @param array<int, string> $storedNames
     * @return int files actually removed
     */
    public function deleteFiles(array $storedNames): int
    {
        $removed = 0;

        foreach ($storedNames as $storedName) {
            $name = basename(trim((string) $storedName));

            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $path = $this->storageDir . '/' . $name;

            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Normalizes both the single-file and multi-file ($_FILES['x[]']) shapes
     * into a flat list of {name,type,tmp_name,error,size}.
     *
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $entry): array
    {
        if (!isset($entry['name'])) {
            return [];
        }

        if (!is_array($entry['name'])) {
            return [$entry];
        }

        $files = [];
        foreach (array_keys($entry['name']) as $i) {
            $files[] = [
                'name' => $entry['name'][$i] ?? '',
                'type' => $entry['type'][$i] ?? '',
                'tmp_name' => $entry['tmp_name'][$i] ?? '',
                'error' => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $entry['size'][$i] ?? 0,
            ];
        }

        return $files;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename($name);

        return $name === '' ? 'file' : substr($name, 0, 255);
    }
}
