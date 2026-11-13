<?php

declare(strict_types=1);

namespace JonatasFenske\Cropper;

use GdImage;

/**
 * Detector de ponto focal em imagens para crop inteligente
 * PHP 8.5: tipagem estrita, readonly properties
 *
 * Algoritmos disponíveis:
 * - center: Centro geométrico (padrão)
 * - face: Detecção de rosto via análise de cores de pele
 * - entropy: Região de maior complexidade visual (contraste/textura)
 * - custom: Coordenadas manuais (x, y)
 */
class FocalPoint
{
    private readonly string $algorithm;
    private ?int $customX = null;
    private ?int $customY = null;

    /**
     * @param string $algorithm Algoritmo: 'center', 'face', 'entropy', 'custom'
     */
    public function __construct(string $algorithm = 'center')
    {
        $validAlgorithms = ['center', 'face', 'entropy', 'custom'];
        if (!in_array($algorithm, $validAlgorithms, true)) {
            throw new \InvalidArgumentException(
                "Algoritmo inválido: {$algorithm}. Use: " . implode(', ', $validAlgorithms)
            );
        }

        $this->algorithm = $algorithm;
    }

    /**
     * Define coordenadas customizadas para algoritmo 'custom'
     *
     * @param int $x Coordenada X (0 = esquerda, 100 = direita)
     * @param int $y Coordenada Y (0 = topo, 100 = base)
     * @return static
     */
    public function setCustomPoint(int $x, int $y): static
    {
        $this->customX = max(0, min(100, $x));
        $this->customY = max(0, min(100, $y));
        return $this;
    }

    /**
     * Detecta ponto focal na imagem
     *
     * @param string $imagePath Caminho da imagem
     * @return array{x: int, y: int} Coordenadas em pixels absolutos
     */
    public function detect(string $imagePath): array
    {
        $imageSize = @getimagesize($imagePath);
        if ($imageSize === false) {
            return ['x' => 0, 'y' => 0];
        }

        [$width, $height] = $imageSize;

        return match ($this->algorithm) {
            'center' => $this->detectCenter($width, $height),
            'face' => $this->detectFace($imagePath, $width, $height),
            'entropy' => $this->detectEntropy($imagePath, $width, $height),
            'custom' => $this->detectCustom($width, $height),
        };
    }

    /**
     * Retorna coordenadas do centro geométrico
     */
    private function detectCenter(int $width, int $height): array
    {
        return [
            'x' => (int)($width / 2),
            'y' => (int)($height / 2),
        ];
    }

    /**
     * Detecta rosto via análise de cores de pele (heurística simples)
     * Não usa OpenCV/ML; analisa regiões com tons de pele típicos
     */
    private function detectFace(string $imagePath, int $width, int $height): array
    {
        $image = $this->loadImage($imagePath);
        if ($image === null) {
            return $this->detectCenter($width, $height);
        }

        // Amostra a imagem em grid 10x10 para performance
        $gridSize = 10;
        $cellW = (int)($width / $gridSize);
        $cellH = (int)($height / $gridSize);
        $skinPixels = [];

        for ($gy = 0; $gy < $gridSize; $gy++) {
            for ($gx = 0; $gx < $gridSize; $gx++) {
                $sampleX = (int)($gx * $cellW + $cellW / 2);
                $sampleY = (int)($gy * $cellH + $cellH / 2);

                if ($sampleX >= $width || $sampleY >= $height) {
                    continue;
                }

                $rgb = imagecolorat($image, $sampleX, $sampleY);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($this->isSkinTone($r, $g, $b)) {
                    $skinPixels[] = ['x' => $sampleX, 'y' => $sampleY];
                }
            }
        }


        if ($skinPixels === []) {
            return $this->detectCenter($width, $height);
        }

        // Calcula centroide dos pixels de pele detectados
        $sumX = array_sum(array_column($skinPixels, 'x'));
        $sumY = array_sum(array_column($skinPixels, 'y'));
        $count = count($skinPixels);

        return [
            'x' => (int)($sumX / $count),
            'y' => (int)($sumY / $count),
        ];
    }

    /**
     * Detecta região de maior entropia (complexidade visual)
     * Útil para paisagens, produtos, cenas sem rosto
     */
    private function detectEntropy(string $imagePath, int $width, int $height): array
    {
        $image = $this->loadImage($imagePath);
        if ($image === null) {
            return $this->detectCenter($width, $height);
        }

        // Divide imagem em blocos e calcula variância de cores
        $blockSize = 32;
        $maxVariance = 0;
        $bestBlock = ['x' => (int)($width / 2), 'y' => (int)($height / 2)];

        for ($y = 0; $y < $height - $blockSize; $y += $blockSize) {
            for ($x = 0; $x < $width - $blockSize; $x += $blockSize) {
                $variance = $this->calculateBlockVariance($image, $x, $y, $blockSize);

                if ($variance > $maxVariance) {
                    $maxVariance = $variance;
                    $bestBlock = [
                        'x' => (int)($x + $blockSize / 2),
                        'y' => (int)($y + $blockSize / 2),
                    ];
                }
            }
        }


        return $bestBlock;
    }

    /**
     * Retorna coordenadas customizadas definidas via setCustomPoint()
     */
    private function detectCustom(int $width, int $height): array
    {
        if ($this->customX === null || $this->customY === null) {
            return $this->detectCenter($width, $height);
        }

        return [
            'x' => (int)($width * $this->customX / 100),
            'y' => (int)($height * $this->customY / 100),
        ];
    }

    /**
     * Carrega imagem como recurso GD
     */
    private function loadImage(string $imagePath): ?GdImage
    {
        $mime = mime_content_type($imagePath);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($imagePath) ?: null,
            'image/png' => @imagecreatefrompng($imagePath) ?: null,
            'image/webp' => @imagecreatefromwebp($imagePath) ?: null,
            'image/avif' => @imagecreatefromavif($imagePath) ?: null,
            default => null,
        };
    }

    /**
     * Verifica se cor RGB corresponde a tom de pele humano
     * Heurística baseada em intervalos HSL típicos
     */
    private function isSkinTone(int $r, int $g, int $b): bool
    {
        // Converte RGB para HSL simplificado
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        if ($delta === 0) {
            return false; // Grayscale
        }

        // Tons de pele típicos: R alto, G médio, B baixo
        if ($r < 95 || $g < 40 || $b < 20) {
            return false;
        }

        if ($max - $min < 15) {
            return false; // Pouca saturação
        }

        if (abs($r - $g) < 15 && $b > $g) {
            return false; // Azul dominante
        }

        // Verifica intervalo típico de pele
        return $r > $g && $g > $b && ($r - $g) > 10;
    }

    /**
     * Calcula variância de cores em um bloco da imagem
     */
    private function calculateBlockVariance(GdImage $image, int $startX, int $startY, int $blockSize): float
    {
        $pixels = [];

        for ($y = $startY; $y < $startY + $blockSize; $y += 4) {
            for ($x = $startX; $x < $startX + $blockSize; $x += 4) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Luminância (percepção humana)
                $pixels[] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }

        if ($pixels === []) {
            return 0.0;
        }

        $mean = array_sum($pixels) / count($pixels);
        $variance = 0.0;

        foreach ($pixels as $pixel) {
            $variance += ($pixel - $mean) ** 2;
        }

        return $variance / count($pixels);
    }
}