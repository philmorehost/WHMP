<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database;
use CodeVault\Support\TicketAttachmentRepository;
use CodeVault\Support\TicketAttachmentService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure upload-shape logic (no DB / no real file moves): correctly
 * telling a real upload from an empty file field across both the single-file
 * and multi-file ($_FILES['x[]']) PHP shapes.
 */
final class TicketAttachmentServiceTest extends TestCase
{
    private function service(): TicketAttachmentService
    {
        // The repo is never touched by hasRealUpload(); no connection is made.
        $repo = new TicketAttachmentRepository(new Database('127.0.0.1', '3306', 'unused', 'root', ''));

        return new TicketAttachmentService($repo, sys_get_temp_dir());
    }

    public function test_null_entry_is_not_a_real_upload(): void
    {
        $this->assertFalse($this->service()->hasRealUpload(null));
    }

    public function test_single_uploaded_file_is_detected(): void
    {
        $entry = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10];
        $this->assertTrue($this->service()->hasRealUpload($entry));
    }

    public function test_single_empty_file_field_is_ignored(): void
    {
        $entry = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $this->assertFalse($this->service()->hasRealUpload($entry));
    }

    public function test_multi_file_shape_with_one_real_file_is_detected(): void
    {
        // The shape PHP produces for <input name="attachments[]" multiple>.
        $entry = [
            'name' => ['', 'doc.pdf'],
            'type' => ['', 'application/pdf'],
            'tmp_name' => ['', '/tmp/y'],
            'error' => [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
            'size' => [0, 2048],
        ];
        $this->assertTrue($this->service()->hasRealUpload($entry));
    }

    public function test_multi_file_shape_all_empty_is_ignored(): void
    {
        $entry = [
            'name' => ['', ''],
            'type' => ['', ''],
            'tmp_name' => ['', ''],
            'error' => [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_FILE],
            'size' => [0, 0],
        ];
        $this->assertFalse($this->service()->hasRealUpload($entry));
    }

    public function test_missing_file_on_disk_returns_null(): void
    {
        $result = $this->service()->fileFor(['stored_name' => 'does-not-exist.png', 'mime_type' => 'image/png', 'original_name' => 'x.png']);
        $this->assertNull($result);
    }
}
