<?php

namespace App\Jobs;

use App\Models\Track;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ExtractPeaks implements ShouldQueue
{
    use Queueable;

    /**
     * Roughly how many min/max pairs to emit per second of audio. The total is
     * capped so a long track stays a reasonable JSON payload while a short one
     * still gets enough detail to draw a useful waveform.
     */
    private const int SECOND_PER_SECOND = 20;

    private const int MAX_PAIRS = 8000;

    public int $timeout = 1800;

    public function __construct(public Track $track) {}

    public function handle(): void
    {
        // Queue worker enforces $timeout; PHP's own max_execution_time (30s on
        // Forge) would otherwise kill the decode loop mid-stream.
        set_time_limit(0);

        [$source, $remote] = $this->source();

        $probe = $this->probe($source, $remote);

        $channels = max(1, (int) ($probe['channels'] ?? 1));
        $sampleRate = max(1, (int) ($probe['sample_rate'] ?? 44100));
        $duration = (float) ($probe['duration'] ?? 0.0);

        $pairs = (int) max(1, min(self::MAX_PAIRS, round($duration * self::SECOND_PER_SECOND)));
        $totalFrames = max(1, (int) round($duration * $sampleRate));
        $framesPerPair = max(1, (int) ceil($totalFrames / $pairs));

        $this->track->update([
            'duration_seconds' => $duration,
            'peaks' => [
                'channels' => $this->extract($source, $channels, $framesPerPair, $pairs, $remote),
                'sample_rate' => $sampleRate,
            ],
        ]);
    }

    /**
     * Decode the file to interleaved 16-bit PCM and reduce it to one
     * [max, min, max, min, …] envelope per channel, normalised to [-1, 1].
     *
     * @return list<list<float>>
     */
    private function extract(string $path, int $channels, int $framesPerPair, int $pairs, bool $remote = false): array
    {
        $command = array_merge(
            ['ffmpeg', '-v', 'error'],
            // Reading a multi-GB object straight from R2 over HTTP: ride out
            // transient drops during the long sequential decode instead of
            // failing the whole job.
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            ['-i', $path, '-map', '0:a:0', '-f', 's16le', '-acodec', 'pcm_s16le', '-'],
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start ffmpeg.');
        }

        // Per channel: a flat list of interleaved max/min values, pre-sized so
        // gaps (e.g. a truncated stream) stay flat rather than shifting later
        // pairs out of position.
        $data = array_fill(0, $channels, array_fill(0, $pairs * 2, 0.0));
        $max = array_fill(0, $channels, null);
        $min = array_fill(0, $channels, null);

        $frameSize = 2 * $channels; // 16-bit samples, one per channel
        $buffer = '';
        $frameIndex = 0;

        stream_set_blocking($pipes[2], false);

        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 1 << 16);

            if ($chunk === false || $chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $whole = intdiv(strlen($buffer), $frameSize) * $frameSize;

            if ($whole === 0) {
                continue;
            }

            $samples = unpack('s*', substr($buffer, 0, $whole));
            $buffer = substr($buffer, $whole);

            // unpack() is 1-indexed; samples arrive interleaved by channel.
            $i = 1;
            $count = count($samples);

            while ($i <= $count) {
                $pair = intdiv($frameIndex, $framesPerPair);

                if ($pair >= $pairs) {
                    break 2; // produced everything the payload can hold
                }

                for ($c = 0; $c < $channels; $c++) {
                    $value = $samples[$i + $c] / 32768.0;

                    if ($max[$c] === null || $value > $max[$c]) {
                        $max[$c] = $value;
                    }
                    if ($min[$c] === null || $value < $min[$c]) {
                        $min[$c] = $value;
                    }
                }

                $i += $channels;
                $frameIndex++;

                // The pair is complete once we cross its frame boundary.
                if ($frameIndex % $framesPerPair === 0) {
                    $this->flushPair($data, $max, $min, $channels, $pair);
                }
            }
        }

        // Flush the final, partially filled pair.
        $lastPair = intdiv(max(0, $frameIndex - 1), $framesPerPair);
        if ($lastPair < $pairs && $max[0] !== null && ($frameIndex % $framesPerPair) !== 0) {
            $this->flushPair($data, $max, $min, $channels, $lastPair);
        }

        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            is_resource($pipe) && fclose($pipe);
        }

        if (proc_close($process) !== 0) {
            throw new RuntimeException('ffmpeg failed: '.trim((string) $stderr));
        }

        return array_map('array_values', $data);
    }

    /**
     * @param  list<list<float>>  $data
     * @param  list<float|null>  $max
     * @param  list<float|null>  $min
     */
    private function flushPair(array &$data, array &$max, array &$min, int $channels, int $pair): void
    {
        for ($c = 0; $c < $channels; $c++) {
            $data[$c][$pair * 2] = round($max[$c] ?? 0.0, 4);
            $data[$c][$pair * 2 + 1] = round($min[$c] ?? 0.0, 4);
            $max[$c] = null;
            $min[$c] = null;
        }
    }

    /**
     * @return array{channels:int,sample_rate:int,duration:float}
     */
    private function probe(string $path, bool $remote = false): array
    {
        $result = Process::run(array_merge(
            ['ffprobe', '-v', 'error'],
            // ffprobe only reads the WAV header (which carries duration), but
            // tolerate a dropped HTTP connection while it does.
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            [
                '-select_streams', 'a:0',
                '-show_entries', 'stream=channels,sample_rate:format=duration',
                '-of', 'json', $path,
            ],
        ));

        if (! $result->successful()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput()));
        }

        $json = json_decode($result->output(), true);
        $stream = $json['streams'][0] ?? [];

        return [
            'channels' => (int) ($stream['channels'] ?? 0),
            'sample_rate' => (int) ($stream['sample_rate'] ?? 0),
            'duration' => (float) ($json['format']['duration'] ?? 0.0),
        ];
    }

    /**
     * Resolve a URI ffmpeg/ffprobe can read, and whether it's remote. Local
     * disks expose the file path directly. Remote disks (R2/S3) hand ffmpeg a
     * presigned URL so it streams the object over HTTP — decoding a multi-GB
     * file in place rather than first copying the whole thing to a temp file.
     *
     * @return array{0:string,1:bool} [uri, isRemote]
     */
    private function source(): array
    {
        $diskName = config('filesystems.tracks_disk');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return [$disk->path($this->track->s3_key), false];
        }

        // Sign for longer than the job can run so the URL outlasts the decode of
        // a large file (the read is sequential and can take many minutes).
        $url = $disk->temporaryUrl($this->track->s3_key, now()->addSeconds($this->timeout + 600));

        return [$url, true];
    }
}
