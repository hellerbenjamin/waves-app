<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Media;
use App\Models\Track;
use Closure;

/**
 * Describes how an event's tracks/media should be linked for a given audience.
 * The owner view bakes in presigned S3 URLs (revocable=false) and shows
 * owner-only sections; a public share view forces the revocable in-app routes
 * and hides those sections. Each closure resolves the per-item stream/thumb URL,
 * which is the only thing that differs between event-token and profile-token
 * sharing.
 */
class EventLinkContext
{
    /**
     * @param  Closure(Track): string  $trackShow
     * @param  Closure(Media): string  $mediaStream
     * @param  Closure(Media): string  $mediaThumb
     * @param  Closure(Media): string  $mediaDownload
     * @param  array<string, string>|null  $mediaUploadRoutes  Route URLs the
     *         browser-driven upload composable needs (uploadUrl, multipart-*,
     *         cleanup, store). Null when the audience can't upload.
     */
    public function __construct(
        public readonly bool $shared,
        public readonly Closure $trackShow,
        public readonly Closure $mediaStream,
        public readonly Closure $mediaThumb,
        public readonly Closure $mediaDownload,
        public readonly ?string $eventShareUrl,
        public readonly ?array $mediaUploadRoutes = null,
        public readonly ?string $mediaDownloadAllUrl = null,
    ) {}

    /** Owner-facing: cookie-auth in-app routes, presigned S3, all sections visible. */
    public static function owner(Event $event): self
    {
        return new self(
            shared: false,
            trackShow: fn (Track $t) => route('tracks.show', $t->id),
            mediaStream: fn (Media $m) => route('media.stream', $m->id),
            mediaThumb: fn (Media $m) => route('media.thumb', $m->id),
            mediaDownload: fn (Media $m) => route('media.download', $m->id),
            eventShareUrl: $event->share_token ? route('events.shared', $event->share_token) : null,
            mediaDownloadAllUrl: route('events.media.download-all', $event->id),
            mediaUploadRoutes: [
                'uploadUrl' => route('media.upload-url'),
                'multipartCreate' => route('media.multipart.create'),
                'multipartSign' => route('media.multipart.sign'),
                'multipartComplete' => route('media.multipart.complete'),
                'multipartAbort' => route('media.multipart.abort'),
                'cleanup' => route('media.cleanup'),
                'store' => route('media.store'),
            ],
        );
    }

    /** Public event-token share: items stream through the event's own token. */
    public static function eventShare(Event $event): self
    {
        $t = $event->share_token;

        return new self(
            shared: true,
            trackShow: fn (Track $t2) => route('events.shared.track-show', [$t, $t2->id]),
            mediaStream: fn (Media $m) => route('events.shared.media-stream', [$t, $m->id]),
            mediaThumb: fn (Media $m) => route('events.shared.media-thumb', [$t, $m->id]),
            mediaDownload: fn (Media $m) => route('events.shared.media-download', [$t, $m->id]),
            eventShareUrl: route('events.shared', $t),
            mediaDownloadAllUrl: route('events.shared.media.download-all', $t),
            // Public share viewers can upload photos/videos through the same
            // token — view and contribute live on one link.
            mediaUploadRoutes: [
                'uploadUrl' => route('events.shared.media-upload-url', $t),
                'multipartCreate' => route('events.shared.media-multipart-create', $t),
                'multipartSign' => route('events.shared.media-multipart-sign', $t),
                'multipartComplete' => route('events.shared.media-multipart-complete', $t),
                'multipartAbort' => route('events.shared.media-multipart-abort', $t),
                'cleanup' => route('events.shared.media-cleanup', $t),
                'store' => route('events.shared.media-store', $t),
            ],
        );
    }

    /**
     * Public profile-token share: items stream through the owner's profile token
     * scoped to this event. The event's own token is never exposed here.
     */
    public static function profileShare(string $userToken, Event $event): self
    {
        return new self(
            shared: true,
            trackShow: fn (Track $t) => route('profile.shared.track-show', [$userToken, $event->id, $t->id]),
            mediaStream: fn (Media $m) => route('profile.shared.media-stream', [$userToken, $event->id, $m->id]),
            mediaThumb: fn (Media $m) => route('profile.shared.media-thumb', [$userToken, $event->id, $m->id]),
            mediaDownload: fn (Media $m) => route('profile.shared.media-download', [$userToken, $event->id, $m->id]),
            eventShareUrl: null,
            mediaDownloadAllUrl: route('profile.shared.media.download-all', [$userToken, $event->id]),
        );
    }
}
