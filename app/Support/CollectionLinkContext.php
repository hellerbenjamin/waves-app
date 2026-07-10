<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Media;
use Closure;

/**
 * Describes how a collection's media should be linked for a given audience.
 * The owner view bakes in presigned S3 URLs (revocable=false) and shows
 * owner-only actions; a public share view forces the revocable in-app routes
 * and hides them. Mirrors {@see EventLinkContext} but for media only — a
 * collection has no tracks, invites, or uploads-through-the-share.
 */
class CollectionLinkContext
{
    /**
     * @param  Closure(Media): string  $mediaStream
     * @param  Closure(Media): string  $mediaThumb
     * @param  Closure(Media): string  $mediaDownload
     */
    public function __construct(
        public readonly bool $shared,
        public readonly Closure $mediaStream,
        public readonly Closure $mediaThumb,
        public readonly Closure $mediaDownload,
        public readonly ?string $shareUrl,
        public readonly ?string $mediaDownloadAllUrl = null,
    ) {}

    /** Owner-facing: cookie-auth in-app routes, presigned S3, all actions visible. */
    public static function owner(Collection $collection): self
    {
        return new self(
            shared: false,
            mediaStream: fn (Media $m) => route('media.stream', $m->id),
            mediaThumb: fn (Media $m) => route('media.thumb', $m->id),
            mediaDownload: fn (Media $m) => route('media.download', $m->id),
            shareUrl: $collection->share_token ? route('collections.shared', $collection->share_token) : null,
            mediaDownloadAllUrl: route('collections.media.download-all', $collection->id),
        );
    }

    /** Public share: media streams through the collection's own token. */
    public static function share(Collection $collection): self
    {
        $t = $collection->share_token;

        return new self(
            shared: true,
            mediaStream: fn (Media $m) => route('collections.shared.media-stream', [$t, $m->id]),
            mediaThumb: fn (Media $m) => route('collections.shared.media-thumb', [$t, $m->id]),
            mediaDownload: fn (Media $m) => route('collections.shared.media-download', [$t, $m->id]),
            shareUrl: route('collections.shared', $t),
            mediaDownloadAllUrl: route('collections.shared.media.download-all', $t),
        );
    }
}
