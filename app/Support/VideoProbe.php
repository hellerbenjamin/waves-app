<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Thin ffprobe wrapper for reading a video's display dimensions and duration.
 * The input may be a local path or a URL; probing a faststart MP4 over HTTP
 * only reads the moov atom, so it stays cheap even against a remote rendition.
 */
class VideoProbe
{
    /**
     * @return array{width?: int, height?: int, duration?: int}
     */
    public static function dimensions(string $input): array
    {
        $process = new Process([
            'ffprobe', '-v', 'quiet',
            '-print_format', 'json',
            '-show_format', '-show_streams',
            $input,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);
        if (! is_array($data)) {
            return [];
        }

        $video = null;
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
                break;
            }
        }

        return array_filter([
            'width' => isset($video['width']) ? (int) $video['width'] : null,
            'height' => isset($video['height']) ? (int) $video['height'] : null,
            'duration' => isset($data['format']['duration'])
                ? (int) round((float) $data['format']['duration'])
                : null,
        ], fn ($v) => $v !== null);
    }
}
