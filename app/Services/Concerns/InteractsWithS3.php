<?php

namespace App\Services\Concerns;

use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * The shared S3 mechanics behind both audio tracks and event media: multipart
 * signing/finalising for multi-gigabyte direct-to-bucket uploads, plus the
 * low-level read/write/exists primitives. Both back ends live on the same disk
 * (the R2 bucket); only the object key namespace and the streaming/MIME
 * concerns differ, and those stay in the per-type services.
 */
trait InteractsWithS3
{
    /**
     * Begin an S3 multipart upload. The browser then PUTs each part directly to
     * S3 via the per-part URLs from signPart(); only valid on an S3 disk.
     */
    public function createMultipartUpload(string $key, string $contentType): string
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        return (string) $result['UploadId'];
    }

    /**
     * Presign a single UploadPart request. Content-Type is intentionally not
     * signed: the browser sets its own on the part body, and unsigned headers
     * are ignored by SigV4 verification.
     */
    public function signPart(string $key, string $uploadId, int $partNumber): string
    {
        $command = $this->client()->getCommand('UploadPart', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $this->client()
            ->createPresignedRequest($command, '+15 minutes')
            ->getUri();
    }

    /**
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);
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

    public function putContents(string $key, string $contents): void
    {
        $this->disk()->put($key, $contents);
    }

    public function get(string $key): ?string
    {
        return $this->disk()->get($key);
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.tracks_disk'));
    }

    protected function client(): S3Client
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = $this->disk();

        return $disk->getClient();
    }

    protected function bucket(): string
    {
        return (string) config('filesystems.disks.'.config('filesystems.tracks_disk').'.bucket');
    }

    protected function isS3(): bool
    {
        return $this->driver() === 's3';
    }

    protected function driver(): string
    {
        return (string) config('filesystems.disks.'.config('filesystems.tracks_disk').'.driver');
    }
}
