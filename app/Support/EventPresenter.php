<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventInvite;
use App\Models\Media;
use App\Models\Track;
use App\Services\MediaStorage;
use App\Services\TrackStorage;

/**
 * Shapes an event (and its tracks/media) into the props the Events/Show page
 * expects. The owner view, the public event-token share, and the public
 * profile-token share all render the same page; they differ only in the links
 * each item points at, which an EventLinkContext supplies.
 */
class EventPresenter
{
    public function __construct(
        private TrackStorage $tracks,
        private MediaStorage $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function event(Event $event, EventLinkContext $ctx): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'type' => $event->type,
            'event_date' => $event->event_date?->toDateString(),
            'location' => $event->location,
            'description' => $event->description,
            'share_url' => $ctx->eventShareUrl,
            'tracks' => $event->tracks->map(fn (Track $t) => $this->trackCard($t, $ctx))->all(),
            'media' => $event->media->map(fn (Media $m) => $this->mediaCard($m, $ctx))->all(),
            // Contribution links are an owner-only concern; never on a public view.
            'invites' => $ctx->shared ? [] : $event->invites->map(fn (EventInvite $i) => [
                'id' => $i->id,
                'label' => $i->label,
                'url' => route('contribute.show', $i->token),
                'expires_at' => $i->expires_at?->toIso8601String(),
                'uploads_count' => $i->uploads_count,
                'active' => $i->isUsable(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trackCard(Track $track, EventLinkContext $ctx): array
    {
        return [
            'id' => $track->id,
            'name' => $track->original_name,
            'duration_seconds' => $track->duration_seconds,
            'peaks_ready' => $track->peaks_ready,
            'stream_url' => $this->tracks->playbackUrl($track, ($ctx->trackStream)($track), $ctx->shared),
            'stream_cross_origin' => $this->tracks->streamCrossOrigin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaCard(Media $media, EventLinkContext $ctx): array
    {
        return [
            'id' => $media->id,
            'name' => $media->original_name,
            'kind' => $media->kind,
            'mime' => $media->mime,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'url' => $this->media->objectUrl($media->s3_key, ($ctx->mediaStream)($media), $ctx->shared),
            'thumb_url' => $this->media->objectUrl($media->thumb_key, ($ctx->mediaThumb)($media), $ctx->shared),
            'download_url' => ($ctx->mediaDownload)($media),
            'share_url' => (! $ctx->shared && $media->share_token) ? route('media.shared', $media->share_token) : null,
            'contributor_name' => $media->contributor_name,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
