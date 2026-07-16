<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Track;
use Closure;

/**
 * Describes how a collection's tracks/media should be linked for a given
 * audience. The owner view bakes in presigned S3 URLs (revocable=false); the
 * public share view forces the revocable in-app routes bound to the collection
 * token. Mirrors {@see EventLinkContext}, minus the profile-share and upload
 * variants (a collection is curation-only for now).
 */
class CollectionLinkContext
{
    /**
     * @param  Closure(Track): string  $trackShow
     * @param  Closure(Media): string  $mediaStream
     * @param  Closure(Media): string  $mediaThumb
     * @param  Closure(Media): string  $mediaDownload
     */
    public function __construct(
        public readonly bool $shared,
        public readonly Closure $trackShow,
        public readonly Closure $mediaStream,
        public readonly Closure $mediaThumb,
        public readonly Closure $mediaDownload,
        public readonly ?string $shareUrl,
        public readonly ?string $mediaDownloadAllUrl = null,
    ) {}

    /** Owner-facing: cookie-auth in-app routes, presigned S3. */
    public static function owner(Collection $collection): self
    {
        return new self(
            shared: false,
            trackShow: fn (Track $t) => route('tracks.show', $t->id),
            mediaStream: fn (Media $m) => route('media.stream', $m->id),
            mediaThumb: fn (Media $m) => route('media.thumb', $m->id),
            mediaDownload: fn (Media $m) => route('media.download', $m->id),
            shareUrl: $collection->share_token ? route('collections.shared', $collection->share_token) : null,
            mediaDownloadAllUrl: route('collections.media.download-all', $collection->id),
        );
    }

    /** Public share: items stream through the collection's own token. */
    public static function collectionShare(Collection $collection): self
    {
        $t = $collection->share_token;

        return new self(
            shared: true,
            trackShow: fn (Track $t2) => route('collections.shared.track-show', [$t, $t2->id]),
            mediaStream: fn (Media $m) => route('collections.shared.media-stream', [$t, $m->id]),
            mediaThumb: fn (Media $m) => route('collections.shared.media-thumb', [$t, $m->id]),
            mediaDownload: fn (Media $m) => route('collections.shared.media-download', [$t, $m->id]),
            shareUrl: route('collections.shared', $t),
            mediaDownloadAllUrl: route('collections.shared.media.download-all', $t),
        );
    }
}
