<?php

namespace App\Jobs;

use App\Models\Track;
use App\Models\TrackChannel;
use App\Services\TrackStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Split the source multi-channel WAV behind a Track into one mono Opus (WebM)
 * stream per channel via ffmpeg, persist a TrackChannel row for each, attach a
 * per-channel peaks envelope, and delete the source WAV once every channel is
 * safely in R2. The player loads the channels and runs N parallel <audio>
 * elements through its Web Audio mixer — see the throwaway /sync-test page
 * for the sub-millisecond drift result that justified this architecture.
 *
 * Per-channel ffmpeg invocation uses `asplit` to fan one channel extraction
 * into two outputs: the encoded Opus on disk and a raw PCM stream on stdout
 * that we scan inline for peaks. This keeps the source decode to one pass per
 * channel instead of two.
 */
class TranscodeTrackToChannels implements ShouldQueue
{
    use Queueable;

    /** Per-second peaks resolution for the per-channel envelope. */
    private const int PEAKS_PER_SECOND = 20;

    private const int MAX_PEAK_PAIRS = 8000;

    /** Mono Opus VBR target. 96 kbps is transparent on instrument/vocal tracks. */
    private const int OPUS_BITRATE = 96_000;

    /** Opus's native sample rate; resampled by libopus if the source isn't 48k. */
    private const int PEAK_SAMPLE_RATE = 48_000;

    public int $timeout = 3600;

    public function __construct(public Track $track) {}

