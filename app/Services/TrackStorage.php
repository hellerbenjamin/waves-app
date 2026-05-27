<?php

namespace App\Services;

use App\Models\Track;
use App\Models\TrackChannel;
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
     * The crossorigin mode the channel audio elements must use. Presigned R2
     * URLs carry no cookies, so they load anonymously; the in-app channel
     * route is cookie-authenticated and same-origin, so it sends credentials.
     * Either way the source is CORS-clean, so per-channel mixing can run.
     */
    public function streamCrossOrigin(): string
    {
        return $this->isS3() ? 'anonymous' : 'use-credentials';
    }

    /**
     * Storage key for the (legacy, pre-transcode) sibling peaks JSON. Still
     * referenced by Track destroy() to clean up artifacts of a track that was
     * deleted before the transcode finished — once a track has channel rows,
     * its s3_key is null and this method isn't called.
     */
    public function peaksKey(Track $track): string
    {
        return $this->peaksKeyFor($track->s3_key);
    }

    public function peaksKeyFor(string $wavKey): string
    {
        return preg_replace('/\.wav$/i', '.peaks.json', $wavKey);
    }

    /**
     * Owner page: presigned per-channel Opus URL (CORS-clean for the mixer,
     * baked into the page). Shared/local pages always go through the in-app
     * stream route so a revoked share token instantly stops working.
     */
    public function channelStreamUrl(TrackChannel $channel, string $localRoute, bool $shared = false): string
    {
        if ($this->isS3() && ! $shared) {
            return $this->disk()->temporaryUrl($channel->s3_key, now()->addHours(6));
        }

        return $localRoute;
    }

    /** Same rules as {@see channelStreamUrl}, applied to the channel's peaks JSON. */
    public function channelPeaksUrl(TrackChannel $channel, string $localRoute, bool $shared = false): ?string
    {
        if ($channel->peaks_s3_key === null) {
            return null;
        }

        if ($this->isS3() && ! $shared) {
            return $this->disk()->temporaryUrl($channel->peaks_s3_key, now()->addHours(6));
        }

        return $localRoute;
    }

    /**
     * Build the playback response for a single channel's Opus stream. Mirrors
     * {@see streamResponse}: presign-and-redirect on S3, range-aware file on
     * local. Content-Type is fixed to audio/webm — the encoder we run in the
     * transcode job emits WebM-containerised Opus.
     */
    public function channelStreamResponse(TrackChannel $channel): SymfonyResponse
    {
        if ($this->isS3()) {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return redirect()->away($disk->temporaryUrl($channel->s3_key, now()->addMinutes(30)));
        }

        $disk = $this->disk();
        abort_unless($disk->exists($channel->s3_key), 404);

        return response()->file($disk->path($channel->s3_key), [
            'Content-Type' => 'audio/webm',
        ]);
    }

    public function channelPeaksResponse(TrackChannel $channel): SymfonyResponse
    {
        abort_if($channel->peaks_s3_key === null, 404);

        if ($this->isS3()) {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return redirect()->away($disk->temporaryUrl($channel->peaks_s3_key, now()->addMinutes(30)));
        }

        $disk = $this->disk();
        abort_unless($disk->exists($channel->peaks_s3_key), 404);

        return response()->file($disk->path($channel->peaks_s3_key), ['Content-Type' => 'application/json']);
    }
}
