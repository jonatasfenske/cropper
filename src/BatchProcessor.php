<?php

declare(strict_types=1);

namespace JonatasFenske\Cropper;

/**
 * Processador batch para geração de múltiplos tamanhos de imagem
 * PHP 8.5: tipagem estrita, suporte a responsive images
 *
 * Uso típico: gerar srcset para <picture> ou <img srcset>
 */
class BatchProcessor
{
    private Cropper $cropper;

    public function __construct(Cropper $cropper)
    {
        $this->cropper = $cropper;
    }

    /**
     * Gera múltiplos tamanhos de uma imagem para responsive images
     *
     * @param string $imagePath Caminho da imagem original
     * @param array<int, array{width: int, height?: int, suffix?: string}> $sizes
     *        Exemplo: [
     *            ['width' => 320, 'suffix' => '-sm'],
     *            ['width' => 640, 'suffix' => '-md'],
     *            ['width' => 1280, 'suffix' => '-lg'],
     *            ['width' => 1920, 'suffix' => '-xl'],
     *        ]
     * @return array<int, array{path: string, width: int, height: int, size: int}>
     *         Array com caminhos e metadados das imagens geradas
     */
    public function generateResponsive(string $imagePath, array $sizes): array
    {
        $results = [];

        foreach ($sizes as $sizeConfig) {
            $width = $sizeConfig['width'];
            $height = $sizeConfig['height'] ?? null;
            $suffix = $sizeConfig['suffix'] ?? "-{$width}w";

            $outputPath = $this->cropper->make($imagePath, $width, $height);

            if ($outputPath !== null && file_exists($outputPath)) {
                $imageSize = getimagesize($outputPath);
                $fileSize = filesize($outputPath);

                $results[] = [
                    'path' => $outputPath,
                    'width' => $imageSize[0] ?? $width,
                    'height' => $imageSize[1] ?? $height ?? $width,
                    'size' => $fileSize !== false ? $fileSize : 0,
                    'suffix' => $suffix,
                ];
            }
        }

        return $results;
    }

    /**
     * Gera srcset HTML para tag <img> ou <source>
     *
     * @param array<int, array{path: string, width: int}> $images Resultado de generateResponsive()
     * @param bool $useDescriptors Se true, usa 'w' descriptors; se false, usa 'x' (retina)
     * @return string Atributo srcset pronto para uso
     */
    public function buildSrcset(array $images, bool $useDescriptors = true): string
    {
        $parts = [];

        foreach ($images as $image) {
            $path = $image['path'];
            $width = $image['width'];

            if ($useDescriptors) {
                $parts[] = "{$path} {$width}w";
            } else {
                // Retina descriptors (1x, 2x, 3x)
                $multiplier = max(1, (int)($width / 320));
                $parts[] = "{$path} {$multiplier}x";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Gera tag <picture> completa com múltiplos formatos e tamanhos
     *
     * @param string $originalPath Caminho da imagem original
     * @param array<int, array{width: int, height?: int}> $sizes Tamanhos desejados
     * @param array<string> $formats Formatos: ['webp', 'avif', 'jpg']
     * @param string|null $alt Texto alternativo
     * @param array<string, string> $extraAttrs Atributos adicionais (class, loading, etc.)
     * @return string HTML completo da tag <picture>
     */
    public function buildPictureTag(
        string $originalPath,
        array $sizes,
        array $formats = ['webp', 'jpg'],
        ?string $alt = null,
        array $extraAttrs = [],
    ): string {
        $html = "<picture>\n";

        // Gera <source> para cada formato (exceto o último, que vai em <img>)
        $fallbackFormat = array_pop($formats);

        foreach ($formats as $format) {
            $images = $this->generateResponsiveForFormat($originalPath, $sizes, $format);
            if ($images !== []) {
                $srcset = $this->buildSrcset($images);
                $mimeType = $this->getMimeType($format);
                $html .= "  <source srcset=\"{$srcset}\" type=\"{$mimeType}\">\n";
            }
        }

        // Fallback <img> com formato padrão
        $fallbackImages = $this->generateResponsiveForFormat($originalPath, $sizes, $fallbackFormat);
        if ($fallbackImages !== []) {
            $src = $fallbackImages[0]['path']; // Menor tamanho como src padrão
            $srcset = $this->buildSrcset($fallbackImages);

            $attrs = $extraAttrs;
            $attrs['src'] = $src;
            $attrs['srcset'] = $srcset;
            $attrs['alt'] = $alt ?? '';

            $attrString = $this->buildAttributes($attrs);
            $html .= "  <img {$attrString}>\n";
        }

        $html .= "</picture>";

        return $html;
    }

    /**
     * Gera imagens em formato específico
     */
    private function generateResponsiveForFormat(string $imagePath, array $sizes, string $format): array
    {
        // Cria cropper temporário com formato específico
        $tempCropper = new Cropper(
            $this->cropper->getCachePath(),
            $this->cropper->getQuality(),
            $this->cropper->getCompressor(),
            $format === 'webp' || $format === 'avif',
            $this->cropper->getEngine(),
        );

        $results = [];

        foreach ($sizes as $sizeConfig) {
            $width = $sizeConfig['width'];
            $height = $sizeConfig['height'] ?? null;

            $outputPath = $tempCropper->make($imagePath, $width, $height);

            if ($outputPath !== null && file_exists($outputPath)) {
                $imageSize = getimagesize($outputPath);
                $results[] = [
                    'path' => $outputPath,
                    'width' => $imageSize[0] ?? $width,
                    'height' => $imageSize[1] ?? $height ?? $width,
                ];
            }
        }

        return $results;
    }

    /**
     * Retorna MIME type para formato
     */
    private function getMimeType(string $format): string
    {
        return match (strtolower($format)) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    /**
     * Constrói string de atributos HTML
     */
    private function buildAttributes(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            if ($value === true) {
                $parts[] = $key;
            } elseif ($value !== false && $value !== null) {
                $parts[] = "{$key}=\"" . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . "\"";
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Presets comuns de responsive images
     */
    public static function presetStandard(): array
    {
        return [
            ['width' => 320, 'suffix' => '-sm'],
            ['width' => 640, 'suffix' => '-md'],
            ['width' => 1024, 'suffix' => '-lg'],
            ['width' => 1920, 'suffix' => '-xl'],
        ];
    }

    public static function presetRetina(): array
    {
        return [
            ['width' => 320, 'suffix' => '-1x'],
            ['width' => 640, 'suffix' => '-2x'],
            ['width' => 960, 'suffix' => '-3x'],
        ];
    }

    public static function presetThumbnail(): array
    {
        return [
            ['width' => 150, 'height' => 150, 'suffix' => '-thumb'],
            ['width' => 300, 'height' => 300, 'suffix' => '-thumb-2x'],
        ];
    }
}