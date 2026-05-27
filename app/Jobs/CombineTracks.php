<?php

namespace App\Jobs;

use App\Models\Track;
use App\Models\User;
use App\Services\TrackStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stitch a list of the owner's WAV tracks into one new track in the given
 * order, then delete the sources. The user picked "delete originals", so we
 * commit to that path: success removes the inputs, failure leaves everything
 * intact and surfaces in the queue log.
 *
 * Sample rate and channel count were checked at the request level (cheap, from
 * the cached columns on tracks). Bit depth lives only inside each WAV header,
 * so we probe each source here with ffprobe and refuse to mix mismatched
 * depths — concat with `-c copy` is sample-accurate but assumes byte-identical
 * PCM frames.
 */
class CombineTracks implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /**
     * @param  list<int>  $sourceIds  source track ids in the join order chosen by the user
     */
    public function __construct(
        public User $user,
        public array $sourceIds,
        public string $name,
        public ?int $eventId,
    ) {}

    public function handle(TrackStorage $storage): void
    {
        set_time_limit(0);

        // Re-fetch in the requested order — preserveOrder() keeps the user's
        // drag-sorted list intact through the DB round trip.
        $sources = Track::query()
            ->whereIn('id', $this->sourceIds)
            ->where('user_id', $this->user->id)
            ->get()
            ->keyBy('id');

        $ordered = collect($this->sourceIds)
            ->map(fn ($id) => $sources->get($id))
            ->filter()
            ->values();

        if ($ordered->count() < 2) {
            throw new RuntimeException('Combine needs at least two source tracks.');
        }

        $this->assertCompatible($ordered, $storage);

        // Download every source to a local temp file. ffmpeg's concat demuxer
        // can read https inputs in theory, but it expects each input to be
        // seekable — and a few hundred MB per file is cheap disk on the worker.
        $tempInputs = [];
        $tempList = tempnam(sys_get_temp_dir(), 'combine-list-').'.txt';
        $tempOut = tempnam(sys_get_temp_dir(), 'combine-out-').'.wav';

        try {
            foreach ($ordered as $i => $track) {
                $tempInputs[$i] = $this->fetchToLocal($track, $storage);
            }

            // Concat list format: one "file '<path>'" per line. Paths are
            // single-quoted; ffmpeg's escape rule for a literal single quote
            // inside the path is '\'' — we never produce one (tempnam paths
            // are safe), but be explicit anyway.
            $listBody = '';
            foreach ($tempInputs as $path) {
                $escaped = str_replace("'", "'\\''", $path);
                $listBody .= "file '{$escaped}'\n";
            }
            file_put_contents($tempList, $listBody);

            $this->concat($tempList, $tempOut);

            $childKey = $storage->newTrackKey($this->user);
            $stream = fopen($tempOut, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('Unable to open combined output.');
            }
            try {
                $storage->put($childKey, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // Combined row + source deletion in one transaction so a partial
            // failure mid-cleanup doesn't leave an unreferenced new row.
            // Storage deletion is best-effort outside the transaction.
            $combinedSize = filesize($tempOut) ?: null;

            $combined = DB::transaction(function () use ($childKey, $combinedSize, $ordered) {
                $combined = $this->user->tracks()->create([
                    'event_id' => $this->eventId,
                    's3_key' => $childKey,
                    'original_name' => $this->finalName(),
                    'mime' => 'audio/wav',
                    'size' => $combinedSize,
                ]);

                Track::query()
                    ->whereIn('id', $ordered->pluck('id'))
                    ->delete();

                return $combined;
            });

            // Delete the source objects from storage now that the rows are
            // gone. Best-effort: an orphaned object is cheap; an orphaned
            // row would be worse, and the transaction prevents that.
            foreach ($ordered as $track) {
                try {
                    $storage->delete($track->s3_key);
                    $storage->delete($storage->peaksKey($track));
                } catch (\Throwable) {
                    // ignore — bytes will linger but the user-visible state is correct
                }
            }

            ExtractPeaks::dispatch($combined);
        } finally {
            foreach ($tempInputs as $path) {
                @unlink($path);
            }
            @unlink($tempList);
            @unlink($tempOut);
        }
    }

    /**
     * Probe each source with ffprobe and require an exact format match across
     * codec, sample rate, bit depth, and channel count. The request layer has
     * already ruled out a sample-rate or channel mismatch from the cached
     * columns; this catches the bit-depth case that isn't recorded there.
     *
     * @param  \Illuminate\Support\Collection<int, Track>  $tracks
     */
    private function assertCompatible($tracks, TrackStorage $storage): void
    {
        $expected = null;

        foreach ($tracks as $track) {
            [$uri, $remote] = $this->sourceFor($track, $storage);
            $info = $this->probe($uri, $remote);

            if ($expected === null) {
                $expected = $info;
                continue;
            }

            if ($info !== $expected) {
                throw new RuntimeException(sprintf(
                    'Cannot combine "%s": format %s does not match the first track\'s %s.',
                    $track->original_name,
                    json_encode($info),
                    json_encode($expected),
                ));
            }
        }
    }

    /**
     * @return array{codec:string,sample_rate:int,channels:int,sample_fmt:string,bits_per_sample:int}
     */
    private function probe(string $path, bool $remote): array
    {
        $result = Process::run(array_merge(
            ['ffprobe', '-v', 'error'],
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            [
                '-select_streams', 'a:0',
                '-show_entries', 'stream=codec_name,sample_rate,channels,sample_fmt,bits_per_sample,bits_per_raw_sample',
                '-of', 'json', $path,
            ],
        ));

        if (! $result->successful()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput()));
        }

        $stream = (json_decode($result->output(), true)['streams'][0] ?? []);

        // ffprobe reports bits_per_sample as 0 for some codecs; fall back to
        // bits_per_raw_sample, which carries the WAV header's bitsPerSample.
        $bits = (int) ($stream['bits_per_sample'] ?? 0);
        if ($bits === 0) {
            $bits = (int) ($stream['bits_per_raw_sample'] ?? 0);
        }

        return [
            'codec' => (string) ($stream['codec_name'] ?? ''),
            'sample_rate' => (int) ($stream['sample_rate'] ?? 0),
            'channels' => (int) ($stream['channels'] ?? 0),
            'sample_fmt' => (string) ($stream['sample_fmt'] ?? ''),
            'bits_per_sample' => $bits,
        ];
    }

    /**
     * Pull a source to a local temp file. On an S3 disk we presign a GET and
     * stream-copy via ffmpeg's HTTPS reader (with reconnect for transient
     * drops); on a local disk we just symlink-equivalent the path through
     * fopen — both end with a seekable local WAV ready for the concat demuxer.
     */
    private function fetchToLocal(Track $track, TrackStorage $storage): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'combine-in-').'.wav';

        $diskName = config('filesystems.tracks_disk');
        $disk = Storage::disk($diskName);

        if (! $storage->exists($track->s3_key)) {
            @unlink($tmp);
            throw new RuntimeException("Source missing in storage: {$track->original_name}");
        }

        $in = $disk->readStream($track->s3_key);
        if (! is_resource($in)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to read source: {$track->original_name}");
        }

        $out = fopen($tmp, 'wb');
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $tmp;
    }

    /**
     * Stream-copy concat: with identical PCM frames across inputs the output
     * is just header + concatenated sample data, sample-accurate and instant.
     * `-safe 0` lets the list file reference absolute paths.
     */
    private function concat(string $listPath, string $out): void
    {
        $result = Process::timeout($this->timeout)->run([
            'ffmpeg', '-nostdin', '-v', 'error', '-y',
            '-f', 'concat', '-safe', '0',
            '-i', $listPath,
            '-map', '0:a:0',
            '-c', 'copy',
            $out,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg combine failed: '.trim($result->errorOutput()));
        }
    }

    /** Append ".wav" iff the user didn't include it. */
    private function finalName(): string
    {
        return preg_match('/\.wav$/i', $this->name) ? $this->name : $this->name.'.wav';
    }

    /** @return array{0:string,1:bool} */
    private function sourceFor(Track $track, TrackStorage $storage): array
    {
        $diskName = config('filesystems.tracks_disk');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return [$disk->path($track->s3_key), false];
        }

        $url = $disk->temporaryUrl($track->s3_key, now()->addSeconds($this->timeout + 600));

        return [$url, true];
    }
}
