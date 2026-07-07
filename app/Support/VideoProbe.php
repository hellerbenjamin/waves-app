<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Thin ffprobe wrapper for reading a video's dimensions, duration and rotation.
 * The input may be a local path or a URL; probing a faststart MP4 over HTTP
 * only reads the moov atom, so it stays cheap even against a remote rendition.
 */
class VideoProbe
{
    /**
     * Coded dimensions, duration and rotation metadata for a video.
     *
     * `rotation` is the display-matrix rotation (or legacy rotate tag) in
     * degrees, or null when the file carries no orientation metadata at all —
     * the tell-tale of footage from a dedicated camera held sideways.
     *
     * @return array{width?: int, height?: int, duration?: int, rotation?: int|null}
     */
    public static function inspect(string $input): array
    {
        $process = new Process([
            'ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_streams', '-show_format', '-of', 'json', $input,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);
        $stream = $data['streams'][0] ?? null;
        if (! is_array($stream)) {
            return [];
        }

        $rotation = null;
        foreach ($stream['side_data_list'] ?? [] as $sideData) {
            if (isset($sideData['rotation'])) {
                $rotation = (int) $sideData['rotation'];
                break;
            }
        }
        if ($rotation === null && isset($stream['tags']['rotate'])) {
            $rotation = (int) $stream['tags']['rotate'];
        }

        $duration = $stream['duration'] ?? $data['format']['duration'] ?? null;

        return array_filter([
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
            'duration' => $duration !== null ? (int) round((float) $duration) : null,
            'rotation' => $rotation,
        ], fn ($v) => $v !== null);
    }

    /**
     * Display dimensions and duration only.
     *
     * @return array{width?: int, height?: int, duration?: int}
     */
    public static function dimensions(string $input): array
    {
        $info = self::inspect($input);
        unset($info['rotation']);

        return $info;
    }
}
