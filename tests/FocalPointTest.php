<?php

declare(strict_types=1);

namespace JonatasFenske\Cropper\Tests;

use JonatasFenske\Cropper\FocalPoint;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do FocalPoint para PHP 8.5
 */
final class FocalPointTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/coffeecode_focal_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testCenterAlgorithmReturnsMiddleOfImage(): void
    {
        $img = imagecreatetruecolor(200, 100);
        $path = $this->tempDir . '/center.png';
        imagepng($img, $path);
        imagedestroy($img);

        $fp = new FocalPoint('center');
        $point = $fp->detect($path);

        self::assertSame(100, $point['x']);
        self::assertSame(50, $point['y']);
    }

    public function testCustomAlgorithmWithSetPoint(): void
    {
        $img = imagecreatetruecolor(400, 200);
        $path = $this->tempDir . '/custom.png';
        imagepng($img, $path);
        imagedestroy($img);

        $fp = new FocalPoint('custom');
        $fp->setCustomPoint(75, 25); // 75% direita, 25% topo
        $point = $fp->detect($path);

        self::assertSame(300, $point['x']); // 400 * 0.75
        self::assertSame(50, $point['y']);  // 200 * 0.25
    }

    public function testCustomAlgorithmWithoutSetPointFallsBackToCenter(): void
    {
        $img = imagecreatetruecolor(200, 100);
        $path = $this->tempDir . '/custom_default.png';
        imagepng($img, $path);
        imagedestroy($img);

        $fp = new FocalPoint('custom');
        $point = $fp->detect($path);

        self::assertSame(100, $point['x']);
        self::assertSame(50, $point['y']);
    }

    public function testEntropyAlgorithmReturnsValidCoordinates(): void
    {
        // Cria imagem com região de alto contraste à direita
        $img = imagecreatetruecolor(200, 100);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 99, 99, $white);
        imagefilledrectangle($img, 100, 0, 199, 99, $black);
        // Adiciona ruído na metade direita para aumentar entropia
        for ($i = 0; $i < 500; $i++) {
            imagesetpixel($img, rand(100, 199), rand(0, 99), rand(0, 1) ? $white : $black);
        }

        $path = $this->tempDir . '/entropy.png';
        imagepng($img, $path);
        imagedestroy($img);

        $fp = new FocalPoint('entropy');
        $point = $fp->detect($path);

        // Ponto focal deve estar dentro dos limites da imagem
        self::assertGreaterThanOrEqual(0, $point['x']);
        self::assertLessThanOrEqual(200, $point['x']);
        self::assertGreaterThanOrEqual(0, $point['y']);
        self::assertLessThanOrEqual(100, $point['y']);
    }

    public function testFaceDetectionReturnsValidCoordinates(): void
    {
        // Cria imagem com tons de pele concentrados no centro-superior
        $img = imagecreatetruecolor(200, 200);
        $skin = imagecolorallocate($img, 210, 170, 130);
        $bg = imagecolorallocate($img, 50, 50, 50);
        imagefilledrectangle($img, 0, 0, 199, 199, $bg);
        imagefilledellipse($img, 100, 60, 80, 80, $skin);

        $path = $this->tempDir . '/face.png';
        imagepng($img, $path);
        imagedestroy($img);

        $fp = new FocalPoint('face');
        $point = $fp->detect($path);

        self::assertGreaterThanOrEqual(0, $point['x']);
        self::assertLessThanOrEqual(200, $point['x']);
        self::assertGreaterThanOrEqual(0, $point['y']);
        self::assertLessThanOrEqual(200, $point['y']);
    }

    public function testInvalidAlgorithmThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Algoritmo inválido');
        new FocalPoint('invalid_algo');
    }

    public function testSetCustomPointClampsValues(): void
    {
        $img = imagecreatetruecolor(100, 100);
        $path = $this->tempDir . '/clamp.png';
        imagepng($img, $path);
        imagedestroy($img);

        $fp = new FocalPoint('custom');
        $fp->setCustomPoint(150, -10); // Fora dos limites → clamp para 100 e 0
        $point = $fp->detect($path);

        self::assertSame(100, $point['x']); // 100% de 100
        self::assertSame(0, $point['y']);   // 0% de 100
    }

    public function testNonexistentFileReturnsZeroCoordinates(): void
    {
        $fp = new FocalPoint('center');
        $point = $fp->detect('/nonexistent/image.png');

        self::assertSame(0, $point['x']);
        self::assertSame(0, $point['y']);
    }

    public function testFluentSetCustomPointReturnsSelf(): void
    {
        $fp = new FocalPoint('custom');
        $result = $fp->setCustomPoint(50, 50);

        self::assertInstanceOf(FocalPoint::class, $result);
    }
}