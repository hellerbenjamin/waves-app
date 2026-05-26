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
            ->with('event:id,name')
            // Select a cheap readiness flag rather than the peaks JSON itself —
            // that payload is huge for multi-GB tracks and would blow MySQL's
            // sort buffer when this list is ordered (see Track::scopeForCards).
            ->select(['id', 'event_id', 'original_name', 'size', 'duration_seconds', 'created_at'])
            ->selectRaw('peaks is not null as peaks_ready')
            ->latest()
            ->get()
            ->map(fn (Track $t) => [
                'id' => $t->id,
                'name' => $t->original_name,
                'size' => $t->size,
                'duration_seconds' => $t->duration_seconds,
                'peaks_ready' => $t->peaks_ready,
                'event_id' => $t->event_id,
                'event' => $t->event ? ['id' => $t->event->id, 'name' => $t->event->name] : null,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Tracks/Index', [
            'tracks' => $tracks,
            'events' => $request->user()
                ->events()
                ->latest()
                ->get(['id', 'name'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name]),
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
            'track' => $this->trackProps($track, route('tracks.shared-stream', $track->share_token), shared: true),
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

        if (array_key_exists('event_id', $data)) {
            $changes['event_id'] = $data['event_id'];
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

    public function download(Track $track): SymfonyResponse
    {
        $this->authorize('view', $track);

        return $this->storage->downloadResponse($track);
    }

    public function streamShared(Track $track): SymfonyResponse
    {
        return $this->storage->streamResponse($track);
    }

    /**
     * @return array<string, mixed>
     */
    private function trackProps(Track $track, string $streamUrl, bool $shared = false): array
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
            'stream_url' => $this->storage->playbackUrl($track, $streamUrl, $shared),
            // How the player must load the stream so it stays CORS-clean for
            // the per-channel mixer: 'anonymous' for presigned S3, else creds.
            'stream_cross_origin' => $this->storage->streamCrossOrigin(),
        ];
    }

    public function uploadUrl(UploadUrlRequest $request): array
    {
        return $this->storage->uploadTarget(
            $request->user(),
            $request->validated('content_type'),
        );
    }

    /**
     * Start a multipart upload (the path for multi-gigabyte files). Mints a
     * user-scoped key and returns the S3 upload id the browser drives parts
     * against. Finalisation still happens in store().
     */
    public function createMultipart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/\.wav$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:53687091200'], // 50 GB
            'content_type' => ['required', 'string', 'in:audio/wav,audio/x-wav,audio/wave,audio/vnd.wave'],
        ]);

        $key = $this->storage->newTrackKey($request->user());

        return response()->json([
            'key' => $key,
            'uploadId' => $this->storage->createMultipartUpload($key, $validated['content_type']),
        ]);
    }

    public function signPart(Request $request): JsonResponse
    {
        $key = (string) $request->query('key', '');
        $this->assertOwnsKey($request, $key);

        $uploadId = (string) $request->query('uploadId', '');
        $partNumber = (int) $request->query('partNumber', 0);
        abort_if($uploadId === '' || $partNumber < 1, 422);

        return response()->json([
            'url' => $this->storage->signPart($key, $uploadId, $partNumber),
        ]);
    }

    public function completeMultipart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.PartNumber' => ['required', 'integer', 'min:1'],
            'parts.*.ETag' => ['required', 'string'],
        ]);
        $this->assertOwnsKey($request, $validated['key']);

        $this->storage->completeMultipartUpload(
            $validated['key'],
            $validated['uploadId'],
            $validated['parts'],
        );

        return response()->json(['location' => $validated['key']]);
    }

    public function abortMultipart(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
        ]);
        $this->assertOwnsKey($request, $validated['key']);

        $this->storage->abortMultipartUpload($validated['key'], $validated['uploadId']);

        return response()->noContent();
    }

    /**
     * Delete an uploaded object whose finalisation (store) failed. The bytes
     * are already in the bucket but no Track row references them, so without
     * this a failed finalise would orphan a (potentially multi-gigabyte)
     * object forever. Authorised by the owner-encoding key, since no row exists.
     */
    public function cleanup(Request $request): SymfonyResponse
    {
        $validated = $request->validate(['key' => ['required', 'string']]);
        $this->assertOwnsKey($request, $validated['key']);

        $this->storage->delete($validated['key']);

        return response()->noContent();
    }

    /**
     * A track key encodes its owner; reject any that isn't this user's. The
     * only authorisation the upload endpoints need, since no Track row exists
     * yet.
     */
    private function assertOwnsKey(Request $request, string $key): void
    {
        abort_unless(
            str_starts_with($key, 'users/'.$request->user()->id.'/') && str_ends_with($key, '.wav'),
            403,
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
            'event_id' => $request->validated('event_id'),
            'original_name' => $request->validated('original_name'),
            'mime' => $request->validated('mime'),
            'size' => $request->validated('size'),
        ]);

        ExtractPeaks::dispatch($track);

        // Stay on whichever page the upload came from (track list or an event).
        return back();
    }

    public function destroy(Track $track): RedirectResponse
    {
        $this->authorize('delete', $track);

        $this->storage->delete($track->s3_key);
        $track->delete();

        return back();
    }
}
