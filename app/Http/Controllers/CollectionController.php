<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Models\Collection;
use App\Models\Media;
use App\Services\MediaStorage;
use App\Support\CollectionLinkContext;
use App\Support\CollectionPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CollectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private MediaStorage $media,
        private CollectionPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $collections = $request->user()
            ->collections()
            ->withCount('media')
            ->latest()
            ->get()
            ->map(fn (Collection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'media_count' => $c->media_count,
                'shared' => $c->share_token !== null,
            ]);

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
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

        $collection->load(['media' => fn ($q) => $q->latest()]);

        return Inertia::render('Collections/Show', [
            'canEdit' => true,
            // Owned media not already in this collection, offered for "add existing".
            'assignableMedia' => $request->user()
                ->media()
                ->whereNull('collection_id')
                ->latest()
                ->get()
                ->map(fn (Media $m) => [
                    'id' => $m->id,
                    'name' => $m->original_name,
                    'kind' => $m->kind,
                ]),
            'collection' => $this->presenter->collection($collection, CollectionLinkContext::owner($collection)),
        ]);
    }

    public function showShared(Collection $collection): Response
    {
        $collection->load(['media' => fn ($q) => $q->latest()]);

        return Inertia::render('Collections/Show', [
            'canEdit' => false,
            'assignableMedia' => [],
            'collection' => $this->presenter->collection($collection, CollectionLinkContext::share($collection)),
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

        // Media survives — its collection_id is nulled by the FK. The underlying
        // objects are only removed when the items themselves are.
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

    /** Assign existing (owned) media to this collection. */
    public function attachMedia(Request $request, Collection $collection): RedirectResponse
    {
        $this->authorize('update', $collection);

        $validated = $request->validate([
            'media_ids' => ['required', 'array'],
            'media_ids.*' => ['integer'],
        ]);

        $request->user()
            ->media()
            ->whereIn('id', $validated['media_ids'])
            ->update(['collection_id' => $collection->id]);

        return back();
    }

    public function detachMedia(Request $request, Collection $collection, Media $media): RedirectResponse
    {
        $this->authorize('update', $collection);
        abort_unless($media->collection_id === $collection->id, 404);

        $media->update(['collection_id' => null]);

        return back();
    }

    // --- Token-scoped public streaming -------------------------------------
    // A shared collection grants access to its own media without each item
    // needing its own share link; membership is the collection token.

    public function streamSharedMedia(Collection $collection, Media $media): SymfonyResponse
    {
        abort_unless($media->collection_id === $collection->id, 404);

        return $this->media->streamResponse($media->playbackKey(), $media->playbackMime());
    }

    public function thumbSharedMedia(Collection $collection, Media $media): SymfonyResponse
    {
        abort_unless($media->collection_id === $collection->id && $media->thumb_key, 404);

        return $this->media->streamResponse($media->thumb_key, 'image/jpeg');
    }

    public function downloadSharedMedia(Collection $collection, Media $media): SymfonyResponse
    {
        abort_unless($media->collection_id === $collection->id, 404);

        return $this->media->downloadResponse($media);
    }

    public function downloadAllSharedMedia(Collection $collection): SymfonyResponse
    {
        $collection->load('media');

        return $this->media->zipDownloadResponse($collection->media, $collection->name.'.zip');
    }

    public function downloadAllMedia(Request $request, Collection $collection): SymfonyResponse
    {
        $this->authorize('view', $collection);
        $collection->load('media');

        return $this->media->zipDownloadResponse($collection->media, $collection->name.'.zip');
    }
}
