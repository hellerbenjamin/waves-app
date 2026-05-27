<?php

namespace App\Services;

use App\Models\EventInvite;
use App\Models\Media;
use App\Models\User;
use App\Services\Concerns\InteractsWithS3;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Where event photos and videos live, and how they are read and written. Shares
 * the S3 multipart/presign plumbing with tracks via {@see InteractsWithS3};
 * the differences are the key namespace (media/…), the open set of image/video
 * MIME types, and the generated thumbnail that sits alongside each object.
 */
class MediaStorage
{
    use InteractsWithS3;

    /**
     * Mint an upload target for a new media file. Mirrors TrackStorage: S3 hands
     * back a presigned PUT, other disks a signed app endpoint that streams to
     * disk. The extension is preserved so the object keeps a sensible name.
     *
     * @return array{url: string, headers: array<string, string>, s3_key: string}
     */
    public function uploadTarget(User $user, string $contentType, string $extension): array
    {
        $key = $this->newMediaKey($user, $extension);

        return $this->signedPut($key, $contentType, fn () => URL::temporarySignedRoute(
            'media.upload-put',
            now()->addMinutes(15),
            ['key' => $key],
        ));
    }

    /**
     * Mint an upload target for an anonymous contribution. Identical to
     * {@see uploadTarget} but keyed under the event (not a user), so the public
     * upload endpoints can authorise from the invite token instead of auth().
     * The local-disk fallback signs the token-scoped contribution put route.
     *
     * @return array{url: string, headers: array<string, string>, s3_key: string}
     */
    public function contribUploadTarget(EventInvite $invite, string $contentType, string $extension): array
    {
        $key = $this->newContribKey($invite->event_id, $extension);

        return $this->signedPut($key, $contentType, fn () => URL::temporarySignedRoute(
            'contribute.upload-put',
            now()->addMinutes(15),
            ['invite' => $invite->token, 'key' => $key],
        ));
    }

    /**
     * The single-PUT upload target for a key. On S3 it's a presigned PUT
     * straight to the bucket; on other disks a signed app endpoint that streams
     * to disk, whose URL the caller supplies (it knows which route guards it).
     *
     * @param  callable(): string  $localUrl
     * @return array{url: string, headers: array<string, string>, s3_key: string}
     */
    private function signedPut(string $key, string $contentType, callable $localUrl): array
    {
        if (! $this->isS3()) {
            return ['url' => $localUrl(), 'headers' => [], 's3_key' => $key];
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
     * Per-user, ULID-named key so ownership is enforceable from the key alone
     * and two uploads never collide. The extension is normalised to a short
     * lowercase token.
     */
    public function newMediaKey(User $user, string $extension): string
    {
        return "media/users/{$user->id}/".(string) Str::ulid().'.'.$this->normalizeExt($extension);
    }

    /**
     * An event-scoped contribution key. Authorisation comes from the invite
     * token plus this prefix, so anonymous uploads never need a user id.
     */
    public function newContribKey(int $eventId, string $extension): string
    {
        return "media/events/{$eventId}/contrib/".(string) Str::ulid().'.'.$this->normalizeExt($extension);
    }

    /** Normalise an extension to a short lowercase token. */
    private function normalizeExt(string $extension): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin');
    }

    /** The thumbnail key that sits beside a media object (always a JPEG). */
    public function thumbKeyFor(string $sourceKey): string
    {
        $dir = dirname($sourceKey);
        $base = pathinfo($sourceKey, PATHINFO_FILENAME);

        return "{$dir}/thumbs/{$base}.jpg";
    }

    /**
     * Stream a media object for inline display. S3 redirects to a short-lived
     * signed URL (R2 honours range requests for video scrubbing); local disks
     * use a range-aware file response.
     */
    public function streamResponse(string $key, ?string $mime = null): SymfonyResponse
    {
        if ($this->isS3()) {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return redirect()->away($disk->temporaryUrl($key, now()->addMinutes(30)));
        }

        $disk = $this->disk();
        abort_unless($disk->exists($key), 404);

        return response()->file($disk->path($key), array_filter([
            'Content-Type' => $mime,
        ]));
    }

    /**
     * Build a download response for a media item: presign an S3 GET that forces
     * Content-Disposition: attachment with the original filename, or fall back
     * to a streamed file download on a local disk.
     */
    public function downloadResponse(Media $media): SymfonyResponse
    {
        $filename = $media->original_name ?: ('media-'.$media->id);

        if ($this->isS3()) {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return redirect()->away($disk->temporaryUrl(
                $media->s3_key,
                now()->addMinutes(15),
                array_filter([
                    'ResponseContentDisposition' => 'attachment; filename="'.addslashes($filename).'"',
                    'ResponseContentType' => $media->mime,
                ]),
            ));
        }

        $disk = $this->disk();
        abort_unless($disk->exists($media->s3_key), 404);

        return response()->download(
            $disk->path($media->s3_key),
            $filename,
            array_filter(['Content-Type' => $media->mime]),
        );
    }

    /**
     * The URL to load a media object (or its thumbnail) from in the page. For
     * an owner's own page an S3 disk is handed a presigned object URL directly;
     * the TTL must outlast a viewing session since URLs are baked in at render
     * time.
     *
     * A public share page must stay revocable, so it never bakes in a
     * long-lived presigned URL: it always uses the given in-app route, which
     * mints a fresh short-lived URL per request and 404s the moment the share
     * token is cleared. Local disks always use the route regardless.
     */
    public function objectUrl(?string $key, string $localRoute, bool $shared = false): ?string
    {
        if ($key === null) {
            return null;
        }

        if ($this->isS3() && ! $shared) {
            return $this->disk()->temporaryUrl($key, now()->addHours(6));
        }

        return $localRoute;
    }
}
