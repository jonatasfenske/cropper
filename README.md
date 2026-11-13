# JonatasFenske\Cropper

<p align="center">
 <strong>Crop inteligente de imagens PHP 8.5 com Focal Point, AVIF e responsive images</strong>
</p>

<p align="center">
 <img src="https://img.shields.io/badge/PHP-8.5%2B-8892BF?logo=php" alt="PHP 8.5+">
 <img src="https://img.shields.io/badge/license-MIT-blue" alt="MIT License">
</p>

## Instalação

```bash
composer require jonatasfenske/cropper
```

## Recursos

- **Focal Point crop** — algoritmos center, face (detecção de pele), entropy (complexidade visual) e custom
- **AVIF e WebP nativos** via GD/Imagick sem dependências externas
- **Batch processing** para geração de múltiplos tamanhos (responsive images)
- **Tag `<picture>` completa** com múltiplos formatos e fallback automático
- **srcset generation** com descritores `w` ou `x` (retina)
- **Presets prontos** — standard, retina, thumbnail
- **Suporte dual GD/Imagick** com detecção automática

## Uso Rápido

### Crop Simples

```php
use JonatasFenske\Cropper\Cropper;

$cropper = new Cropper('storage/cache/thumbs', quality: 80, webP: true);
$thumb = $cropper->make('storage/uploads/foto.jpg', width: 300, height: 200);
```

### Focal Point Inteligente

```php
use JonatasFenske\Cropper\FocalPoint;

$focal = new FocalPoint('face'); // ou 'entropy', 'center', 'custom'
$point = $focal->detect('storage/uploads/retrato.jpg');
// ['x' => 480, 'y' => 200] — região do rosto detectada
```

### Responsive Images

```php
use JonatasFenske\Cropper\BatchProcessor;

$batch = new BatchProcessor($cropper);

$results = $batch->generateResponsive('storage/uploads/hero.jpg', [
 ['width' => 320, 'suffix' => '-sm'],
 ['width' => 640, 'suffix' => '-md'],
 ['width' => 1280, 'suffix' => '-lg'],
]);

$srcset = $batch->buildSrcset($results);
// "/cache/hero-320w.webp 320w, /cache/hero-640w.webp 640w, ..."

echo $batch->buildPictureTag(
 'storage/uploads/hero.jpg',
 BatchProcessor::presetStandard(),
 formats: ['webp', 'jpg'],
 alt: 'Banner principal',
 extraAttrs: ['loading' => 'lazy'],
);
```

## Testes

```bash
composer test
```

## Licença

MIT