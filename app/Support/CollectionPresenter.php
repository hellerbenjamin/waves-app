<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Media;
use App\Services\MediaStorage;

/**
 * Shapes a collection (and its media) into the props the Collections/Show page
 * expects. The owner view and the public share render the same page; they
 * differ only in the links each item points at, which a CollectionLinkContext
 * supplies. The media-only twin of {@see EventPresenter}.
 */
class CollectionPresenter
{
    public function __construct(private MediaStorage $media) {}

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
            'media' => $collection->media->map(fn (Media $m) => $this->mediaCard($m, $ctx))->all(),
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
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