    public function handle(TrackStorage $storage): void
    {
        // PHP's own max_execution_time (30 s by default on Forge) would kill a
        // multi-channel decode mid-stream; the queue worker already enforces
        // $timeout above.
        set_time_limit(0);

        $sourceKey = $this->track->s3_key;
        if ($sourceKey === null) {
            // Nothing to transcode from. Treat as a no-op rather than a hard
            // error so a re-queued job after a successful run doesn't blow up.
            return;
        }

        [$source, $remote] = $this->source($sourceKey);
        $probe = $this->probe($source, $remote);
        $channels = max(1, (int) $probe['channels']);
        $sampleRate = max(1, (int) $probe['sample_rate']);
        $duration = (float) $probe['duration'];

        // Cache the per-channel label list so we don't decode the JSON in the
        // tight loop. `default_mix` is the authoritative source for user-given
        // labels; `channel_labels` is the legacy path.
        $labels = $this->resolveChannelLabels($channels);

        // Each channel writes one Opus and one peaks JSON. If any of them
        // fails we abort the whole job (and leave already-uploaded blobs as
        // orphans — cheap to clean up later).
        $created = [];

        try {
            for ($c = 0; $c < $channels; $c++) {
                $opusKey = $this->channelKey($c, 'webm');
                $peaksKey = $this->channelKey($c, 'peaks.json');

                [$opusPath, $peaks] = $this->transcodeChannel(
                    $source, $remote, $c, $channels, $sampleRate, $duration,
                );

                try {
                    $stream = fopen($opusPath, 'rb');
                    if (! is_resource($stream)) {
                        throw new RuntimeException("Could not open transcoded channel {$c}.");
                    }
                    try {
                        $storage->put($opusKey, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $opusSize = filesize($opusPath) ?: null;
                } finally {
                    @unlink($opusPath);
                }

                $storage->putContents($peaksKey, json_encode([
                    'sample_rate' => self::PEAK_SAMPLE_RATE,
                    'peaks' => $peaks,
                ]));

                $created[] = TrackChannel::create([
                    'track_id' => $this->track->id,
                    'channel_index' => $c,
                    's3_key' => $opusKey,
                    'peaks_s3_key' => $peaksKey,
                    'label' => $labels[$c] ?? null,
                    'size' => $opusSize,
                ]);
            }

            DB::transaction(function () use ($duration, $sampleRate, $channels) {
                $this->track->update([
                    'duration_seconds' => $duration,
                    'channels_count' => $channels,
                    'sample_rate' => $sampleRate,
                    // Source WAV is gone after the cleanup below — null out the
                    // key so nothing in the app still thinks it can stream it.
                    's3_key' => null,
                ]);
            });

            // Best-effort cleanup of the source WAV and any sibling artifacts
            // from the old pipeline. Orphaned bytes are cheap; an orphaned
            // peaks row would be worse, but we haven't created one yet.
            try {
                $storage->delete($sourceKey);
                $storage->delete($storage->peaksKeyFor($sourceKey));
            } catch (\Throwable) {
                // ignore — the user-visible state is correct either way
            }
        } catch (\Throwable $e) {
            // Roll back any channel rows we created in this run so a re-queue
            // starts from a clean slate. Orphaned R2 objects from a partial
            // run are out of scope; cleanup belongs to a sweeper.
            foreach ($created as $row) {
                $row->delete();
            }
            throw $e;
        }
    }

    /**
     * Run ffmpeg for one channel: `asplit` fans the mono extraction into a
     * file output (Opus/WebM) and a stdout pipe (s16le PCM at 48 kHz) that we
     * scan for peaks while ffmpeg keeps running. Returns the local Opus path
     * the caller is responsible for uploading and deleting.
     *
     * @return array{0:string,1:array<int,float>}  [tempOpusPath, peaks]
     */
    private function transcodeChannel(
        string $source,
        bool $remote,
        int $channelIndex,
        int $channels,
        int $sampleRate,
        float $duration,
    ): array {
        $tempOpus = tempnam(sys_get_temp_dir(), 'tx-opus-').'.webm';

        // Build the filtergraph: pull this channel as mono, then asplit it so
        // one branch encodes to Opus and the other becomes raw PCM on stdout.
        $pan = "[0:a]pan=mono|c0=c{$channelIndex},asplit=2[opus][pcm]";

        $command = array_merge(
            ['ffmpeg', '-nostdin', '-v', 'error', '-y'],
            $remote ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '30'] : [],
            [
                '-i', $source,
                '-filter_complex', $pan,
                '-map', '[opus]',
                '-c:a', 'libopus',
                '-b:a', (string) self::OPUS_BITRATE,
                '-application', 'audio',
                '-frame_duration', '20',
                '-f', 'webm',
                $tempOpus,
                '-map', '[pcm]',
                '-ac', '1',
                '-ar', (string) self::PEAK_SAMPLE_RATE,
                '-f', 's16le',
                'pipe:1',
            ],
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            @unlink($tempOpus);
            throw new RuntimeException('Unable to start ffmpeg.');
        }

        try {
            $peaks = $this->readPeaksFromStdout($pipes[1], $duration);

            $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                is_resource($pipe) && fclose($pipe);
            }
            $exit = proc_close($process);

            if ($exit !== 0) {
                @unlink($tempOpus);
                throw new RuntimeException("ffmpeg channel {$channelIndex} failed: ".trim((string) $stderr));
            }
        } catch (\Throwable $e) {
            // proc_close might have already run, but it's safe to call again
            // through is_resource(); be defensive about leftover temp files.
            foreach ($pipes as $pipe) {
                is_resource($pipe) && fclose($pipe);
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            @unlink($tempOpus);
            throw $e;
        }

        return [$tempOpus, $peaks];
    }

    /**
     * Bucket the s16le PCM stream into a flat [max, min, max, min, …] envelope
     * normalised to [-1, 1]. Pre-sized so a truncated stream stays
     * positionally correct rather than shifting later pairs.
     *
     * @param  resource  $pipe
     * @return array<int, float>
     */
    private function readPeaksFromStdout($pipe, float $duration): array
    {
        $pairs = (int) max(1, min(self::MAX_PEAK_PAIRS, round($duration * self::PEAKS_PER_SECOND)));
        $totalFrames = max(1, (int) round($duration * self::PEAK_SAMPLE_RATE));
        $framesPerPair = max(1, (int) ceil($totalFrames / $pairs));

        $out = array_fill(0, $pairs * 2, 0.0);
        $accMax = null;
        $accMin = null;

        $frameSize = 2; // mono, 16-bit
        $buffer = '';
        $frameIndex = 0;

        while (! feof($pipe)) {
            $chunk = fread($pipe, 1 << 16);
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

            $count = count($samples);
            for ($i = 1; $i <= $count; $i++) {
                $pair = intdiv($frameIndex, $framesPerPair);
                if ($pair >= $pairs) {
                    break 2;
                }

                $value = $samples[$i] / 32768.0;
                if ($accMax === null || $value > $accMax) {
                    $accMax = $value;
                }
                if ($accMin === null || $value < $accMin) {
                    $accMin = $value;
                }

                $frameIndex++;
                if ($frameIndex % $framesPerPair === 0) {
                    $out[$pair * 2] = round($accMax ?? 0.0, 4);
                    $out[$pair * 2 + 1] = round($accMin ?? 0.0, 4);
                    $accMax = null;
                    $accMin = null;
                }
            }
        }

        // Flush the trailing partial bucket so the tail isn't dropped.
        $lastPair = intdiv(max(0, $frameIndex - 1), $framesPerPair);
        if ($lastPair < $pairs && $accMax !== null && ($frameIndex % $framesPerPair) !== 0) {
            $out[$lastPair * 2] = round($accMax, 4);
            $out[$lastPair * 2 + 1] = round($accMin ?? 0.0, 4);
        }

        return $out;
    }

    /**
     * ffprobe for channel count, sample rate, and duration. Mirrors the
     * pattern in ExtractPeaks for consistent failure semantics.
     *
     * @return array{channels:int,sample_rate:int,duration:float}
     */
    private function probe(string $path, bool $remote): array
    {
        $result = Process::run(array_merge(
            ['ffprobe', '-v', 'error'],
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
     * Channel label lookup: prefer `default_mix[i].label`, then `channel_labels[i]`,
     * else null (the UI falls back to "Channel N").
     *
     * @return array<int, string|null>
     */
    private function resolveChannelLabels(int $channels): array
    {
        $labels = array_fill(0, $channels, null);

        $defaultMix = $this->track->default_mix ?? null;
        if (is_array($defaultMix)) {
            foreach ($defaultMix as $i => $row) {
                if ($i >= $channels) break;
                $label = is_array($row) ? ($row['label'] ?? null) : null;
                if (is_string($label) && $label !== '') {
                    $labels[$i] = $label;
                }
            }
        }

        $channelLabels = $this->track->channel_labels ?? null;
        if (is_array($channelLabels)) {
            foreach ($channelLabels as $i => $label) {
                if ($i >= $channels) break;
                if ($labels[$i] === null && is_string($label) && $label !== '') {
                    $labels[$i] = $label;
                }
            }
        }

        return $labels;
    }

    /** Per-channel object key, namespaced by track id and owner. */
    private function channelKey(int $channelIndex, string $extension): string
    {
        $userId = (int) $this->track->user_id;
        $trackId = (int) $this->track->id;
        $pad = str_pad((string) $channelIndex, 2, '0', STR_PAD_LEFT);

        return "users/{$userId}/tracks/{$trackId}/ch{$pad}.{$extension}";
    }

    /**
     * Resolve a source the ffmpeg child can read: local path on a local disk,
     * presigned URL on S3. Sign for longer than the job's timeout so the URL
     * outlasts the sequential decode of a multi-gig file.
     *
     * @return array{0:string,1:bool} [uri, isRemote]
     */
    private function source(string $sourceKey): array
    {
        $diskName = config('filesystems.tracks_disk');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return [$disk->path($sourceKey), false];
        }

        $url = $disk->temporaryUrl($sourceKey, now()->addSeconds($this->timeout + 600));

        return [$url, true];
    }
}
