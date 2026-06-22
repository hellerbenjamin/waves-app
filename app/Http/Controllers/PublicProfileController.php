<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Media;
use App\Models\Track;
use App\Models\TrackChannel;
use App\Models\User;
use App\Services\MediaStorage;
use App\Services\TrackStorage;
use App\Support\EventLinkContext;
use App\Support\EventPresenter;
use App\Support\TrackPresenter;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The public, unauthenticated face of a user's `share_token`: one link that
 * lists all their events and streams each event's tracks/media — all authorized
 * by the single profile token, scoped to rows the token's owner owns. This never
 * touches per-event share tokens; it is an independent access path.
 */
class PublicProfileController extends Controller
{
    public function __construct(
        private TrackStorage $tracks,
        private MediaStorage $media,
        private EventPresenter $presenter,
        private TrackPresenter $trackPresenter,
    ) {}

    public function show(User $user): Response
    {
        $events = $user->events()
            ->withCount(['tracks', 'media'])
            ->orderByRaw('event_date is null, event_date desc')
            ->latest()
            ->get()
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type,
                'event_date' => $e->event_date?->toDateString(),
                'location' => $e->location,
                'tracks_count' => $e->tracks_count,
                'media_count' => $e->media_count,
            ]);

        return Inertia::render('Profile/Shared', [
            'name' => $user->name,
            'shareToken' => $user->share_token,
            'events' => $events,
            'types' => Event::TYPES,
        ]);
    }

    public function showEvent(User $user, Event $event): Response
    {
        abort_unless($event->user_id === $user->id, 404);

        $event->load(['tracks' => fn ($q) => $q->forCards()->latest(), 'media' => fn ($q) => $q->latest()]);

        return Inertia::render('Events/Show', [
            'canEdit' => false,
            'types' => Event::TYPES,
            'assignableTracks' => [],
            'event' => $this->presenter->event($event, EventLinkContext::profileShare($user->share_token, $event)),
        ]);
    }

    // --- Token-scoped public streaming -------------------------------------
    // Every item is double-checked: it must belong to this event, and the event
    // must belong to the token's owner.

    public function showTrack(User $user, Event $event, Track $track): Response
    {
        abort_unless($event->user_id === $user->id && $track->event_id === $event->id, 404);
        $track->load('channels');

        return Inertia::render('Tracks/Show', [
            'canEdit' => false,
            'templates' => [],
            'track' => $this->trackPresenter->show(
                $track,
                fn (TrackChannel $c) => route('profile.shared.channels.stream', [$user->share_token, $event->id, $track->id, $c->channel_index]),
                fn (TrackChannel $c) => route('profile.shared.channels.peaks', [$user->share_token, $event->id, $track->id, $c->channel_index]),
                shared: true,
            ),
        ]);
    }

    public function streamChannel(User $user, Event $event, Track $track, int $channel): SymfonyResponse
    {
        abort_unless($event->user_id === $user->id && $track->event_id === $event->id, 404);
        $row = $track->channels()->where('channel_index', $channel)->first();
        abort_unless($row !== null, 404);

        return $this->tracks->channelStreamResponse($row);
    }

    public function peaksChannel(User $user, Event $event, Track $track, int $channel): SymfonyResponse
    {
        abort_unless($event->user_id === $user->id && $track->event_id === $event->id, 404);
        $row = $track->channels()->where('channel_index', $channel)->first();
        abort_unless($row !== null, 404);

        return $this->tracks->channelPeaksResponse($row);
    }

    public function streamMedia(User $user, Event $event, Media $media): SymfonyResponse
    {
        abort_unless($event->user_id === $user->id && $media->event_id === $event->id, 404);

        return $this->media->streamResponse($media->s3_key, $media->mime);
    }

    public function thumbMedia(User $user, Event $event, Media $media): SymfonyResponse
    {
        abort_unless($event->user_id === $user->id && $media->event_id === $event->id && $media->thumb_key, 404);

        return $this->media->streamResponse($media->thumb_key, 'image/jpeg');
    }

    public function downloadMedia(User $user, Event $event, Media $media): SymfonyResponse
    {
        abort_unless($event->user_id === $user->id && $media->event_id === $event->id, 404);

        return $this->media->downloadResponse($media);
    }

    public function downloadAllMedia(User $user, Event $event): SymfonyResponse
    {
        abort_unless($event->user_id === $user->id, 404);
        $event->load('media');

        return $this->media->zipDownloadResponse($event, $event->name.'.zip');
    }
}
