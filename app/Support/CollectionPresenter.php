<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Track;
use App\Services\MediaStorage;
use App\Services\TrackStorage;

/**
 * Shapes a collection (and its tracks/media) into the props the Collections/Show
 * page expects. The owner view and the public share view render the same page;
 * they differ only in the links each item points at, which a
 * CollectionLinkContext supplies. Mirrors {@see EventPresenter} — the card
 * shapes are identical so the two Show pages can share markup.
 */
class CollectionPresenter
{
    public function __construct(
        private TrackStorage $tracks,
        private MediaStorage $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collection(Collection $collection, CollectionLinkContext $ctx): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'share_url' => $ctx->shareUrl,
            'media_download_all_url' => $ctx->mediaDownloadAllUrl,
            'tracks' => $collection->tracks->map(fn (Track $t) => $this->trackCard($t, $ctx))->all(),
            'media' => $collection->media->map(fn (Media $m) => $this->mediaCard($m, $ctx))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trackCard(Track $track, CollectionLinkContext $ctx): array
    {
        return [
            'id' => $track->id,
            'name' => $track->original_name,
            'duration_seconds' => $track->duration_seconds,
            'ready' => $track->channels()->exists(),
            'show_url' => ($ctx->trackShow)($track),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaCard(Media $media, CollectionLinkContext $ctx): array
    {
        return [
            'id' => $media->id,
            'name' => $media->original_name,
            'kind' => $media->kind,
            'mime' => $media->mime,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'url' => $this->media->objectUrl($media->playbackKey(), ($ctx->mediaStream)($media), $ctx->shared),
            'thumb_url' => $this->media->objectUrl($media->thumb_key, ($ctx->mediaThumb)($media), $ctx->shared),
            'download_url' => ($ctx->mediaDownload)($media),
            'share_url' => (! $ctx->shared && $media->share_token) ? route('media.shared', $media->share_token) : null,
            'contributor_name' => $media->contributor_name,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
