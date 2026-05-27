<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Http\Requests\UploadUrlRequest;
use App\Jobs\CombineTracks;
use App\Jobs\DetectSongs;
use App\Jobs\ExtractPeaks;
use App\Jobs\SplitTrackSegment;
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
            ->select(['id', 'event_id', 'original_name', 'size', 'duration_seconds', 'peaks_ready', 'created_at'])
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
            // Owner's other tracks with a saved default mix and a matching
            // channel count — candidates to "Copy mix from".
            'mixSources' => $this->mixSourcesFor($request, $track),
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
                ->map(fn ($entry) => [
                    'level' => (int) round(max(0, min(100, (float) $entry['level']))),
                    'pan' => (int) round(max(-100, min(100, (float) $entry['pan']))),
                    'muted' => (bool) $entry['muted'],
                ])
                ->all();
        }

        $track->update($changes);

        return response()->json([
            'name' => $track->original_name,
            'channel_labels' => $track->channel_labels,
            'default_mix' => $track->default_mix,
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

    public function peaks(Track $track): SymfonyResponse
    {
        $this->authorize('view', $track);

        return $this->storage->peaksResponse($track);
    }

    public function peaksShared(Track $track): SymfonyResponse
    {
        return $this->storage->peaksResponse($track);
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

    /**
     * @return array<string, mixed>
     */
    private function trackProps(Track $track, string $streamUrl, bool $shared = false): array
    {
        $peaksRoute = $shared
            ? route('tracks.shared-peaks', $track->share_token)
            : route('tracks.peaks', $track->id);

        return [
            'id' => $track->id,
            'name' => $track->original_name,
            'size' => $track->size,
            'mime' => $track->mime,
            'duration_seconds' => $track->duration_seconds,
            'channels_count' => (int) $track->channels_count,
            'sample_rate' => (int) $track->sample_rate,
            'channel_labels' => $track->channel_labels,
            // Saved mixer state both views initialise to. Shared viewers see it
            // applied but can't save changes back (the update route is auth'd).
            'default_mix' => $track->default_mix,
            'peaks_ready' => (bool) $track->peaks_ready,
            // Peaks JSON lives in object storage; the mixer fetches it via this
            // URL instead of receiving it inline in the Inertia payload.
            'peaks_url' => $this->storage->peaksUrl($track, $peaksRoute, $shared),
            'split_proposal' => $shared ? null : $track->split_proposal,
            'children' => $shared ? [] : $track->children()
                ->select(['id', 'original_name', 'duration_seconds', 'peaks_ready'])
                ->orderBy('id')
                ->get()
                ->map(fn (Track $c) => [
                    'id' => $c->id,
                    'name' => $c->original_name,
                    'duration_seconds' => $c->duration_seconds,
                    'peaks_ready' => (bool) $c->peaks_ready,
                ])
                ->all(),
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

    /**
     * Run silence detection against the track and stage a proposal the user
     * can review and edit. Detection params come from the UI sliders; sensible
     * floors keep a stray value from producing a useless analysis.
     */
    public function detectSongs(Request $request, Track $track): JsonResponse
    {
        $this->authorize('update', $track);
        abort_unless($track->peaks_ready, 422, 'Track is still processing.');

        $validated = $request->validate([
            'silence_db' => ['nullable', 'numeric', 'between:-80,-10'],
            'min_silence' => ['nullable', 'numeric', 'between:0.2,30'],
            'min_region' => ['nullable', 'numeric', 'between:1,3600'],
        ]);

        // Stash the params and a 'detecting' status synchronously so the UI's
        // poll sees the job is in flight rather than a stale 'ready' proposal.
        $track->update([
            'split_proposal' => [
                'status' => 'detecting',
                'params' => [
                    'silence_db' => (float) ($validated['silence_db'] ?? -40),
                    'min_silence' => (float) ($validated['min_silence'] ?? 1.5),
                    'min_region' => (float) ($validated['min_region'] ?? 30),
                ],
                'regions' => $track->split_proposal['regions'] ?? [],
            ],
        ]);

        DetectSongs::dispatch(
            $track,
            (float) ($validated['silence_db'] ?? -40),
            (float) ($validated['min_silence'] ?? 1.5),
            (float) ($validated['min_region'] ?? 30),
        );

        return response()->json(['split_proposal' => $track->split_proposal]);
    }

    /**
     * Persist edits to the staged proposal (region edges, names, deletions).
     * Validates each region's bounds; the UI debounces calls so a drag becomes
     * a single save.
     */
    public function updateSplitProposal(Request $request, Track $track): JsonResponse
    {
        $this->authorize('update', $track);

        $validated = $request->validate([
            'regions' => ['present', 'array'],
            'regions.*.id' => ['nullable', 'string', 'max:32'],
            'regions.*.start' => ['required', 'numeric', 'min:0'],
            'regions.*.end' => ['required', 'numeric', 'gt:regions.*.start'],
            'regions.*.name' => ['nullable', 'string', 'max:120'],
        ]);

        $proposal = $track->split_proposal ?: ['status' => 'ready', 'params' => null];
        $proposal['regions'] = array_map(
            fn ($r, $i) => [
                'id' => $r['id'] ?? ('r'.($i + 1)),
                'start' => round((float) $r['start'], 3),
                'end' => round((float) $r['end'], 3),
                'name' => trim((string) ($r['name'] ?? '')) ?: ('Part '.($i + 1)),
            ],
            $validated['regions'],
            array_keys($validated['regions']),
        );

        $track->update(['split_proposal' => $proposal]);

        return response()->json(['split_proposal' => $track->split_proposal]);
    }

    /** Discard a staged proposal entirely. */
    public function deleteSplitProposal(Track $track): SymfonyResponse
    {
        $this->authorize('update', $track);

        $track->update(['split_proposal' => null]);

        return response()->noContent();
    }

    /**
     * Commit the proposal: enqueue one split job per region. Returns
     * immediately; children appear in the parent's `children` list as jobs
     * finish.
     */
    public function commitSplit(Request $request, Track $track): JsonResponse
    {
        $this->authorize('update', $track);
        abort_unless($track->peaks_ready, 422, 'Track is still processing.');

        $regions = $track->split_proposal['regions'] ?? [];
        abort_if(empty($regions), 422, 'No regions to split.');

        foreach ($regions as $region) {
            SplitTrackSegment::dispatch($track, $region);
        }

        // Clear the proposal: it's no longer the source of truth — the spawned
        // children are. A re-run starts from a fresh detect.
        $track->update(['split_proposal' => null]);

        return response()->json(['queued' => count($regions)]);
    }

    /**
     * Stitch ≥2 of the owner's tracks into one new track, in the order the
     * client passes. The user picked "delete originals" — that happens inside
     * the job once the combined output is safely in storage.
     *
     * Format-matching is split between layers: sample rate and channel count
     * are cheap to read from the cached columns we hoisted out of the peaks
     * envelope at extract time, so we reject mismatches here before queueing.
     * Bit-depth lives only in the WAV header and is verified inside the job
     * (with ffprobe) just before concat.
     */
    public function combine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_ids' => ['required', 'array', 'min:2'],
            'track_ids.*' => ['integer', 'distinct'],
            'name' => ['required', 'string', 'max:255'],
            'event_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        $tracks = $user->tracks()
            ->whereIn('id', $validated['track_ids'])
            ->get()
            ->keyBy('id');

        // Same count means every id was found and owned. Anything missing
        // either belongs to someone else or doesn't exist.
        abort_unless($tracks->count() === count($validated['track_ids']), 404, 'One or more tracks not found.');

        $ordered = collect($validated['track_ids'])->map(fn ($id) => $tracks->get($id));

        // Peaks must be ready on every source — without them we don't have a
        // cheap channel/sample-rate to validate against, and a not-yet-decoded
        // upload is also not safe to concat (its bytes may still be in flight).
        $missingPeaks = $ordered->first(fn (Track $t) => ! $t->peaks_ready);
        if ($missingPeaks) {
            return response()->json([
                'message' => "\"{$missingPeaks->original_name}\" is still processing — wait for its waveform before combining.",
            ], 422);
        }

        $first = $ordered->first();
        $firstChannels = (int) $first->channels_count;
        $firstRate = (int) $first->sample_rate;

        foreach ($ordered as $track) {
            $channels = (int) $track->channels_count;
            $rate = (int) $track->sample_rate;

            if ($channels !== $firstChannels || $rate !== $firstRate) {
                return response()->json([
                    'message' => "\"{$track->original_name}\" ({$channels}ch @ {$rate} Hz) doesn't match the first track ({$firstChannels}ch @ {$firstRate} Hz). All tracks must share sample rate, bit depth, and channel count.",
                ], 422);
            }
        }

        // Resolve event_id: an explicit value the user owns, else the common
        // event of all sources if they happen to share one, else null.
        $eventId = $validated['event_id'] ?? null;
        if ($eventId !== null) {
            abort_unless(
                $user->events()->whereKey($eventId)->exists(),
                404,
                'Event not found.',
            );
        } else {
            $commonEventIds = $ordered->pluck('event_id')->unique();
            $eventId = $commonEventIds->count() === 1 ? $commonEventIds->first() : null;
        }

        CombineTracks::dispatch(
            $user,
            $ordered->pluck('id')->all(),
            trim($validated['name']),
            $eventId,
        );

        return response()->json(['queued' => $ordered->count()]);
    }

    public function destroy(Track $track): RedirectResponse
    {
        $this->authorize('delete', $track);

        $this->storage->delete($track->s3_key);
        // Best-effort peaks cleanup: the sibling .peaks.json may or may not
        // exist (older tracks pre-regenerate, or extraction never completed),
        // but a missing key is a no-op on both S3 and local disks.
        $this->storage->delete($this->storage->peaksKey($track));
        $track->delete();

        return back();
    }
}
