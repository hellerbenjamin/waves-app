<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaStorage;
use App\Support\VideoProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

/**
 * Produces a downscaled, faststart H.264/AAC web rendition of an uploaded
 * video so playback streams smoothly instead of pushing the raw camera
 * original at the viewer. The original is left untouched for downloads; the
 * rendition is stored beside it and recorded on `playback_key`.
 *
 * The whole original is pulled down to a local temp file first, then encoded
 * from disk — a full transcode reads the entire file, so relying on a
 * long-lived remote read held open for the encode's duration is fragile. The
 * poster frame is grabbed from the finished (faststart, local) rendition,
 * which makes it an instant seek with no remote-read timeout risk.
 */
class TranscodeVideo implements ShouldQueue
{
    use Queueable;

    /** Longest edge of the web rendition, in pixels (720p-class box). */
    private const int MAX_WIDTH = 1280;

    private const int MAX_HEIGHT = 720;

    /** Longest edge of the poster JPEG, in pixels. */
    private const int THUMB_EDGE = 800;

    public int $timeout = 1800;

    public int $tries = 2;

    public function __construct(public Media $media) {}

    public function handle(MediaStorage $storage): void
    {
        if ($this->media->kind !== 'video') {
            return;
        }

        $source = $storage->downloadToTemp($this->media->s3_key);
        // ffmpeg needs the extension to infer the output muxer; the extra file
        // (tempnam creates one without it) is cleaned up in the finally block.
        $rendition = tempnam(sys_get_temp_dir(), 'waves_web_').'.mp4';
        $poster = tempnam(sys_get_temp_dir(), 'waves_vthumb_').'.jpg';

        try {
            if (! $this->encode($source, $rendition)) {
                return; // Leave playback_key null; serving falls back to the original.
            }

            // Probe the rendition, not the source: ffmpeg's default autorotate
            // has already baked in any phone-orientation matrix, so the
            // rendition's coded dimensions are the true display dimensions — a
            // portrait clip shot on a sideways-held phone lands as portrait here.
            $meta = VideoProbe::dimensions($rendition);

            $playbackKey = $storage->playbackKeyFor($this->media->s3_key);
            $handle = fopen($rendition, 'rb');
            $storage->put($playbackKey, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            $update = [
                'playback_key' => $playbackKey,
                'width' => $meta['width'] ?? $this->media->width,
                'height' => $meta['height'] ?? $this->media->height,
                'duration' => $meta['duration'] ?? $this->media->duration,
            ];

            if ($this->poster($rendition, $poster)) {
                $thumbKey = $storage->thumbKeyFor($this->media->s3_key);
                $storage->putContents($thumbKey, (string) file_get_contents($poster));
                $update['thumb_key'] = $thumbKey;
            }

            $this->media->update($update);
        } finally {
            @unlink($source);
            @unlink($rendition);
            @unlink($poster);
        }
    }

    /** Encode the local source into a downscaled, faststart web MP4. */
    private function encode(string $input, string $output): bool
    {
        $process = new Process([
            'ffmpeg', '-y', '-nostdin',
            '-i', $input,
            // Fit within the 720p box preserving aspect, never upscaling; even
            // dimensions are required by H.264.
            '-vf', 'scale='.self::MAX_WIDTH.':'.self::MAX_HEIGHT
                .':force_original_aspect_ratio=decrease:force_divisible_by=2',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-pix_fmt', 'yuv420p', // normalise 10-bit / HEVC footage to something every browser plays
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart', // moov atom up front so playback starts immediately
            $output,
        ]);
        $process->setTimeout(1500);
        $process->run();

        return $process->isSuccessful() && file_exists($output) && filesize($output) > 0;
    }

    /** Pull a poster JPEG from the finished rendition (local, instant seek). */
    private function poster(string $rendition, string $output): bool
    {
        $process = new Process([
            'ffmpeg', '-y', '-nostdin',
            '-ss', '00:00:01',
            '-i', $rendition,
            '-vframes', '1',
            '-vf', 'scale='.self::THUMB_EDGE.':-2',
            $output,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() && file_exists($output) && filesize($output) > 0;
    }
}
