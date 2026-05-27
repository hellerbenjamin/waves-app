<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Http\Requests\UploadUrlRequest;
use App\Jobs\TranscodeTrackToChannels;
use App\Models\Track;
use App\Models\TrackChannel;
use App\Services\TrackStorage;
use App\Support\TrackPresenter;
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

    public function __construct(
        private TrackStorage $storage,
        private TrackPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $tracks = $request->user()
            ->tracks()
            ->with('event:id,name')
            ->withExists('channels')
            ->select(['id', 'event_id', 'original_name', 'size', 'duration_seconds', 'created_at'])
            ->latest()
            ->get()
            ->map(fn (Track $t) => [
                'id' => $t->id,
                'name' => $t->original_name,
                'size' => $t->size,
                'duration_seconds' => $t->duration_seconds,
                // Channels exist iff the transcode job has finished.
                'ready' => (bool) $t->channels_exists,
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
        $track->load('channels');

        return Inertia::render('Tracks/Show', [
            'canEdit' => true,
            'templates' => $request->user()
                ->channelTemplates()
                ->latest()
                ->get(['id', 'name', 'labels']),
            // Owner's other tracks with a saved default mix and a matching
            // channel count — candidates to "Copy mix from".
            'mixSources' => $this->mixSourcesFor($request, $track),
            'track' => array_merge(
                $this->presenter->show(
                    $track,
                    fn (TrackChannel $c) => route('tracks.channels.stream', [$track->id, $c->channel_index]),
                    fn (TrackChannel $c) => route('tracks.channels.peaks', [$track->id, $c->channel_index]),
                    shared: false,
                ),
                ['share_url' => $track->share_token ? route('tracks.shared', $track->share_token) : null],
            ),
        ]);
    }

    public function showShared(Track $track): Response
    {
        $track->load('channels');

        return Inertia::render('Tracks/Show', [
            'canEdit' => false,
            'templates' => [],
            'track' => $this->presenter->show(
                $track,
                fn (TrackChannel $c) => route('tracks.shared-channels.stream', [$track->share_token, $c->channel_index]),
                fn (TrackChannel $c) => route('tracks.shared-channels.peaks', [$track->share_token, $c->channel_index]),
                shared: true,
            ),
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
            $channels = (int) $track->channels_count;
            $changes['channel_labels'] = collect($data['channel_labels'])
                ->take($channels)
                ->map(fn ($label) => filled(trim((string) $label)) ? trim((string) $label) : null)
                ->all();
        }

        if (array_key_exists('default_mix', $data)) {
            // null clears the saved mix; otherwise trim to the channel count
            // and coerce types so the client always reads back the same shape
            // it writes (and shared viewers get something safe to apply).
            $channels = (int) $track->channels_count;
            $changes['default_mix'] = $data['default_mix'] === null ? null : collect($data['default_mix'])
                ->take($channels)
                ->map(function ($entry) {
                    // Per-channel preamp trim in dB, snapped to 5 dB steps in
                    // [0, 20] — there's a real risk of clipping above that, and
                    // a quiet track usually only needs +5–+15 to put the fader
                    // back in a useful range.
                    $boost = (int) round(((float) ($entry['boost'] ?? 0)) / 5) * 5;
                    $boost = max(0, min(20, $boost));

                    return [
                        'level' => (int) round(max(0, min(100, (float) $entry['level']))),
                        'pan' => (int) round(max(-100, min(100, (float) $entry['pan']))),
                        'muted' => (bool) $entry['muted'],
                        'solo' => (bool) ($entry['solo'] ?? false),
                        'boost' => $boost,
                    ];
                })
                ->all();
        }

        $track->update($changes);

        return response()->json([
            'name' => $track->original_name,
            'channel_labels' => $track->channel_labels,
            'default_mix' => $track->default_mix,
        ]);
    }

    public function download(Track $track): SymfonyResponse
    {
        $this->authorize('view', $track);

        return $this->storage->downloadResponse($track);
    }

    /**
     * Stream a single transcoded Opus channel of an authed-user-owned track.
     * The channel lookup is by `(track_id, channel_index)` rather than the
     * row's own PK so the URL stays opaque to the storage layout.
     */
    public function streamChannel(Track $track, int $channel): SymfonyResponse
    {
        $this->authorize('view', $track);

        return $this->storage->channelStreamResponse($this->channelOr404($track, $channel));
    }

    public function peaksChannel(Track $track, int $channel): SymfonyResponse
    {
        $this->authorize('view', $track);

        return $this->storage->channelPeaksResponse($this->channelOr404($track, $channel));
    }

    /** Channel stream for a per-track public share link — token-bound, no auth. */
    public function streamSharedChannel(Track $track, int $channel): SymfonyResponse
    {
        return $this->storage->channelStreamResponse($this->channelOr404($track, $channel));
    }

    public function peaksSharedChannel(Track $track, int $channel): SymfonyResponse
    {
        return $this->storage->channelPeaksResponse($this->channelOr404($track, $channel));
    }

    private function channelOr404(Track $track, int $channelIndex): TrackChannel
    {
        $channel = $track->channels()->where('channel_index', $channelIndex)->first();
        abort_unless($channel !== null, 404);

        return $channel;
    }

    /**
     * Candidate source tracks for "Copy mix from…": owner's other tracks with
     * a saved default_mix and the same channel count.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mixSourcesFor(Request $request, Track $track): array
    {
        $channels = (int) $track->channels_count;
        if ($channels < 1) {
            return [];
        }

        return $request->user()
            ->tracks()
            ->whereKeyNot($track->id)
            ->whereNotNull('default_mix')
            ->where('channels_count', $channels)
            ->select(['id', 'original_name', 'default_mix'])
            ->latest()
            ->get()
            ->map(fn (Track $t) => [
                'id' => $t->id,
                'name' => $t->original_name,
                'default_mix' => $t->default_mix,
            ])
            ->all();
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

        TranscodeTrackToChannels::dispatch($track);

        // Stay on whichever page the upload came from (track list or an event).
        return back();
    }

    public function destroy(Track $track): RedirectResponse
    {
        $this->authorize('delete', $track);

        // Source WAV only exists between upload finalize and transcode; clear
        // it (and its legacy sibling peaks JSON) if it's still around.
        if ($track->s3_key !== null) {
            $this->storage->delete($track->s3_key);
            $this->storage->delete($this->storage->peaksKey($track));
        }

        // Per-channel Opus and peaks blobs. The DB rows cascade on track
        // delete; storage cleanup is best-effort and survives a missing key.
        foreach ($track->channels as $channel) {
            $this->storage->delete($channel->s3_key);
            if ($channel->peaks_s3_key !== null) {
                $this->storage->delete($channel->peaks_s3_key);
            }
        }

        $track->delete();

        return back();
    }
}
