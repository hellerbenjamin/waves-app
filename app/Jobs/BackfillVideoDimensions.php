<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaStorage;
use App\Support\VideoProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Corrects the stored width/height/duration of an already-transcoded video by
 * re-reading its web rendition. Renditions have always been autorotated to
 * upright, but videos transcoded before the metadata was read from the
 * rendition kept the source's coded (pre-rotation) dimensions — so a portrait
 * clip was recorded as landscape. This refreshes those without re-encoding.
 */
class BackfillVideoDimensions implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public Media $media) {}

    public function handle(MediaStorage $storage): void
    {
        if ($this->media->kind !== 'video' || ! $this->media->playback_key) {
            return; // No rendition to read; a full transcode is needed instead.
        }

        // Renditions are small and faststart, so pulling one down to probe it
        // locally is cheap and avoids depending on a signed-URL read.
        $tmp = $storage->downloadToTemp($this->media->playback_key);

        try {
            $meta = VideoProbe::dimensions($tmp);

            $update = array_filter([
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'duration' => $meta['duration'] ?? null,
            ], fn ($v) => $v !== null);

            if ($update !== []) {
                $this->media->update($update);
            }
        } finally {
            @unlink($tmp);
        }
    }
}
