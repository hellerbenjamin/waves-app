<?php

namespace App\Http\Controllers;

use App\Http\Requests\MediaUploadUrlRequest;
use App\Http\Requests\StoreMediaRequest;
use App\Jobs\GenerateThumbnail;
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

    public function __construct(private MediaStorage $storage) {}

    public function uploadUrl(MediaUploadUrlRequest $request): array
    {
        return $this->storage->uploadTarget(
            $request->user(),
            $request->validated('content_type'),
            Media::extensionForMime($request->validated('content_type')),
        );
    }

    public function createMultipart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:53687091200'], // 50 GB
            'content_type' => ['required', 'string', \Illuminate\Validation\Rule::in(Media::ALLOWED_MIMES)],
        ]);

        $key = $this->storage->newMediaKey(
            $request->user(),
            Media::extensionForMime($validated['content_type']),
        );

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
     * are already in the bucket but no Media row references them, so without
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

    public function uploadPut(Request $request): \Illuminate\Http\Response
    {
        $key = (string) $request->query('key', '');
        $this->assertOwnsKey($request, $key);

        $this->storage->put($key, $request->getContent(asResource: true));

        return response()->noContent();
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
        }

        return back();
    }

    public function stream(Media $media): SymfonyResponse
    {
        $this->authorize('view', $media);

        return $this->storage->streamResponse($media->s3_key, $media->mime);
    }

    public function thumb(Media $media): SymfonyResponse
    {
        $this->authorize('view', $media);
        abort_unless($media->thumb_key, 404);

        return $this->storage->streamResponse($media->thumb_key, 'image/jpeg');
    }

    public function destroy(Media $media): RedirectResponse
    {
        $this->authorize('delete', $media);

        $this->storage->delete($media->s3_key);
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
                'url' => $this->storage->objectUrl($media->s3_key, route('media.shared-stream', $media->share_token), shared: true),
            ],
        ]);
    }

    public function streamShared(Media $media): SymfonyResponse
    {
        return $this->storage->streamResponse($media->s3_key, $media->mime);
    }

    /**
     * A media key encodes its owner; reject any that isn't this user's. The
     * only authorisation the upload endpoints need, since no Media row exists
     * yet.
     */
    private function assertOwnsKey(Request $request, string $key): void
    {
        abort_unless(
            str_starts_with($key, 'media/users/'.$request->user()->id.'/'),
            403,
        );
    }
}
