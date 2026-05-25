<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Produces a downscaled JPEG thumbnail for an uploaded image and records its
 * pixel dimensions. Videos are skipped — poster-frame extraction needs ffmpeg,
 * which the worker doesn't have yet, so the UI falls back to an inline player.
 */
class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    /** Longest edge of the generated thumbnail, in pixels. */
    private const MAX_EDGE = 800;

    public int $timeout = 120;

    public function __construct(public Media $media) {}

    public function handle(MediaStorage $storage): void
    {
        if ($this->media->kind !== 'image') {
            return;
        }

        $bytes = $storage->get($this->media->s3_key);
        if ($bytes === null) {
            return;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return; // Not a format GD can decode; leave thumb_key null.
        }

        $source = $this->applyExifOrientation($source, $bytes);

        $width = imagesx($source);
        $height = imagesy($source);

        $thumb = $this->downscale($source, $width, $height);

        ob_start();
        imagejpeg($thumb, null, 80);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        if ($thumb !== $source) {
            imagedestroy($thumb);
        }

        $thumbKey = $storage->thumbKeyFor($this->media->s3_key);
        $storage->putContents($thumbKey, $jpeg);

        $this->media->update([
            'thumb_key' => $thumbKey,
            'width' => $width,
            'height' => $height,
        ]);
    }

    /**
     * Scale the image so its longest edge is at most MAX_EDGE, preserving
     * aspect ratio. Images already within bounds are returned untouched.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    private function downscale($source, int $width, int $height)
    {
        $longest = max($width, $height);
        if ($longest <= self::MAX_EDGE) {
            return $source;
        }

        $scale = self::MAX_EDGE / $longest;
        $scaled = imagescale($source, (int) round($width * $scale), (int) round($height * $scale));

        // GD can fail to scale (memory pressure, an awkward pixel format). Fall
        // back to the full-size source so we still emit a valid thumbnail rather
        // than crashing on imagedestroy(false) and writing empty bytes.
        return $scaled === false ? $source : $scaled;
    }

    /**
     * GD ignores EXIF orientation, so phone photos can come out sideways.
     * Best-effort rotate based on the JPEG's Orientation tag when the exif
     * extension is available.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, string $bytes)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = $exif['Orientation'] ?? null;

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image; // Rotation failed; use the image as-is.
        }

        imagedestroy($image);

        return $rotated;
    }
}
