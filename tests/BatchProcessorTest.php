<?php

declare(strict_types=1);

namespace JonatasFenske\Cropper\Tests;

use JonatasFenske\Cropper\BatchProcessor;
use JonatasFenske\Cropper\Cropper;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do BatchProcessor para PHP 8.5
 */
final class BatchProcessorTest extends TestCase
{
 private string $tempDir;
 private string $cacheDir;

 protected function setUp(): void
 {
 $this->tempDir = sys_get_temp_dir() . '/coffeecode_batch_' . uniqid();
 $this->cacheDir = $this->tempDir . '/cache';
 mkdir($this->tempDir, 0755, true);
 mkdir($this->cacheDir, 0755, true);
 }

 protected function tearDown(): void
 {
 $this->removeDirectory($this->tempDir);
 }

 private function removeDirectory(string $dir): void
 {
 if (!is_dir($dir)) {
 return;
 }

 $items = scandir($dir);
 if ($items === false) {
 return;
 }

 foreach ($items as $item) {
 if ($item === '.' || $item === '..') {
 continue;
 }

 $path = "{$dir}/{$item}";
 if (is_dir($path)) {
 $this->removeDirectory($path);
 } else {
 unlink($path);
 }
 }

 rmdir($dir);
 }

 private function createTestImage(int $width, int $height, string $filename): string
 {
 $img = imagecreatetruecolor($width, $height);
 $color = imagecolorallocate($img, 100, 150, 200);
 imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color);
 $path = "{$this->tempDir}/{$filename}";
 imagepng($img, $path);
 imagedestroy($img);
 return $path;
 }

 public function testGenerateResponsiveCreatesMultipleSizes(): void
 {
 $imagePath = $this->createTestImage(800, 600, 'test.png');
 $cropper = new Cropper($this->cacheDir, quality: 75);
 $batch = new BatchProcessor($cropper);

 $results = $batch->generateResponsive($imagePath, [
 ['width' => 320],
 ['width' => 640],
 ]);

 self::assertCount(2, $results);
 self::assertArrayHasKey('path', $results[0]);
 self::assertArrayHasKey('width', $results[0]);
 self::assertArrayHasKey('size', $results[0]);
 self::assertTrue(file_exists($results[0]['path']));
 self::assertTrue(file_exists($results[1]['path']));
 }

 public function testBuildSrcsetWithWidthDescriptors(): void
 {
 $images = [
 ['path' => '/img/photo-320w.jpg', 'width' => 320],
 ['path' => '/img/photo-640w.jpg', 'width' => 640],
 ['path' => '/img/photo-1280w.jpg', 'width' => 1280],
 ];

 $cropper = new Cropper($this->cacheDir);
 $batch = new BatchProcessor($cropper);
 $srcset = $batch->buildSrcset($images, useDescriptors: true);

 self::assertStringContainsString('/img/photo-320w.jpg 320w', $srcset);
 self::assertStringContainsString('/img/photo-640w.jpg 640w', $srcset);
 self::assertStringContainsString('/img/photo-1280w.jpg 1280w', $srcset);
 }

 public function testBuildSrcsetWithRetinaDescriptors(): void
 {
 $images = [
 ['path' => '/img/photo-320w.jpg', 'width' => 320],
 ['path' => '/img/photo-640w.jpg', 'width' => 640],
 ];

 $cropper = new Cropper($this->cacheDir);
 $batch = new BatchProcessor($cropper);
 $srcset = $batch->buildSrcset($images, useDescriptors: false);

 self::assertStringContainsString('1x', $srcset);
 self::assertStringContainsString('2x', $srcset);
 }

 public function testPresetStandardReturnsFourSizes(): void
 {
 $preset = BatchProcessor::presetStandard();

 self::assertCount(4, $preset);
 self::assertSame(320, $preset[0]['width']);
 self::assertSame(640, $preset[1]['width']);
 self::assertSame(1024, $preset[2]['width']);
 self::assertSame(1920, $preset[3]['width']);
 }

 public function testPresetRetinaReturnsThreeSizes(): void
 {
 $preset = BatchProcessor::presetRetina();

 self::assertCount(3, $preset);
 self::assertSame('-1x', $preset[0]['suffix']);
 self::assertSame('-2x', $preset[1]['suffix']);
 self::assertSame('-3x', $preset[2]['suffix']);
 }

 public function testPresetThumbnailReturnsTwoSizes(): void
 {
 $preset = BatchProcessor::presetThumbnail();

 self::assertCount(2, $preset);
 self::assertSame(150, $preset[0]['width']);
 self::assertSame(150, $preset[0]['height']);
 self::assertSame(300, $preset[1]['width']);
 }

 public function testGenerateResponsiveWithCustomSuffix(): void
 {
 $imagePath = $this->createTestImage(400, 300, 'custom.png');
 $cropper = new Cropper($this->cacheDir);
 $batch = new BatchProcessor($cropper);

 $results = $batch->generateResponsive($imagePath, [
 ['width' => 200, 'suffix' => '-small'],
 ['width' => 400, 'suffix' => '-large'],
 ]);

 self::assertCount(2, $results);
 self::assertSame('-small', $results[0]['suffix']);
 self::assertSame('-large', $results[1]['suffix']);
 }

 public function testGenerateResponsiveReturnsFileSize(): void
 {
 $imagePath = $this->createTestImage(200, 200, 'size_test.png');
 $cropper = new Cropper($this->cacheDir);
 $batch = new BatchProcessor($cropper);

 $results = $batch->generateResponsive($imagePath, [['width' => 100]]);

 self::assertGreaterThan(0, $results[0]['size']);
 }

 public function testBuildPictureTagGeneratesValidHtml(): void
 {
 $imagePath = $this->createTestImage(400, 300, 'picture.png');
 $cropper = new Cropper($this->cacheDir, webP: true);
 $batch = new BatchProcessor($cropper);

 $html = $batch->buildPictureTag(
 $imagePath,
 [['width' => 200], ['width' => 400]],
 formats: ['webp', 'jpg'],
 alt: 'Test image',
 extraAttrs: ['class' => 'responsive-img', 'loading' => 'lazy'],
 );

 self::assertStringContainsString('<picture>', $html);
 self::assertStringContainsString('</picture>', $html);
 self::assertStringContainsString('<source', $html);
 self::assertStringContainsString('type="image/webp"', $html);
 self::assertStringContainsString('<img', $html);
 self::assertStringContainsString('alt="Test image"', $html);
 self::assertStringContainsString('class="responsive-img"', $html);
 self::assertStringContainsString('loading="lazy"', $html);
 }

 public function testEmptySizesReturnsEmptyResults(): void
 {
 $imagePath = $this->createTestImage(200, 200, 'empty.png');
 $cropper = new Cropper($this->cacheDir);
 $batch = new BatchProcessor($cropper);

 $results = $batch->generateResponsive($imagePath, []);

 self::assertSame([], $results);
 }
}