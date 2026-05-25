<?php

namespace App\Services;

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

        if (! $this->isS3()) {
            return [
                'url' => URL::temporarySignedRoute(
                    'media.upload-put',
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
     * Per-user, ULID-named key so ownership is enforceable from the key alone
     * and two uploads never collide. The extension is normalised to a short
     * lowercase token.
     */
    public function newMediaKey(User $user, string $extension): string
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin');

        return "media/users/{$user->id}/".(string) Str::ulid().'.'.$ext;
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
