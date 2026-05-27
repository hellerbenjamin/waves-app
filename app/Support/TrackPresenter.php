<?php

namespace App\Support;

use App\Models\Track;
use App\Models\TrackChannel;
use App\Services\TrackStorage;
use Closure;

/**
 * Shapes a Track into the props the Tracks/Show page expects. Owner, per-track
 * share, event-share, and profile-share views all render the same page and
 * differ only in how each channel's stream/peaks URL is built — owner pages
 * presign object URLs directly (CORS-clean, baked into the page), shared
 * pages route through token-scoped app endpoints that 404 the moment the
 * share is revoked.
 */
class TrackPresenter
{
    public function __construct(private TrackStorage $storage) {}

    /**
     * @param  Closure(TrackChannel): string  $channelStreamRoute  local-route fallback for the stream URL of a given channel
     * @param  Closure(TrackChannel): string  $channelPeaksRoute   local-route fallback for the peaks URL of a given channel
     * @return array<string, mixed>
     */
    public function show(Track $track, Closure $channelStreamRoute, Closure $channelPeaksRoute, bool $shared): array
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
            // "Ready" once the transcode job has populated per-channel rows.
            'ready' => $track->channels()->exists(),
            'channels' => $track->channels->map(fn (TrackChannel $c) => [
                'index' => $c->channel_index,
                'label' => $c->label,
                'stream_url' => $this->storage->channelStreamUrl($c, $channelStreamRoute($c), $shared),
                'peaks_url' => $this->storage->channelPeaksUrl($c, $channelPeaksRoute($c), $shared),
            ])->all(),
            'stream_cross_origin' => $this->storage->streamCrossOrigin(),
            'created_at' => $track->created_at?->toIso8601String(),
        ];
    }
}
