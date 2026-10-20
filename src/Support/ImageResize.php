<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Crop an image to a centred square and scale it down (0.28, avatars). Uses
 * PHP's GD extension when it is there; without it, or for an animated GIF,
 * the file is left as it is. Re-encoding also drops the photo's metadata
 * (EXIF location and the like).
 *
 * @internal
 */
final class ImageResize
{
    /** Rewrites $file in place; returns whether it was changed. */
    public static function square(string $file, int $size, string $mime): bool
    {
        if (!extension_loaded('gd') || !is_file($file) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { return false; }
        if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) { return false; }
        $info = @getimagesize($file);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) { return false; }
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png' => @imagecreatefrompng($file),
            default => @imagecreatefromwebp($file),
        };
        if ($source === false) { return false; }
        if ($mime === 'image/jpeg') { $source = self::orient($source, $file); }
        $width = imagesx($source); $height = imagesy($source);
        $side = min($width, $height);
        $target = min($size, $side);
        $canvas = imagecreatetruecolor($target, $target);
        if ($mime !== 'image/jpeg') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $target, $target, $side, $side);
        $written = match ($mime) {
            'image/jpeg' => imagejpeg($canvas, $file, 88),
            'image/png' => imagepng($canvas, $file, 6),
            default => imagewebp($canvas, $file, 86),
        };
        imagedestroy($canvas); imagedestroy($source);
        clearstatcache(true, $file);
        return $written;
    }

    /** A phone photo taken sideways: turn it the way the camera says. */
    private static function orient(\GdImage $image, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) { return $image; }
        $exif = @exif_read_data($file);
        $angle = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle === 0) { return $image; }
        $turned = imagerotate($image, $angle, 0);
        return $turned instanceof \GdImage ? $turned : $image;
    }
}
