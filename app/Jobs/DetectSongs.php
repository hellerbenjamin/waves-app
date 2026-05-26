<?php

namespace App\Jobs;

use App\Models\Track;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Detect the song boundaries inside a long track. Runs ffmpeg's silencedetect
 * filter against the (streamed) source, inverts the silence ranges into "loud"
 * ranges, and stores the result on the parent track as a {@see Track::$split_proposal}
 * the UI can then review, edit, and commit.
 *
 * The output is only a suggestion: the user follows up by adjusting region
 * edges in the waveform and posts the final regions back. We never mutate the
 * source bytes here.
 */
class DetectSongs implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public Track $track,
        public float $silenceDb = -40.0,
        public float $minSilence = 1.5,
        public float $minRegion = 30.0,
    ) {}

    public function handle(): void
    {
        [$source, $remote] = $this->source();

        $silences = $this->detectSilences($source, $remote);
        $duration = (float) ($this->track->duration_seconds ?: $this->probeDuration($source, $remote));

        $regions = $this->invert($silences, $duration);

        // Drop noise: a cough or short clap between songs shouldn't become its
        // own region. Anything tighter than minRegion is folded into nothing.
        $regions = array_values(array_filter(
            $regions,
            fn ($r) => ($r['end'] - $r['start']) >= $this->minRegion,
        ));

        // Re-index with stable client-side ids and default names so the UI has
        // something to render before the user renames anything.
        $regions = array_map(
            fn ($r, $i) => [
                'id' => 'r'.($i + 1),
                'start' => round($r['start'], 3),
                'end' => round($r['end'], 3),
                'name' => 'Part '.($i + 1),
            ],
            $regions,
            array_keys($regions),
        );

        $this->track->update([
            'split_proposal' => [
                'status' => 'ready',
                'params' => [
                    'silence_db' => $this->silenceDb,
                    'min_silence' => $this->minSilence,
                    'min_region' => $this->minRegion,
                ],
                'regions' => $regions,
                'detected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Run silencedetect and parse its stderr into [start, end] pairs in seconds.
     * silencedetect emits two lines per silence: a `silence_start: T` when it
     * crosses below the threshold, and a `silence_end: T | silence_duration: D`
     * when it crosses back up. We pair them in order.
     *
     * @return list<array{start: float, end: float}>
     */
    private function detectSilences(string $path, bool $remote): array
    {
        $filter = sprintf(
            'silencedetect=noise=%sdB:d=%s',
            // ffmpeg accepts a negative dB value directly.
            rtrim(rtrim(number_format($this->silenceDb, 3, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($this->minSilence, 3, '.', ''), '0'), '.'),
        );

        $command = array_merge(
            ['ffmpeg', '-nostats', '-v', 'info'],
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            ['-i', $path, '-map', '0:a:0', '-af', $filter, '-f', 'null', '-'],
        );

        // silencedetect writes its findings to stderr; route stdout to /dev/null
        // and capture stderr to parse. We let ffmpeg read the entire stream — on
        // a multi-GB file this is bounded by network throughput, hence $timeout.
        $result = Process::timeout($this->timeout)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg silencedetect failed: '.trim($result->errorOutput()));
        }

        $output = $result->errorOutput();
        $silences = [];
        $pendingStart = null;

        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (preg_match('/silence_start:\s*(-?\d+(?:\.\d+)?)/', $line, $m)) {
                $pendingStart = (float) $m[1];
            } elseif (preg_match('/silence_end:\s*(-?\d+(?:\.\d+)?)/', $line, $m)) {
                if ($pendingStart !== null) {
                    $silences[] = ['start' => max(0.0, $pendingStart), 'end' => (float) $m[1]];
                    $pendingStart = null;
                }
            }
        }

        return $silences;
    }

    /**
     * Convert silence intervals into the gaps between them — i.e. the loud
     * stretches that are candidate songs.
     *
     * @param  list<array{start: float, end: float}>  $silences
     * @return list<array{start: float, end: float}>
     */
    private function invert(array $silences, float $duration): array
    {
        $regions = [];
        $cursor = 0.0;

        foreach ($silences as $s) {
            if ($s['start'] > $cursor) {
                $regions[] = ['start' => $cursor, 'end' => $s['start']];
            }
            $cursor = max($cursor, $s['end']);
        }

        if ($duration > $cursor) {
            $regions[] = ['start' => $cursor, 'end' => $duration];
        }

        return $regions;
    }

    private function probeDuration(string $path, bool $remote): float
    {
        $result = Process::run(array_merge(
            ['ffprobe', '-v', 'error'],
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            ['-show_entries', 'format=duration', '-of', 'json', $path],
        ));

        if (! $result->successful()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput()));
        }

        $json = json_decode($result->output(), true);

        return (float) ($json['format']['duration'] ?? 0.0);
    }

    /** @return array{0:string,1:bool} */
    private function source(): array
    {
        $diskName = config('filesystems.tracks_disk');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return [$disk->path($this->track->s3_key), false];
        }

        $url = $disk->temporaryUrl($this->track->s3_key, now()->addSeconds($this->timeout + 600));

        return [$url, true];
    }
}
