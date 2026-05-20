<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UploadUrlRequest;
use App\Jobs\ExtractPeaks;
use App\Models\Track;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

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

    public function uploadUrl(UploadUrlRequest $request): array
    {
        $userId = $request->user()->id;
        $key = "users/{$userId}/".(string) Str::ulid().'.wav';

        /** @var \Illuminate\Filesystem\AwsS3V3Adapter $disk */
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

    public function store(StoreTrackRequest $request): RedirectResponse
    {
        $disk = Storage::disk('s3');
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

        Storage::disk('s3')->delete($track->s3_key);
        $track->delete();

        return back();
    }
}
