<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UploadUrlRequest;
use App\Jobs\ExtractPeaks;
use App\Models\Track;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TrackController extends Controller
{
    use AuthorizesRequests;

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

    public function show(Track $track): Response
    {
        $this->authorize('view', $track);

        return Inertia::render('Tracks/Show', [
            'track' => [
                'id' => $track->id,
                'name' => $track->original_name,
                'size' => $track->size,
                'mime' => $track->mime,
                'duration_seconds' => $track->duration_seconds,
                'peaks' => $track->peaks,
                'peaks_ready' => $track->peaks !== null,
                'created_at' => $track->created_at?->toIso8601String(),
                'stream_url' => route('tracks.stream', $track->id),
                // Per-channel mixing needs a CORS-clean (same-origin) source;
                // the S3 branch redirects off-origin, which taints Web Audio.
                'streams_same_origin' => $this->diskDriver() !== 's3',
            ],
        ]);
    }

    public function stream(Track $track): SymfonyResponse
    {
        $this->authorize('view', $track);

        // S3 streams (and seeks) directly from a short-lived signed URL; local
        // disks are served through a range-aware file response for scrubbing.
        if ($this->diskDriver() === 's3') {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return redirect()->away($disk->temporaryUrl($track->s3_key, now()->addMinutes(30)));
        }

        $disk = $this->disk();
        abort_unless($disk->exists($track->s3_key), 404);

        return response()->file($disk->path($track->s3_key), [
            'Content-Type' => $track->mime ?: 'audio/wav',
        ]);
    }

    public function uploadUrl(UploadUrlRequest $request): array
    {
        $userId = $request->user()->id;
        $key = "users/{$userId}/".(string) Str::ulid().'.wav';

        // Local disks can't mint presigned upload URLs, so point the browser
        // at a signed app endpoint that streams the body to disk instead.
        if ($this->diskDriver() !== 's3') {
            return [
                'url' => URL::temporarySignedRoute(
                    'tracks.upload-put',
                    now()->addMinutes(15),
                    ['key' => $key],
                ),
                'headers' => [],
                's3_key' => $key,
            ];
        }

        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk('s3');

        $signed = $disk->temporaryUploadUrl(
            $key,
            now()->addMinutes(15),
            ['ContentType' => $request->validated('content_type')],
        );

        return [
            'url' => $signed['url'],
            'headers' => $signed['headers'],
            's3_key' => $key,
        ];
    }

    public function uploadPut(Request $request): \Illuminate\Http\Response
    {
        $key = (string) $request->query('key', '');

        abort_unless(
            str_starts_with($key, 'users/'.$request->user()->id.'/') && str_ends_with($key, '.wav'),
            403,
        );

        $this->disk()->writeStream($key, $request->getContent(asResource: true));

        return response()->noContent();
    }

    public function store(StoreTrackRequest $request): RedirectResponse
    {
        $disk = $this->disk();
        $key = $request->validated('s3_key');

        abort_unless($disk->exists($key), 422, 'Upload not found in storage.');

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

        $this->disk()->delete($track->s3_key);
        $track->delete();

        return back();
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.tracks_disk'));
    }

    private function diskDriver(): string
    {
        return (string) config('filesystems.disks.'.config('filesystems.tracks_disk').'.driver');
    }
}
