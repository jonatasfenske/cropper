<?php

declare(strict_types=1);

namespace JonatasFenske\Cropper;

use Exception;
use GdImage;
use Imagick;

/**
 * Cropper de imagens e cache de thumbnails com suporte dual GD/Imagick
 * PHP 8.5: detecção automática de engine, tipagem estrita, readonly properties
 */
class Cropper
{
 use ImageProcessor;

 private readonly string $cachePath;
 private readonly int $quality;
 private readonly int $compressor;
 private readonly bool $webP;
 private readonly ImageEngine $engine;

 private string $imagePath = '';
 private string $imageName = '';
 private string $imageMime = '';

 /** @var array<string> Tipos MIME permitidos para crop */
 private static array $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

 /**
 * @param string $cachePath Diretório de cache para thumbnails
 * @param int $quality Qualidade JPEG/WebP (1-100)
 * @param int $compressor Compressão PNG (1-9)
 * @param bool $webP Se true, gera thumbnails em formato WebP
 * @param ImageEngine $engine Engine de processamento (AUTO detecta a melhor disponível)
 * @throws Exception
 */
 public function __construct(
 string $cachePath,
 int $quality = 75,
 int $compressor = 5,
 bool $webP = false,
 ImageEngine $engine = ImageEngine::AUTO,
 ) {
 $this->cachePath = $cachePath;
 $this->quality = max(1, min(100, $quality));
 $this->compressor = max(1, min(9, $compressor));
 $this->webP = $webP;

 try {
 $this->engine = $engine->resolve();
 } catch (\RuntimeException $e) {
 throw new Exception($e->getMessage());
 }

 if (!file_exists($this->cachePath) || !is_dir($this->cachePath)) {
 if (!mkdir($this->cachePath, 0755, true)) {
 throw new Exception("Não foi possível criar o diretório de cache: {$cachePath}");
 }
 }
 }

 /**
 * Retorna a engine de imagem ativa nesta instância
 */
 public function getEngine(): ImageEngine
 {
 return $this->engine;
 }

	/**
	 * Retorna o caminho do diretório de cache
	 */
	public function getCachePath(): string
	{
		return $this->cachePath;
	}

	/**
	 * Retorna a qualidade configurada (1-100)
	 */
	public function getQuality(): int
	{
		return $this->quality;
	}

	/**
	 * Retorna o nível de compressão PNG configurado (1-9)
	 */
	public function getCompressor(): int
	{
		return $this->compressor;
	}

	/**
	 * Retorna se a conversão WebP está ativada
	 */
	public function isWebPEnabled(): bool
	{
		return $this->webP;
	}

 /**
 * Gera thumbnail com crop inteligente
 *
 * @param string $imagePath Caminho da imagem original
 * @param int $width Largura desejada
 * @param int|null $height Altura desejada (null = proporcional)
 * @return string|null Caminho da thumbnail gerada ou null em caso de erro
 */
 public function make(string $imagePath, int $width, ?int $height = null): ?string
 {
 if (!file_exists($imagePath)) {
 return null;
 }

 $this->imagePath = $imagePath;
 $this->imageName = $this->buildCacheName($imagePath, $width, $height);
 $this->imageMime = mime_content_type($imagePath) ?: '';

 if (!in_array($this->imageMime, self::$allowedMimes, true)) {
 return null;
 }

 return $this->resolveCachedImage($width, $height);
 }

 /**
 * Verifica cache e retorna caminho da thumbnail existente ou gera nova
 */
 private function resolveCachedImage(int $width, ?int $height): ?string
 {
 $webPCache = "{$this->cachePath}/{$this->imageName}.webp";
 $ext = pathinfo($this->imagePath)['extension'];
 $originalCache = "{$this->cachePath}/{$this->imageName}.{$ext}";

 if ($this->webP && file_exists($webPCache) && is_file($webPCache)) {
 return $webPCache;
 }

 if (!$this->webP && file_exists($originalCache) && is_file($originalCache)) {
 return $originalCache;
 }

 return match ($this->engine) {
 ImageEngine::IMAGICK => $this->generateWithImagick($width, $height),
 default => $this->generateWithGd($width, $height),
 };
 }

