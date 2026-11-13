<?php

declare(strict_types=1);

/**
 * Exemplo de crop inteligente e responsive images com Cropper
 * Execute: php examples/responsive_images.php
 */

require __DIR__ . '/../vendor/autoload.php';

use JonatasFenske\Cropper\Cropper;
use JonatasFenske\Cropper\FocalPoint;
use JonatasFenske\Cropper\BatchProcessor;
use JonatasFenske\Cropper\ImageEngine;

// Verificar engine disponível
echo "Engine: " . ImageEngine::best()->value . "\n\n";

// Criar imagem de teste
$testDir = sys_get_temp_dir() . '/cropper_example_' . uniqid();
mkdir($testDir, 0755, true);
$img = imagecreatetruecolor(1920, 1080);
$color = imagecolorallocate($img, 100, 150, 200);
imagefilledrectangle($img, 0, 0, 1919, 1079, $color);
$testImage = $testDir . '/hero.jpg';
imagejpeg($img, $testImage, 90);
imagedestroy($img);

// 1. Crop simples com WebP
$cropper = new Cropper($testDir . '/cache', quality: 80, webP: true);
$thumb = $cropper->make($testImage, width: 300, height: 200);
echo "1. Thumbnail WebP: {$thumb}\n";

// 2. Focal Point - detecção de rosto
$focal = new FocalPoint('face');
$point = $focal->detect($testImage);
echo "2. Focal Point (face): x={$point['x']}, y={$point['y']}\n";

// 3. Focal Point - entropia
$focalEntropy = new FocalPoint('entropy');
$pointEntropy = $focalEntropy->detect($testImage);
echo "3. Focal Point (entropy): x={$pointEntropy['x']}, y={$pointEntropy['y']}\n";

// 4. Batch processing para responsive images
$batch = new BatchProcessor($cropper);
$results = $batch->generateResponsive($testImage, BatchProcessor::presetStandard());
echo "4. Responsive images geradas: " . count($results) . "\n";
foreach ($results as $r) {
    echo "   - {$r['width']}x{$r['height']} ({$r['size']} bytes)\n";
}

// 5. Gerar srcset
$srcset = $batch->buildSrcset($results);
echo "5. srcset: {$srcset}\n";

// 6. Gerar tag <picture>
$picture = $batch->buildPictureTag(
    $testImage,
    [['width' => 320], ['width' => 640]],
    formats: ['webp', 'jpg'],
    alt: 'Banner hero',
    extraAttrs: ['loading' => 'lazy'],
);
echo "6. Tag <picture>:\n{$picture}\n";

// Limpar
array_map('unlink', glob($testDir . '/cache/*') ?: []);
@rmdir($testDir . '/cache');
@unlink($testImage);
@rmdir($testDir);

echo "\n✅ Exemplo concluído!\n";