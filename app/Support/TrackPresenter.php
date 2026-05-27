<?php

namespace App\Support;

use App\Models\Track;
use App\Services\TrackStorage;

/**
 * Shapes a Track into the props the Tracks/Show page expects. The owner view,
 * the per-track public share, and the event/profile-token-scoped track view all
 * render the same page; they differ only in the stream/peaks URLs each one
 * points at.
 */
class TrackPresenter
{
    public function __construct(private TrackStorage $storage) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Track $track, string $streamUrl, string $peaksRoute, bool $shared): array
    {
        return [
            'id' => $track->id,
            'name' => $track->original_name,
            'size' => $track->size,
            'mime' => $track->mime,
            'duration_seconds' => $track->duration_seconds,
            'channels_count' => (int) $track->channels_count,
            'sample_rate' => (int) $track->sample_rate,
            'channel_labels' => $track->channel_labels,
            // Saved mixer state both views initialise to. Shared viewers see it
            // applied but can't save changes back (the update route is auth'd).
            'default_mix' => $track->default_mix,
            'peaks_ready' => (bool) $track->peaks_ready,
            // Peaks JSON lives in object storage; the mixer fetches it via this
            // URL instead of receiving it inline in the Inertia payload.
            'peaks_url' => $this->storage->peaksUrl($track, $peaksRoute, $shared),
            'created_at' => $track->created_at?->toIso8601String(),
            'stream_url' => $this->storage->playbackUrl($track, $streamUrl, $shared),
            // How the player must load the stream so it stays CORS-clean for
            // the per-channel mixer: 'anonymous' for presigned S3, else creds.
            'stream_cross_origin' => $this->storage->streamCrossOrigin(),
        ];
    }
}
