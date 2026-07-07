<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SignsDirectUploads;
use App\Http\Requests\MediaUploadUrlRequest;
use App\Http\Requests\StoreMediaRequest;
use App\Jobs\GenerateThumbnail;
use App\Jobs\TranscodeVideo;
use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MediaController extends Controller
{
    use AuthorizesRequests;
    use SignsDirectUploads;

    public function __construct(private MediaStorage $storage) {}

    public function uploadUrl(MediaUploadUrlRequest $request): array
    {
        return $this->storage->uploadTarget(
            $request->user(),
            $request->validated('content_type'),
            Media::extensionForMime($request->validated('content_type')),
        );
    }

    protected function uploadStorage(): MediaStorage
    {
        return $this->storage;
    }

    protected function newUploadKey(Request $request, string $contentType): string
    {
        return $this->storage->newMediaKey($request->user(), Media::extensionForMime($contentType));
    }

    /**
     * A media key encodes its owner; reject any that isn't this user's. The
     * only authorisation the upload endpoints need, since no Media row exists yet.
     */
    protected function assertCanWriteKey(Request $request, string $key): void
    {
        abort_unless(
            str_starts_with($key, 'media/users/'.$request->user()->id.'/'),
            403,
        );
    }

    public function store(StoreMediaRequest $request): RedirectResponse
    {
        $key = $request->validated('s3_key');

        abort_unless($this->storage->exists($key), 422, 'Upload not found in storage.');

        $mime = $request->validated('mime');

        $media = $request->user()->media()->create([
            's3_key' => $key,
            'event_id' => $request->validated('event_id'),
            'original_name' => $request->validated('original_name'),
            'mime' => $mime,
            'size' => $request->validated('size'),
            'kind' => Media::kindForMime($mime),
        ]);

        if ($media->kind === 'image') {
            GenerateThumbnail::dispatch($media);
        } elseif ($media->kind === 'video') {
            TranscodeVideo::dispatch($media);
        }

        return back();
    }

    public function stream(Media $media): SymfonyResponse
    {
        $this->authorize('view', $media);

        return $this->storage->streamResponse($media->playbackKey(), $media->playbackMime());
    }

    public function thumb(Media $media): SymfonyResponse
    {
        $this->authorize('view', $media);
        abort_unless($media->thumb_key, 404);

        return $this->storage->streamResponse($media->thumb_key, 'image/jpeg');
    }

    public function download(Media $media): SymfonyResponse
    {
        $this->authorize('view', $media);

        return $this->storage->downloadResponse($media);
    }

    public function rotate(Request $request, Media $media): RedirectResponse
    {
        $this->authorize('update', $media);
        abort_unless($media->kind === 'video', 422, 'Only videos can be rotated.');

        $direction = $request->validate(['direction' => ['required', 'in:cw,ccw']])['direction'];

        // Turn the currently-displayed orientation a quarter-turn and re-encode.
        $delta = $direction === 'ccw' ? 270 : 90;
        $media->update(['rotation' => (($media->rotation ?? 0) + $delta) % 360]);

        TranscodeVideo::dispatch($media);

        return back();
    }

    public function destroy(Media $media): RedirectResponse
    {
        $this->authorize('delete', $media);

        $this->storage->delete($media->s3_key);
        if ($media->playback_key) {
            $this->storage->delete($media->playback_key);
        }
        if ($media->thumb_key) {
            $this->storage->delete($media->thumb_key);
        }
        $media->delete();

        return back();
    }

    public function share(Request $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        if (! $media->share_token) {
            $media->update(['share_token' => Str::random(32)]);
        }

        return response()->json(['share_url' => route('media.shared', $media->share_token)]);
    }

    public function unshare(Request $request, Media $media): SymfonyResponse
    {
        $this->authorize('update', $media);

        $media->update(['share_token' => null]);

        return response()->noContent();
    }

    public function showShared(Media $media): Response
    {
        return Inertia::render('Media/Shared', [
            'media' => [
                'name' => $media->original_name,
                'kind' => $media->kind,
                'mime' => $media->mime,
                'width' => $media->width,
                'height' => $media->height,
                'url' => $this->storage->objectUrl($media->playbackKey(), route('media.shared-stream', $media->share_token), shared: true),
            ],
        ]);
    }

    public function streamShared(Media $media): SymfonyResponse
    {
        return $this->storage->streamResponse($media->playbackKey(), $media->playbackMime());
    }
}
