<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

/**
 * Validates and stores KB article image uploads. Same shape as
 * TicketAttachmentService: random on-disk filenames under
 * storage/kb_article_images/ (never public/), original name kept only in
 * the DB, so a crafted filename can't traverse paths or get executed by the
 * web server.
 */
final class KbImageUploadService
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB per image
    private const MAX_FILES = 10;

    /** extension => canonical mime type served back on display/download */
    private const ALLOWED = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    ];

    private readonly string $storageDir;

    public function __construct(
        private readonly KbImageRepository $images,
        ?string $storageDir = null
    ) {
        $this->storageDir = $storageDir ?? dirname(__DIR__, 2) . '/storage/kb_article_images';
    }

    /**
     * @param array<string, mixed>|null $filesEntry the raw $request->file('images') entry
     * @return array{stored: int, errors: array<int, string>}
     */
    public function storeFromFilesEntry(?array $filesEntry, int $articleId): array
    {
        $errors = [];
        $stored = 0;

        if ($filesEntry === null) {
            return ['stored' => 0, 'errors' => []];
        }

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $sortOrder = $this->images->nextSortOrder($articleId);
        $count = 0;

        foreach ($this->normalize($filesEntry) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if (++$count > self::MAX_FILES) {
                $errors[] = 'Only ' . self::MAX_FILES . ' images can be uploaded at once — extra files were skipped.';
                break;
            }

            if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $errors[] = "\"{$file['name']}\" failed to upload.";
                continue;
            }

            if ((int) $file['size'] > self::MAX_BYTES) {
                $errors[] = "\"{$file['name']}\" is larger than 5 MB.";
                continue;
            }

            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

            if (!isset(self::ALLOWED[$ext])) {
                $errors[] = "\"{$file['name']}\" is not an allowed image type.";
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

            $this->images->create([
                'article_id' => $articleId,
                'source' => 'upload',
                'original_name' => $this->safeOriginalName((string) $file['name']),
                'stored_name' => $storedName,
                'mime_type' => self::ALLOWED[$ext],
                'size_bytes' => (int) $file['size'],
                'sort_order' => $sortOrder++,
            ]);
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
     * Absolute path + canonical mime for serving an uploaded image, or null
     * if the row isn't an upload / the file is missing.
     *
     * @param array<string, mixed> $image a kb_article_images row
     * @return array{path: string, mime: string, name: string}|null
     */
    public function fileFor(array $image): ?array
    {
        if ($image['stored_name'] === null) {
            return null;
        }

        $path = $this->storageDir . '/' . basename((string) $image['stored_name']);

        if (!is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => (string) $image['mime_type'],
            'name' => (string) ($image['original_name'] ?? basename($path)),
        ];
    }

    /**
     * Deletes the DB row and, for an upload, the file on disk. AI-generated
     * rows have no file to remove — their content lives entirely in
     * svg_content.
     */
    public function deleteImage(int $imageId): bool
    {
        $image = $this->images->find($imageId);

        if ($image === null) {
            return false;
        }

        if ($image['source'] === 'upload' && $image['stored_name'] !== null) {
            $name = basename((string) $image['stored_name']);
            $path = $this->storageDir . '/' . $name;

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->images->delete($imageId);

        return true;
    }

    /**
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
