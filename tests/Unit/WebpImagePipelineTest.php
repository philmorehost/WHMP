<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Media\ImageController;
use CodeVault\Media\WebpImageService;
use CodeVault\Request;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * WebP image pipeline (blueprint performance gap). Locks in: raster images
 * convert to a cached WebP derivative (optionally downscaled), the /img
 * endpoint serves WebP to WebP-capable clients and the original otherwise
 * (both with far-future cache headers), and non-raster or missing files
 * degrade to 404 / original bytes rather than erroring.
 */
final class WebpImagePipelineTest extends BaseTestCase
{
    private string $basePath;
    private string $sourcePng;
    private string $sourceJpg;
    private string $sourceGif;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required for this test.');
        }

        $this->basePath = sys_get_temp_dir() . '/codevault-webp-' . uniqid();
        mkdir($this->basePath . '/public/assets/testimg', 0777, true);

        // 40x20 PNG
        $this->sourcePng = $this->basePath . '/public/assets/testimg/photo.png';
        $png = imagecreatetruecolor(40, 20);
        imagefill($png, 0, 0, imagecolorallocate($png, 200, 30, 30));
        imagepng($png, $this->sourcePng);
        imagedestroy($png);

        // 40x20 JPEG
        $this->sourceJpg = $this->basePath . '/public/assets/testimg/photo.jpg';
        $jpg = imagecreatetruecolor(40, 20);
        imagefill($jpg, 0, 0, imagecolorallocate($jpg, 30, 200, 30));
        imagejpeg($jpg, $this->sourceJpg, 90);
        imagedestroy($jpg);

        // 40x20 GIF
        $this->sourceGif = $this->basePath . '/public/assets/testimg/photo.gif';
        $gif = imagecreatetruecolor(40, 20);
        imagefill($gif, 0, 0, imagecolorallocate($gif, 30, 30, 200));
        imagegif($gif, $this->sourceGif);
        imagedestroy($gif);
    }

    protected function tearDown(): void
    {
        if (isset($this->basePath) && is_dir($this->basePath)) {
            $this->rrmdir($this->basePath);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function service(): WebpImageService
    {
        return new WebpImageService($this->basePath);
    }

    public function test_derivative_converts_a_png_to_webp_and_caches(): void
    {
        $service = $this->service();

        $path1 = $service->derivative($this->sourcePng);
        $this->assertNotNull($path1);
        $this->assertStringEndsWith('.webp', $path1);
        $this->assertFileExists($path1);

        $size1 = filesize($path1);
        $path2 = $service->derivative($this->sourcePng);
        $this->assertSame($path1, $path2, 'A second call must reuse the same cached file.');
        $this->assertSame($size1, filesize($path2));

        $info = @getimagesize($path2);
        $this->assertSame('image/webp', $info['mime']);
    }

    public function test_derivative_downscales_when_a_max_width_is_given(): void
    {
        $path = $this->service()->derivative($this->sourcePng, 20);
        $this->assertNotNull($path);

        $info = @getimagesize($path);
        $this->assertSame(20, (int) $info[0]);
        $this->assertSame(10, (int) $info[1]); // aspect 2:1 preserved
    }

    public function test_derivative_returns_null_for_a_non_raster_file(): void
    {
        $txt = $this->basePath . '/public/assets/testimg/notes.txt';
        file_put_contents($txt, 'not an image');

        $this->assertNull($this->service()->derivative($txt));
    }

    public function test_controller_serves_webp_when_the_client_accepts_it(): void
    {
        $controller = new ImageController($this->service(), $this->basePath);

        $response = $controller->serve(
            new Request(
                ['path' => '/assets/testimg/photo.png', 'w' => '20'],
                [],
                ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/img'],
                ['Accept' => 'image/webp,image/png,*/*'],
                [],
                ''
            ),
            []
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('image/webp', $response->headers()['Content-Type']);
        $this->assertStringContainsString('max-age=31536000', $response->headers()['Cache-Control']);
    }

    public function test_controller_serves_the_original_without_webp_accept(): void
    {
        $controller = new ImageController($this->service(), $this->basePath);

        $response = $controller->serve(
            new Request(
                ['path' => '/assets/testimg/photo.png'],
                [],
                ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/img'],
                ['Accept' => 'image/png,*/*'],
                [],
                ''
            ),
            []
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('image/png', $response->headers()['Content-Type']);
        $this->assertStringContainsString('max-age=31536000', $response->headers()['Cache-Control']);
    }

    public function test_controller_returns_404_for_a_missing_or_path_traversal_file(): void
    {
        $controller = new ImageController($this->service(), $this->basePath);

        $missing = $controller->serve(
            new Request(['path' => '/assets/testimg/nope.png'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/img'], [], [], ''),
            []
        );
        $this->assertSame(404, $missing->status());

        $traversal = $controller->serve(
            new Request(['path' => '/assets/../../.env'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/img'], [], [], ''),
            []
        );
        $this->assertSame(404, $traversal->status());
    }
}
