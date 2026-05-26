<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use App\Services\Concerns\InteractsWithS3;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Owns where track audio lives and how it is read and written. Centralises the
 * one piece of real complexity in track handling: the difference between an S3
 * backend (presigned/redirected, off-origin) and a local disk (signed app
 * endpoint, served in-process). The S3 plumbing is shared with media via
 * {@see InteractsWithS3}.
 */
class TrackStorage
{
    use InteractsWithS3;

    /**
     * Mint an upload target for a new track. S3 disks return a presigned PUT
     * URL the browser hits directly; other disks can't presign, so point the
     * browser at a signed app endpoint that streams the body to disk instead.
     *
     * @return array{url: string, headers: array<string, string>, s3_key: string}
     */
    public function uploadTarget(User $user, string $contentType): array
    {
        $key = $this->newTrackKey($user);

        if (! $this->isS3()) {
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
        $disk = $this->disk();

        $signed = $disk->temporaryUploadUrl(
            $key,
            now()->addMinutes(15),
            ['ContentType' => $contentType],
        );

        return [
            'url' => $signed['url'],
            'headers' => $signed['headers'],
            's3_key' => $key,
        ];
    }

    /**
     * Mint the storage key for a new track. Namespaced per user so ownership
     * can be enforced from the key alone, and ULID-named so two uploads never
     * collide.
     */
    public function newTrackKey(User $user): string
    {
        return "users/{$user->id}/".(string) Str::ulid().'.wav';
    }

    /**
     * Build a playable HTTP response for a track. S3 streams (and seeks)
     * directly from a short-lived signed URL; local disks are served through a
     * range-aware file response for scrubbing.
     */
    public function streamResponse(Track $track): SymfonyResponse
    {
        if ($this->isS3()) {
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

    /**
     * The URL the player loads audio from. For an owner's own page an S3 disk
     * is handed a presigned object URL directly (self-authenticating, fetched
     * without cookies, CORS-clean for the mixer); the TTL must outlast a
     * listening session since it's baked into the page at render time.
     *
     * A public share page must stay revocable, so it never bakes in a
     * long-lived presigned URL: it always uses the given in-app route, which
     * mints a fresh short-lived URL per request and 404s the moment the share
     * token is cleared. Local disks always use the route regardless.
     */
    public function playbackUrl(Track $track, string $localRoute, bool $shared = false): string
    {
        if ($this->isS3() && ! $shared) {
            return $this->disk()->temporaryUrl($track->s3_key, now()->addHours(6));
        }

        return $localRoute;
    }

    /**
     * Build a download response for a track: presign an S3 GET that forces
     * Content-Disposition: attachment with the original filename, or fall back
     * to a streamed file download on a local disk.
     */
    public function downloadResponse(Track $track): SymfonyResponse
    {
        $filename = $track->original_name ?: ('track-'.$track->id.'.wav');

        if ($this->isS3()) {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return redirect()->away($disk->temporaryUrl(
                $track->s3_key,
                now()->addMinutes(15),
                [
                    'ResponseContentDisposition' => 'attachment; filename="'.addslashes($filename).'"',
                    'ResponseContentType' => $track->mime ?: 'audio/wav',
                ],
            ));
        }

        $disk = $this->disk();
        abort_unless($disk->exists($track->s3_key), 404);

        return response()->download(
            $disk->path($track->s3_key),
            $filename,
            ['Content-Type' => $track->mime ?: 'audio/wav'],
        );
    }

    /**
     * The crossorigin mode the audio element must use for playbackUrl().
     * Presigned S3 URLs carry no cookies, so they load anonymously; the local
     * route is cookie-authenticated and same-origin, so it sends credentials.
     * Either way the source is CORS-clean, so per-channel mixing can run.
     */
    public function streamCrossOrigin(): string
    {
        return $this->isS3() ? 'anonymous' : 'use-credentials';
    }
}
