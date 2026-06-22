<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventInviteRequest;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventInvite;
use App\Models\Media;
use App\Models\Track;
use App\Models\TrackChannel;
use App\Services\MediaStorage;
use App\Services\TrackStorage;
use App\Support\EventLinkContext;
use App\Support\EventPresenter;
use App\Support\TrackPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EventController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private TrackStorage $tracks,
        private MediaStorage $media,
        private EventPresenter $presenter,
        private TrackPresenter $trackPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $events = $request->user()
            ->events()
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
                'shared' => $e->share_token !== null,
            ]);

        return Inertia::render('Events/Index', [
            'events' => $events,
            'types' => Event::TYPES,
        ]);
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $event = $request->user()->events()->create($request->validated());

        return redirect()->route('events.show', $event->id);
    }

    public function show(Request $request, Event $event): Response
    {
        $this->authorize('view', $event);

        $event->load([
            'tracks' => fn ($q) => $q->forCards()->latest(),
            'media' => fn ($q) => $q->latest(),
            'invites' => fn ($q) => $q->whereNull('revoked_at')->latest(),
        ]);

        return Inertia::render('Events/Show', [
            'canEdit' => true,
            'types' => Event::TYPES,
            'assignableTracks' => $request->user()
                ->tracks()
                ->whereNull('event_id')
                ->latest()
                ->get(['id', 'original_name'])
                ->map(fn (Track $t) => ['id' => $t->id, 'name' => $t->original_name]),
            'event' => $this->presenter->event($event, EventLinkContext::owner($event)),
        ]);
    }

    public function showShared(Event $event): Response
    {
        $event->load(['tracks' => fn ($q) => $q->forCards()->latest(), 'media' => fn ($q) => $q->latest()]);

        return Inertia::render('Events/Show', [
            'canEdit' => false,
            'types' => Event::TYPES,
            'assignableTracks' => [],
            'event' => $this->presenter->event($event, EventLinkContext::eventShare($event)),
        ]);
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        return back();
    }

    public function destroy(Event $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        // Tracks and media survive — their event_id is nulled by the FK. The
        // underlying objects are only removed when the items themselves are.
        $event->delete();

        return redirect()->route('events.index');
    }

    public function share(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        if (! $event->share_token) {
            $event->update(['share_token' => Str::random(32)]);
        }

        return response()->json(['share_url' => route('events.shared', $event->share_token)]);
    }

    public function unshare(Request $request, Event $event): SymfonyResponse
    {
        $this->authorize('update', $event);

        $event->update(['share_token' => null]);

        return response()->noContent();
    }

    /** Assign existing (owned) tracks to this event. */
    public function attachTracks(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'track_ids' => ['required', 'array'],
            'track_ids.*' => ['integer'],
        ]);

        $request->user()
            ->tracks()
            ->whereIn('id', $validated['track_ids'])
            ->update(['event_id' => $event->id]);

        return back();
    }

    public function detachTrack(Request $request, Event $event, Track $track): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($track->event_id === $event->id, 404);

        $track->update(['event_id' => null]);

        return back();
    }

    /** Mint a contribution link for this event (owner only, via policy). */
    public function storeInvite(StoreEventInviteRequest $request, Event $event): RedirectResponse
    {
        $event->invites()->create([
            'created_by' => $request->user()->id,
            'token' => Str::random(40),
            'label' => $request->validated('label'),
            'expires_at' => $request->validated('expires_at'),
        ]);

        return back();
    }

    /** Revoke a link: a soft kill-switch that 410s the URL but keeps its uploads. */
    public function destroyInvite(Event $event, EventInvite $eventInvite): RedirectResponse
    {
        $this->authorize('delete', $eventInvite);
        abort_unless($eventInvite->event_id === $event->id, 404);

        $eventInvite->update(['revoked_at' => now()]);

        return back();
    }

    // --- Token-scoped public streaming -------------------------------------
    // A shared event grants access to its own tracks/media without each item
    // needing its own share link; ownership is the event token plus membership.

    public function showSharedTrack(Event $event, Track $track): Response
    {
        abort_unless($track->event_id === $event->id, 404);
        $track->load('channels');

        return Inertia::render('Tracks/Show', [
            'canEdit' => false,
            'templates' => [],
            'track' => $this->trackPresenter->show(
                $track,
                fn (TrackChannel $c) => route('events.shared.channels.stream', [$event->share_token, $track->id, $c->channel_index]),
                fn (TrackChannel $c) => route('events.shared.channels.peaks', [$event->share_token, $track->id, $c->channel_index]),
                shared: true,
            ),
        ]);
    }

    public function streamSharedChannel(Event $event, Track $track, int $channel): SymfonyResponse
    {
        abort_unless($track->event_id === $event->id, 404);
        $row = $track->channels()->where('channel_index', $channel)->first();
        abort_unless($row !== null, 404);

        return $this->tracks->channelStreamResponse($row);
    }

    public function peaksSharedChannel(Event $event, Track $track, int $channel): SymfonyResponse
    {
        abort_unless($track->event_id === $event->id, 404);
        $row = $track->channels()->where('channel_index', $channel)->first();
        abort_unless($row !== null, 404);

        return $this->tracks->channelPeaksResponse($row);
    }

    public function streamSharedMedia(Event $event, Media $media): SymfonyResponse
    {
        abort_unless($media->event_id === $event->id, 404);

        return $this->media->streamResponse($media->s3_key, $media->mime);
    }

    public function thumbSharedMedia(Event $event, Media $media): SymfonyResponse
    {
        abort_unless($media->event_id === $event->id && $media->thumb_key, 404);

        return $this->media->streamResponse($media->thumb_key, 'image/jpeg');
    }

    public function downloadSharedMedia(Event $event, Media $media): SymfonyResponse
    {
        abort_unless($media->event_id === $event->id, 404);

        return $this->media->downloadResponse($media);
    }

    public function downloadAllSharedMedia(Event $event): SymfonyResponse
    {
        $event->load('media');

        return $this->media->zipDownloadResponse($event, $event->name.'.zip');
    }

    public function downloadAllMedia(Request $request, Event $event): SymfonyResponse
    {
        $this->authorize('view', $event);
        $event->load('media');

        return $this->media->zipDownloadResponse($event, $event->name.'.zip');
    }
}
