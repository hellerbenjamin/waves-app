<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Track;
use App\Models\TrackChannel;
use App\Services\MediaStorage;
use App\Services\TrackStorage;
use App\Support\CollectionLinkContext;
use App\Support\CollectionPresenter;
use App\Support\TrackPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CollectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private TrackStorage $tracks,
        private MediaStorage $media,
        private CollectionPresenter $presenter,
        private TrackPresenter $trackPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $collections = $request->user()
            ->collections()
            ->withCount(['tracks', 'media'])
            ->latest()
            ->get()
            ->map(fn (Collection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'tracks_count' => $c->tracks_count,
                'media_count' => $c->media_count,
                'shared' => $c->share_token !== null,
            ]);

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
        ]);
    }

    /** Lightweight JSON list of the user's collections, for "Add to collection" menus. */
    public function list(Request $request): JsonResponse
    {
        return response()->json([
            'collections' => $request->user()
                ->collections()
                ->latest()
                ->get(['id', 'name'])
                ->map(fn (Collection $c) => ['id' => $c->id, 'name' => $c->name]),
        ]);
    }

    public function store(StoreCollectionRequest $request): RedirectResponse
    {
        $collection = $request->user()->collections()->create($request->validated());

        return redirect()->route('collections.show', $collection->id);
    }

    public function show(Request $request, Collection $collection): Response
    {
        $this->authorize('view', $collection);

        $collection->load(['tracks' => fn ($q) => $q->forCards(), 'media']);

        return Inertia::render('Collections/Show', [
            'canEdit' => true,
            'collection' => $this->presenter->collection($collection, CollectionLinkContext::owner($collection)),
        ]);
    }

    public function showShared(Collection $collection): Response
    {
        $collection->load(['tracks' => fn ($q) => $q->forCards(), 'media']);

        return Inertia::render('Collections/Show', [
            'canEdit' => false,
            'collection' => $this->presenter->collection($collection, CollectionLinkContext::collectionShare($collection)),
        ]);
    }

    public function update(UpdateCollectionRequest $request, Collection $collection): RedirectResponse
    {
        $collection->update($request->validated());

        return back();
    }

    public function destroy(Collection $collection): RedirectResponse
    {
        $this->authorize('delete', $collection);

        // Only the collection and its pivot rows go — the underlying tracks and
        // media (and their events) are untouched.
        $collection->delete();

        return redirect()->route('collections.index');
    }

    public function share(Request $request, Collection $collection): JsonResponse
    {
        $this->authorize('update', $collection);

        if (! $collection->share_token) {
            $collection->update(['share_token' => Str::random(32)]);
        }

        return response()->json(['share_url' => route('collections.shared', $collection->share_token)]);
    }

    public function unshare(Request $request, Collection $collection): SymfonyResponse
    {
        $this->authorize('update', $collection);

        $collection->update(['share_token' => null]);

        return response()->noContent();
    }

    /** Add owned tracks or media to the collection (idempotent). */
    public function attach(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('update', $collection);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['track', 'media'])],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Only ever attach items the user owns — the id list is client-supplied.
        $ownedIds = $this->ownedItemIds($request, $validated['type'], $validated['ids']);
        $this->relationFor($collection, $validated['type'])->syncWithoutDetaching($ownedIds);

        return back();
    }

    /** Remove tracks or media from the collection (the items themselves survive). */
    public function detach(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('update', $collection);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['track', 'media'])],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $this->relationFor($collection, $validated['type'])->detach($validated['ids']);

        return back();
    }

    /**
     * The user's tracks and media, for the "Add items" picker. Optionally
     * filtered by a name query.
     */
    public function candidates(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $tracks = $request->user()->tracks()
            ->when($q !== '', fn ($b) => $b->where('original_name', 'like', "%{$q}%"))
            ->latest()
            ->get(['id', 'original_name', 'duration_seconds'])
            ->map(fn (Track $t) => [
                'id' => $t->id,
                'name' => $t->original_name,
                'duration_seconds' => $t->duration_seconds,
            ]);

        $media = $request->user()->media()
            ->when($q !== '', fn ($b) => $b->where('original_name', 'like', "%{$q}%"))
            ->latest()
            ->get()
            ->map(fn (Media $m) => [
                'id' => $m->id,
                'name' => $m->original_name,
                'kind' => $m->kind,
                'thumb_url' => $this->media->objectUrl($m->thumb_key, route('media.thumb', $m->id)),
            ]);

        return response()->json(['tracks' => $tracks, 'media' => $media]);
    }

    /** The morph relation on the collection for a validated item type. */
    private function relationFor(Collection $collection, string $type)
    {
        return $type === 'track' ? $collection->tracks() : $collection->media();
    }

    /**
     * Narrow a client-supplied id list to items the user actually owns.
     *
     * @param  array<int>  $ids
     * @return array<int>
     */
    private function ownedItemIds(Request $request, string $type, array $ids): array
    {
        $relation = $type === 'track' ? $request->user()->tracks() : $request->user()->media();

        return $relation->whereIn('id', $ids)->pluck('id')->all();
    }

    // --- Token-scoped public streaming -------------------------------------
    // A shared collection grants access to its member tracks/media without each
    // item needing its own share link; access is the collection token plus
    // membership in the collection.

    public function showSharedTrack(Collection $collection, Track $track): Response
    {
        abort_unless($this->contains($collection, 'track', $track->id), 404);
        $track->load('channels');

        return Inertia::render('Tracks/Show', [
            'canEdit' => false,
            'templates' => [],
            'track' => $this->trackPresenter->show(
                $track,
                fn (TrackChannel $c) => route('collections.shared.channels.stream', [$collection->share_token, $track->id, $c->channel_index]),
                fn (TrackChannel $c) => route('collections.shared.channels.peaks', [$collection->share_token, $track->id, $c->channel_index]),
                shared: true,
            ),
        ]);
    }

    public function streamSharedChannel(Collection $collection, Track $track, int $channel): SymfonyResponse
    {
        abort_unless($this->contains($collection, 'track', $track->id), 404);
        $row = $track->channels()->where('channel_index', $channel)->first();
        abort_unless($row !== null, 404);

        return $this->tracks->channelStreamResponse($row);
    }

    public function peaksSharedChannel(Collection $collection, Track $track, int $channel): SymfonyResponse
    {
        abort_unless($this->contains($collection, 'track', $track->id), 404);
        $row = $track->channels()->where('channel_index', $channel)->first();
        abort_unless($row !== null, 404);

        return $this->tracks->channelPeaksResponse($row);
    }

    public function streamSharedMedia(Collection $collection, Media $media): SymfonyResponse
    {
        abort_unless($this->contains($collection, 'media', $media->id), 404);

        return $this->media->streamResponse($media->playbackKey(), $media->playbackMime());
    }

    public function thumbSharedMedia(Collection $collection, Media $media): SymfonyResponse
    {
        abort_unless($this->contains($collection, 'media', $media->id) && $media->thumb_key, 404);

        return $this->media->streamResponse($media->thumb_key, 'image/jpeg');
    }

    public function downloadSharedMedia(Collection $collection, Media $media): SymfonyResponse
    {
        abort_unless($this->contains($collection, 'media', $media->id), 404);

        return $this->media->downloadResponse($media);
    }

    public function downloadAllSharedMedia(Collection $collection): SymfonyResponse
    {
        return $this->media->zipMediaResponse($collection->media, $collection->name.'.zip');
    }

    public function downloadAllMedia(Request $request, Collection $collection): SymfonyResponse
    {
        $this->authorize('view', $collection);

        return $this->media->zipMediaResponse($collection->media, $collection->name.'.zip');
    }

    /** Whether a track/media is a member of the collection. */
    private function contains(Collection $collection, string $type, int $id): bool
    {
        return $this->relationFor($collection, $type)->whereKey($id)->exists();
    }
}
