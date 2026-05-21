<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Owns where track audio lives and how it is read and written. Centralises the
 * one piece of real complexity in track handling: the difference between an S3
 * backend (presigned/redirected, off-origin) and a local disk (signed app
 * endpoint, served in-process).
 */
class TrackStorage
{
    /**
     * Mint an upload target for a new track. S3 disks return a presigned PUT
     * URL the browser hits directly; other disks can't presign, so point the
     * browser at a signed app endpoint that streams the body to disk instead.
     *
     * @return array{url: string, headers: array<string, string>, s3_key: string}
     */
    public function uploadTarget(User $user, string $contentType): array
    {
        $key = "users/{$user->id}/".(string) Str::ulid().'.wav';

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

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    /**
     * @param  resource  $resource
     */
    public function put(string $key, $resource): void
    {
        $this->disk()->writeStream($key, $resource);
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
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
     * Whether the served stream is same-origin. Per-channel mixing needs a
     * CORS-clean source; the S3 branch redirects off-origin, which taints
     * Web Audio, so the mixer can only run for local streams.
     */
    public function streamsSameOrigin(): bool
    {
        return ! $this->isS3();
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.tracks_disk'));
    }

    private function isS3(): bool
    {
        return $this->driver() === 's3';
    }

    private function driver(): string
    {
        return (string) config('filesystems.disks.'.config('filesystems.tracks_disk').'.driver');
    }
}
