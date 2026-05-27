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
     * @param  Closure(Track): string  $trackStream
     * @param  Closure(Media): string  $mediaStream
     * @param  Closure(Media): string  $mediaThumb
     * @param  Closure(Media): string  $mediaDownload
     */
    public function __construct(
        public readonly bool $shared,
        public readonly Closure $trackStream,
        public readonly Closure $mediaStream,
        public readonly Closure $mediaThumb,
        public readonly Closure $mediaDownload,
        public readonly ?string $eventShareUrl,
    ) {}

    /** Owner-facing: cookie-auth in-app routes, presigned S3, all sections visible. */
    public static function owner(Event $event): self
    {
        return new self(
            shared: false,
            trackStream: fn (Track $t) => route('tracks.stream', $t->id),
            mediaStream: fn (Media $m) => route('media.stream', $m->id),
            mediaThumb: fn (Media $m) => route('media.thumb', $m->id),
            mediaDownload: fn (Media $m) => route('media.download', $m->id),
            eventShareUrl: $event->share_token ? route('events.shared', $event->share_token) : null,
        );
    }

    /** Public event-token share: items stream through the event's own token. */
    public static function eventShare(Event $event): self
    {
        return new self(
            shared: true,
            trackStream: fn (Track $t) => route('events.shared.track-stream', [$event->share_token, $t->id]),
            mediaStream: fn (Media $m) => route('events.shared.media-stream', [$event->share_token, $m->id]),
            mediaThumb: fn (Media $m) => route('events.shared.media-thumb', [$event->share_token, $m->id]),
            mediaDownload: fn (Media $m) => route('events.shared.media-download', [$event->share_token, $m->id]),
            eventShareUrl: route('events.shared', $event->share_token),
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
            trackStream: fn (Track $t) => route('profile.shared.track-stream', [$userToken, $event->id, $t->id]),
            mediaStream: fn (Media $m) => route('profile.shared.media-stream', [$userToken, $event->id, $m->id]),
            mediaThumb: fn (Media $m) => route('profile.shared.media-thumb', [$userToken, $event->id, $m->id]),
            mediaDownload: fn (Media $m) => route('profile.shared.media-download', [$userToken, $event->id, $m->id]),
            eventShareUrl: null,
        );
    }
}