 /**
 * Gera thumbnail usando GD
 */
 private function generateWithGd(int $width, ?int $height): ?string
 {
 $imageSize = getimagesize($this->imagePath);
 if ($imageSize === false) {
 return null;
 }

 [$srcW, $srcH] = $imageSize;
 $targetH = $height ?? (int)round(($width * $srcH) / max(1, $srcW));

 if ($height !== null) {
 $crop = $this->calculateCropRegion($srcW, $srcH, $width, $targetH);
 return $this->gdCropAndResize($width, $targetH, $crop);
 }

 return $this->gdResize($width, $targetH, $srcW, $srcH);
 }

 /**
 * Redimensiona com GD mantendo proporção
 */
 private function gdResize(int $newW, int $newH, int $srcW, int $srcH): ?string
 {
 $source = $this->gdLoadSource();
 if ($source === null) {
 return null;
 }

 $thumb = imagecreatetruecolor($newW, $newH);
 $this->gdPreserveTransparency($thumb);
 imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

 $outputPath = $this->gdSaveOutput($thumb);


 if ($this->webP && $outputPath !== null) {
 return $this->convertToWebP($outputPath);
 }

 return $outputPath;
 }

 /**
 * Crop e redimensiona com GD
 */
 private function gdCropAndResize(int $targetW, int $targetH, array $crop): ?string
 {
 $source = $this->gdLoadSource();
 if ($source === null) {
 return null;
 }

 $thumb = imagecreatetruecolor($targetW, $targetH);
 $this->gdPreserveTransparency($thumb);
 imagecopyresampled($thumb, $source, 0, 0, $crop['x'], $crop['y'], $targetW, $targetH, $crop['w'], $crop['h']);

 $outputPath = $this->gdSaveOutput($thumb);


 if ($this->webP && $outputPath !== null) {
 return $this->convertToWebP($outputPath);
 }

 return $outputPath;
 }

 /**
 * Carrega imagem fonte via GD conforme MIME type
 */
 private function gdLoadSource(): ?GdImage
 {
 return match ($this->imageMime) {
 'image/jpeg' => @imagecreatefromjpeg($this->imagePath) ?: null,
 'image/png' => @imagecreatefrompng($this->imagePath) ?: null,
 'image/gif' => @imagecreatefromgif($this->imagePath) ?: null,
 'image/webp' => @imagecreatefromwebp($this->imagePath) ?: null,
 default => null,
 };
 }

 /**
 * Preserva transparência para PNG/GIF em recurso GD
 */
 private function gdPreserveTransparency(GdImage $image): void
 {
 if (in_array($this->imageMime, ['image/png', 'image/gif'], true)) {
 imagealphablending($image, false);
 imagesavealpha($image, true);
 }
 }

 /**
 * Salva output GD no formato apropriado
 */
 private function gdSaveOutput(GdImage $thumb): ?string
 {
 $ext = match ($this->imageMime) {
 'image/jpeg' => 'jpg',
 'image/png' => 'png',
 'image/gif' => 'gif',
 'image/webp' => 'webp',
 default => 'jpg',
 };

 $outputPath = "{$this->cachePath}/{$this->imageName}.{$ext}";

 $result = match ($ext) {
 'jpg' => imagejpeg($thumb, $outputPath, $this->quality),
 'png' => imagepng($thumb, $outputPath, $this->compressor),
 'gif' => imagegif($thumb, $outputPath),
 'webp' => imagewebp($thumb, $outputPath, $this->quality),
 };

 return $result ? $outputPath : null;
 }

 /**
 * Gera thumbnail usando Imagick
 */
 private function generateWithImagick(int $width, ?int $height): ?string
 {
 try {
 $imagick = new Imagick($this->imagePath);
 } catch (\ImagickException) {
 return null;
 }

 $srcW = $imagick->getImageWidth();
 $srcH = $imagick->getImageHeight();
 $targetH = $height ?? (int)round(($width * $srcH) / max(1, $srcW));

 if ($height !== null) {
 $crop = $this->calculateCropRegion($srcW, $srcH, $width, $targetH);
 $imagick->cropImage($crop['w'], $crop['h'], $crop['x'], $crop['y']);
 $imagick->setImagePage($width, $targetH, 0, 0);
 }

 $imagick->thumbnailImage($width, $targetH, bestfit: true, fill: false);

 $outputPath = $this->imagickSaveOutput($imagick);
 $imagick->destroy();

 if ($this->webP && $outputPath !== null) {
 return $this->convertToWebP($outputPath);
 }

 return $outputPath;
 }

