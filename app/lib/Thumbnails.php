<?php
declare(strict_types=1);

namespace BlaCloud;

/** Small preview images for photos, made with GD (or Imagick when available) and cached on disk. */
final class Thumbnails
{
    public const SIZES = [64, 256, 1024];
    private const MAX_PIXELS = 50_000_000; // skip gigantic images to protect memory on small hosts

    public function __construct(private Storage $fs)
    {
    }

    public static function supports(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $gd = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        if (in_array($ext, $gd, true) && extension_loaded('gd')) {
            return true;
        }
        return in_array($ext, array_merge($gd, ['heic', 'heif', 'avif', 'tif', 'tiff']), true) && extension_loaded('imagick');
    }

    /** Returns [absolute path to cached thumbnail, mime type]. Generates it on first request. */
    public function get(string $rel, int $size): array
    {
        $size = in_array($size, self::SIZES, true) ? $size : 256;
        $abs = $this->fs->abs($rel);
        if (!is_file($abs) || !self::supports($abs)) {
            throw new StorageException('No preview for this file.', 404);
        }
        $webp = function_exists('imagewebp');
        $mime = $webp ? 'image/webp' : 'image/jpeg';
        $key = hash('sha256', $rel . '|' . filemtime($abs) . '|' . filesize($abs) . '|' . $size);
        $out = $this->fs->internalDir('thumbs') . '/' . substr($key, 0, 2);
        if (!is_dir($out)) {
            @mkdir($out, 0750, true);
        }
        $out .= '/' . $key . ($webp ? '.webp' : '.jpg');
        if (is_file($out)) {
            @touch($out); // mark as recently used
            return [$out, $mime];
        }
        $source = Encryption::resolvePlaintext($abs);
        $ok = extension_loaded('imagick') ? $this->withImagick($source, $out, $size, $webp) : false;
        if (!$ok) {
            $ok = $this->withGd($source, $out, $size, $webp);
        }
        if (!$ok || !is_file($out)) {
            throw new StorageException('Could not make a preview for this image.', 415);
        }
        return [$out, $mime];
    }

    private function withGd(string $abs, string $out, int $size, bool $webp): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        $info = @getimagesize($abs);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return false;
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
            IMAGETYPE_PNG  => @imagecreatefrompng($abs),
            IMAGETYPE_GIF  => @imagecreatefromgif($abs),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
            IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($abs) : false,
            default => false,
        };
        if (!$src) {
            return false;
        }
        // Respect the camera's rotation flag on phone photos.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($abs);
            $src = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($src, 180, 0),
                6 => imagerotate($src, -90, 0),
                8 => imagerotate($src, 90, 0),
                default => $src,
            };
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $size / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        if (!$webp) {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 15, 15, 26)); // brand surface behind transparency
            imagealphablending($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $tmp = $out . '.tmp';
        $ok = $webp ? imagewebp($dst, $tmp, 80) : imagejpeg($dst, $tmp, 82);
        return $ok && rename($tmp, $out);
    }

    private function withImagick(string $abs, string $out, int $size, bool $webp): bool
    {
        try {
            $im = new \Imagick();
            $im->pingImage($abs);
            if ($im->getImageWidth() * $im->getImageHeight() > self::MAX_PIXELS) {
                return false;
            }
            $im = new \Imagick($abs . '[0]');
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $im->thumbnailImage($size, $size, true);
            $im->stripImage();
            $im->setImageFormat($webp ? 'webp' : 'jpeg');
            $im->setImageCompressionQuality(80);
            $tmp = $out . '.tmp';
            $im->writeImage($tmp);
            return rename($tmp, $out);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Delete cached thumbnails that haven't been viewed for a while. */
    public function cleanup(int $days = 45): void
    {
        $dir = $this->fs->internalDir('thumbs');
        $cut = time() - $days * 86400;
        foreach (glob($dir . '/*/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }
}
