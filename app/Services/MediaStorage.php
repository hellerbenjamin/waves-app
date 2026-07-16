<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventInvite;
use App\Models\Media;
use App\Models\User;
use App\Services\Concerns\InteractsWithS3;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

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
     * Mint an upload target authorised by an event's public share token.
     * Parallel to {@see contribUploadTarget}; the share viewer can upload
     * into the event without minting a separate invite.
     *
     * @return array{url: string, headers: array<string, string>, s3_key: string}
     */
    public function eventShareUploadTarget(Event $event, string $contentType, string $extension): array
    {
        $key = $this->newContribKey($event->id, $extension);

        return $this->signedPut($key, $contentType, fn () => URL::temporarySignedRoute(
            'events.shared.media-upload-put',
            now()->addMinutes(15),
            ['event' => $event->share_token, 'key' => $key],
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

    /** The web-rendition key that sits beside a video (always a faststart MP4). */
    public function playbackKeyFor(string $sourceKey): string
    {
        $dir = dirname($sourceKey);
        $base = pathinfo($sourceKey, PATHINFO_FILENAME);

        return "{$dir}/web/{$base}.mp4";
    }

    /**
     * Copy a stored object down to a local temp file and return its path. The
     * caller owns the file and must unlink it. Used by the transcode job, which
     * reads the whole original and wants a fast, seekable local source rather
     * than a long-lived remote read held open for the entire encode.
     */
    public function downloadToTemp(string $key): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'waves_src_');

        $source = $this->disk()->readStream($key);
        if (! is_resource($source)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to read media object: {$key}");
        }

        $dest = fopen($tmp, 'wb');
        stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);

        return $tmp;
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
     * Stream all of an event's media files as a single ZIP download. Each file
     * is piped from the disk directly into the ZIP entry — nothing is buffered
     * in memory, so the response stays flat regardless of file count or size.
     */
    public function zipDownloadResponse(Event $event, string $zipName): StreamedResponse
    {
        // Eager-load so the closure doesn't hit the DB per file.
        return $this->zipMediaResponse($event->media->all(), $zipName);
    }

    /**
     * Stream an arbitrary set of media items as a single ZIP download — the
     * event-agnostic core of {@see zipDownloadResponse}, reused for collections
     * whose media spans multiple events.
     *
     * @param  iterable<Media>  $media
     */
    public function zipMediaResponse(iterable $media, string $zipName): StreamedResponse
    {
        return response()->stream(function () use ($media) {
            $zip = new ZipStream(outputStream: fopen('php://output', 'wb'));

            foreach ($media as $item) {
                $stream = $this->disk()->readStream($item->s3_key);
                if (! is_resource($stream)) {
                    continue;
                }

                $zip->addFileFromStream(
                    fileName: $item->original_name ?? ('media-'.$item->id),
                    stream: $stream,
                );
            }

            $zip->finish();
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.addslashes($zipName).'"',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Return a string ffmpeg can use as an -i input: a signed URL for S3/R2
     * (which supports range requests, so ffmpeg only fetches what it needs), or
     * the real filesystem path for local disks. The TTL must outlast the whole
     * ffmpeg run, including any reconnects on a slow read, not just the initial
     * request.
     */
    public function ffmpegInput(string $key): string
    {
        if ($this->isS3()) {
            /** @var AwsS3V3Adapter $disk */
            $disk = $this->disk();

            return $disk->temporaryUrl($key, now()->addMinutes(20));
        }

        return $this->disk()->path($key);
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
