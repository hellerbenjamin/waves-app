<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\TrackStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cut a single [start, end] range out of a parent track and persist the result
 * as an independent child track. Dispatched one-per-region so a long source
 * fan-outs across queue workers and a single bad segment doesn't fail the
 * whole split.
 *
 * The user's region bounds are taken as gospel — we deliberately do not
 * re-trim to the underlying loud region, because the user may have
 * intentionally included a lead-in or tail.
 */
class SplitTrackSegment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * @param  array{id?: string, start: float, end: float, name?: string}  $region
     */
    public function __construct(
        public Track $parent,
        public array $region,
    ) {}

    public function handle(TrackStorage $storage): void
    {
        $start = (float) $this->region['start'];
        $end = (float) $this->region['end'];
        $name = (string) ($this->region['name'] ?? 'Part');

        if ($end <= $start) {
            throw new RuntimeException("Invalid region: end ({$end}) must exceed start ({$start}).");
        }

        [$source, $remote] = $this->source();

        // Cut to a local temp file, then upload. Streaming ffmpeg's stdout
        // straight into S3 would need a chunked uploader; for a per-song clip
        // (typically tens to a few hundred MB) a temp file is simpler and the
        // disk cost is bounded by one segment at a time.
        $tmp = tempnam(sys_get_temp_dir(), 'split-').'.wav';

        try {
            $this->cut($source, $tmp, $start, $end, $remote);

            $childKey = $storage->newTrackKey($this->parent->user);

            $stream = fopen($tmp, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Unable to open split output.');
            }
            try {
                $storage->put($childKey, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $child = $this->parent->user->tracks()->create([
                'parent_track_id' => $this->parent->id,
                'event_id' => $this->parent->event_id,
                's3_key' => $childKey,
                'original_name' => $this->childName($name),
                'mime' => 'audio/wav',
                'size' => filesize($tmp) ?: null,
            ]);

            // The child's peaks are independent: extract them so the new card
            // becomes playable in the list view as soon as the job finishes.
            ExtractPeaks::dispatch($child);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * `-ss` before `-i` is a fast seek (input-side); ffmpeg seeks via HTTP
     * range requests against R2 instead of decoding from t=0. `-c copy` is
     * lossless and instant for WAV PCM — the container is just headered samples
     * so stream-copy stays sample-accurate at the requested timestamps.
     */
    private function cut(string $source, string $out, float $start, float $end, bool $remote): void
    {
        $command = array_merge(
            ['ffmpeg', '-nostdin', '-v', 'error', '-y'],
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            [
                '-ss', $this->fmt($start),
                '-to', $this->fmt($end),
                '-i', $source,
                '-map', '0:a:0',
                '-c', 'copy',
                $out,
            ],
        );

        $result = Process::timeout($this->timeout)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg split failed: '.trim($result->errorOutput()));
        }
    }

    private function fmt(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }

    /**
     * Default file name: just "{region name}.wav". The parent name is
     * deliberately not prepended — region names are already chosen to identify
     * the song, and prefixing the show/source name only makes the track list
     * harder to scan.
     */
    private function childName(string $regionName): string
    {
        return $regionName.'.wav';
    }

    /** @return array{0:string,1:bool} */
    private function source(): array
    {
        $diskName = config('filesystems.tracks_disk');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return [$disk->path($this->parent->s3_key), false];
        }

        $url = $disk->temporaryUrl($this->parent->s3_key, now()->addSeconds($this->timeout + 600));

        return [$url, true];
    }
}
