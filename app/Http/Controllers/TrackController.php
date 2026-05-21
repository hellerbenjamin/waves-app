<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Http\Requests\UploadUrlRequest;
use App\Jobs\ExtractPeaks;
use App\Models\Track;
use App\Services\TrackStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TrackController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private TrackStorage $storage) {}

    public function index(Request $request): Response
    {
        $tracks = $request->user()
            ->tracks()
            ->latest()
            ->get(['id', 'original_name', 'size', 'duration_seconds', 'peaks', 'created_at'])
            ->map(fn (Track $t) => [
                'id' => $t->id,
                'name' => $t->original_name,
                'size' => $t->size,
                'duration_seconds' => $t->duration_seconds,
                'peaks_ready' => $t->peaks !== null,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Tracks/Index', [
            'tracks' => $tracks,
        ]);
    }

    public function show(Request $request, Track $track): Response
    {
        $this->authorize('view', $track);

        return Inertia::render('Tracks/Show', [
            'canEdit' => true,
            'templates' => $request->user()
                ->channelTemplates()
                ->latest()
                ->get(['id', 'name', 'labels']),
            'track' => array_merge(
                $this->trackProps($track, route('tracks.stream', $track->id)),
                ['share_url' => $track->share_token ? route('tracks.shared', $track->share_token) : null],
            ),
        ]);
    }

    public function showShared(Track $track): Response
    {
        return Inertia::render('Tracks/Show', [
            'canEdit' => false,
            'templates' => [],
            'track' => $this->trackProps($track, route('tracks.shared-stream', $track->share_token)),
        ]);
    }

    public function share(Request $request, Track $track): JsonResponse
    {
        $this->authorize('update', $track);

        if (! $track->share_token) {
            $track->update(['share_token' => Str::random(32)]);
        }

        return response()->json(['share_url' => route('tracks.shared', $track->share_token)]);
    }

    public function unshare(Request $request, Track $track): SymfonyResponse
    {
        $this->authorize('update', $track);

        $track->update(['share_token' => null]);

        return response()->noContent();
    }

    public function update(UpdateTrackRequest $request, Track $track): JsonResponse
    {
        $data = $request->validated();
        $changes = [];

        // Only touch fields the request actually sent — a rename must not clear
        // labels, and a label save must not blank the name.
        if (array_key_exists('original_name', $data)) {
            $changes['original_name'] = trim($data['original_name']);
        }

        if (array_key_exists('channel_labels', $data)) {
            // Normalise blank entries to null and drop any beyond the channel count.
            $channels = count($track->peaks['channels'] ?? []);
            $changes['channel_labels'] = collect($data['channel_labels'])
                ->take($channels)
                ->map(fn ($label) => filled(trim((string) $label)) ? trim((string) $label) : null)
                ->all();
        }

        $track->update($changes);

        return response()->json([
            'name' => $track->original_name,
            'channel_labels' => $track->channel_labels,
        ]);
    }

    public function stream(Track $track): SymfonyResponse
    {
        $this->authorize('view', $track);

        return $this->storage->streamResponse($track);
    }

    public function streamShared(Track $track): SymfonyResponse
    {
        return $this->storage->streamResponse($track);
    }

    /**
     * @return array<string, mixed>
     */
    private function trackProps(Track $track, string $streamUrl): array
    {
        return [
            'id' => $track->id,
            'name' => $track->original_name,
            'size' => $track->size,
            'mime' => $track->mime,
            'duration_seconds' => $track->duration_seconds,
            'peaks' => $track->peaks,
            'channel_labels' => $track->channel_labels,
            'peaks_ready' => $track->peaks !== null,
            'created_at' => $track->created_at?->toIso8601String(),
            'stream_url' => $streamUrl,
            // Per-channel mixing needs a CORS-clean (same-origin) source.
            'streams_same_origin' => $this->storage->streamsSameOrigin(),
        ];
    }

    public function uploadUrl(UploadUrlRequest $request): array
    {
        return $this->storage->uploadTarget(
            $request->user(),
            $request->validated('content_type'),
        );
    }

    public function uploadPut(Request $request): \Illuminate\Http\Response
    {
        $key = (string) $request->query('key', '');

        abort_unless(
            str_starts_with($key, 'users/'.$request->user()->id.'/') && str_ends_with($key, '.wav'),
            403,
        );

        $this->storage->put($key, $request->getContent(asResource: true));

        return response()->noContent();
    }

    public function store(StoreTrackRequest $request): RedirectResponse
    {
        $key = $request->validated('s3_key');

        abort_unless($this->storage->exists($key), 422, 'Upload not found in storage.');

        $track = $request->user()->tracks()->create([
            's3_key' => $key,
            'original_name' => $request->validated('original_name'),
            'mime' => $request->validated('mime'),
            'size' => $request->validated('size'),
        ]);

        ExtractPeaks::dispatch($track);

        return redirect()->route('tracks.index');
    }

    public function destroy(Track $track): RedirectResponse
    {
        $this->authorize('delete', $track);

        $this->storage->delete($track->s3_key);
        $track->delete();

        return back();
    }
}
