<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The browser-driven direct-to-bucket upload dance, shared by the owner's media
 * uploads and anonymous event contributions. Both PUT bytes straight to R2 (one
 * PUT for small files, multipart for large ones) against these signing endpoints;
 * they differ only in where the key lives and who is allowed to write it, which
 * the using controller supplies via the two hooks below.
 *
 * (TrackController predates this and keeps its own copy — its key namespace and
 * MIME set differ; folding it in is a separate cleanup, not this feature's job.)
 */
trait SignsDirectUploads
{
    /** The storage service the signed operations run against. */
    abstract protected function uploadStorage(): MediaStorage;

    /** Mint the object key for a fresh upload (user-scoped vs. event-scoped). */
    abstract protected function newUploadKey(Request $request, string $contentType): string;

    /**
     * Authorise writing to an already-minted key. No DB row exists yet, so this
     * is the only guard the part-signing/finalising endpoints have — owner
     * uploads check the user prefix, contributions the event prefix + token.
     */
    abstract protected function assertCanWriteKey(Request $request, string $key): void;

    public function createMultipart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:53687091200'], // 50 GB
            'content_type' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
        ]);

        $key = $this->newUploadKey($request, $validated['content_type']);

        return response()->json([
            'key' => $key,
            'uploadId' => $this->uploadStorage()->createMultipartUpload($key, $validated['content_type']),
        ]);
    }

    public function signPart(Request $request): JsonResponse
    {
        $key = (string) $request->query('key', '');
        $this->assertCanWriteKey($request, $key);

        $uploadId = (string) $request->query('uploadId', '');
        $partNumber = (int) $request->query('partNumber', 0);
        abort_if($uploadId === '' || $partNumber < 1, 422);

        return response()->json([
            'url' => $this->uploadStorage()->signPart($key, $uploadId, $partNumber),
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
        $this->assertCanWriteKey($request, $validated['key']);

        $this->uploadStorage()->completeMultipartUpload(
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
        $this->assertCanWriteKey($request, $validated['key']);

        $this->uploadStorage()->abortMultipartUpload($validated['key'], $validated['uploadId']);

        return response()->noContent();
    }

    /**
     * Delete an uploaded object whose finalisation (store) failed. The bytes
     * are already in the bucket but no row references them, so without this a
     * failed finalise would orphan a (potentially multi-gigabyte) object forever.
     */
    public function cleanup(Request $request): SymfonyResponse
    {
        $validated = $request->validate(['key' => ['required', 'string']]);
        $this->assertCanWriteKey($request, $validated['key']);

        $this->uploadStorage()->delete($validated['key']);

        return response()->noContent();
    }

    public function uploadPut(Request $request): Response
    {
        $key = (string) $request->query('key', '');
        $this->assertCanWriteKey($request, $key);

        $this->uploadStorage()->put($key, $request->getContent(asResource: true));

        return response()->noContent();
    }
}
