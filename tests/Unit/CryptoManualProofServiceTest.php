<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\CryptoManualProofService;
use PHPUnit\Framework\TestCase;

/**
 * Proof upload validation. The is_uploaded_file()/move_uploaded_file() pair
 * cannot succeed under CLI test runners, so — exactly like
 * TicketAttachmentServiceTest — these pin the guard rails (type/size/
 * missing-file rejections), while the happy path is exercised through the
 * repository in ManualCryptoPaymentTest.
 */
final class CryptoManualProofServiceTest extends TestCase
{
    public function test_a_missing_file_is_refused_with_a_clear_reason(): void
    {
        $service = new CryptoManualProofService(sys_get_temp_dir() . '/crypto-proof-' . uniqid());

        $result = $service->store(null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('payment proof', $result['error']);
    }

    public function test_a_document_that_is_not_an_image_or_pdf_is_refused(): void
    {
        $service = new CryptoManualProofService(sys_get_temp_dir() . '/crypto-proof-' . uniqid());

        $result = $service->store(['name' => 'proof.exe', 'type' => 'application/octet-stream', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 100]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('image', $result['error']);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $service = new CryptoManualProofService(sys_get_temp_dir() . '/crypto-proof-' . uniqid());

        $result = $service->store(['name' => 'proof.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 11 * 1024 * 1024]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('10 MB', $result['error']);
    }

    public function test_a_failed_php_upload_is_refused(): void
    {
        $service = new CryptoManualProofService(sys_get_temp_dir() . '/crypto-proof-' . uniqid());

        $result = $service->store(['name' => 'proof.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 100]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed to upload', $result['error']);
    }

    public function test_a_cli_forged_upload_is_refused_by_the_real_upload_check(): void
    {
        $service = new CryptoManualProofService(sys_get_temp_dir() . '/crypto-proof-' . uniqid());

        $result = $service->store(['name' => 'proof.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 100]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('real upload', $result['error']);
    }

    public function test_serving_uses_only_the_stored_basename(): void
    {
        $service = new CryptoManualProofService('/nonexistent-dir-should-never-be-read');

        $this->assertNull($service->fileFor(['proof_path' => '../../etc/passwd', 'proof_mime' => 'image/png', 'proof_name' => 'x.png']));
        $this->assertNull($service->fileFor(['proof_path' => '', 'proof_mime' => 'image/png', 'proof_name' => 'x.png']));
    }
}