 /**
 * Salva output Imagick no formato apropriado
 */
 private function imagickSaveOutput(Imagick $imagick): ?string
 {
 $format = match ($this->imageMime) {
 'image/jpeg' => 'JPEG',
 'image/png' => 'PNG',
 'image/gif' => 'GIF',
 'image/webp' => 'WEBP',
 default => 'JPEG',
 };

 $ext = strtolower($format);
 if ($ext === 'jpeg') {
 $ext = 'jpg';
 }

 $outputPath = "{$this->cachePath}/{$this->imageName}.{$ext}";

 $imagick->setImageFormat($format);

 if ($format === 'JPEG') {
 $imagick->setImageCompressionQuality($this->quality);
 } elseif ($format === 'PNG') {
 $imagick->setImageCompressionQuality($this->compressor * 10);
 } elseif ($format === 'WEBP') {
 $imagick->setImageCompressionQuality($this->quality);
 }

 try {
 $imagick->writeImage($outputPath);
 return $outputPath;
 } catch (\ImagickException) {
 return null;
 }
 }

 /**
 * Converte imagem para WebP usando a engine ativa
 *
 * @param string $imagePath Caminho da imagem de origem
 * @param bool $removeOriginal Se true, remove a imagem original após conversão
 * @return string Caminho da imagem WebP gerada
 */
 public function convertToWebP(string $imagePath, bool $removeOriginal = true): string
 {
 $webPPath = pathinfo($imagePath, PATHINFO_DIRNAME) . '/' . pathinfo($imagePath, PATHINFO_FILENAME) . '.webp';

 if ($this->engine === ImageEngine::IMAGICK) {
 return $this->convertToWebPImagick($imagePath, $webPPath, $removeOriginal);
 }

 return $this->convertToWebPGd($imagePath, $webPPath, $removeOriginal);
 }

 /**
 * Conversão WebP via Imagick
 */
 private function convertToWebPImagick(string $imagePath, string $webPPath, bool $removeOriginal): string
 {
 try {
 $imagick = new Imagick($imagePath);
 $imagick->setImageFormat('WEBP');
 $imagick->setImageCompressionQuality($this->quality);
 $imagick->writeImage($webPPath);
 $imagick->destroy();

 if ($removeOriginal && $imagePath !== $webPPath && file_exists($imagePath)) {
 unlink($imagePath);
 }

 return $webPPath;
 } catch (\ImagickException) {
 return $imagePath;
 }
 }

 /**
 * Conversão WebP via GD nativo
 */
 private function convertToWebPGd(string $imagePath, string $webPPath, bool $removeOriginal): string
 {
 $mime = mime_content_type($imagePath);
 $image = match ($mime) {
 'image/jpeg' => @imagecreatefromjpeg($imagePath),
 'image/png' => @imagecreatefrompng($imagePath),
 'image/webp' => @imagecreatefromwebp($imagePath),
 'image/gif' => @imagecreatefromgif($imagePath),
 default => false,
 };

 if ($image instanceof GdImage) {
 imagewebp($image, $webPPath, $this->quality);

 if ($removeOriginal && $imagePath !== $webPPath && file_exists($imagePath)) {
 unlink($imagePath);
 }

 return $webPPath;
 }

 return $imagePath;
 }

 /**
 * Limpa cache de thumbnails
 *
 * @param string|null $imagePath Se informado, limpa apenas variações desta imagem; se null, limpa tudo
 */
 public function flush(?string $imagePath = null): void
 {
 $files = scandir($this->cachePath);
 if ($files === false) {
 return;
 }

 $targetHash = $imagePath !== null ? hash('crc32b', pathinfo($imagePath)['basename']) : null;

 foreach ($files as $file) {
 if ($file === '.' || $file === '..') {
 continue;
 }

 $fullPath = "{$this->cachePath}/{$file}";

 if (!is_file($fullPath)) {
 continue;
 }

 if ($targetHash !== null && !str_contains($file, $targetHash)) {
 continue;
 }

 unlink($fullPath);
 }
 }
}