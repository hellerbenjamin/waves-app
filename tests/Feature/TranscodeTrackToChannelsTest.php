<?php

namespace Tests\Feature;

use App\Jobs\TranscodeTrackToChannels;
use App\Models\Track;
use App\Models\User;
use App\Services\TrackStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class TranscodeTrackToChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder;
        if (! $finder->find('ffmpeg') || ! $finder->find('ffprobe')) {
            $this->markTestSkipped('ffmpeg/ffprobe not available.');
        }
    }

    public function test_it_explodes_a_multichannel_wav_into_per_channel_opus_with_peaks(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $key = "users/{$user->id}/source.wav";
        $this->generateTone(Storage::disk('local')->path($key), seconds: 2, channels: 3);

        $track = Track::factory()->for($user)->create([
            's3_key' => $key,
            'default_mix' => [
                ['label' => 'Kick', 'gain' => 0, 'pan' => 0, 'muted' => false],
                ['label' => 'Snare', 'gain' => 0, 'pan' => 0, 'muted' => false],
                ['label' => '', 'gain' => 0, 'pan' => 0, 'muted' => false],
            ],
            'channels_count' => null,
            'sample_rate' => null,
            'duration_seconds' => null,
        ]);

        $storage = app(TrackStorage::class);
        (new TranscodeTrackToChannels($track))->handle($storage);

        $track->refresh();
        $this->assertEqualsWithDelta(2.0, $track->duration_seconds, 0.2);
        $this->assertSame(3, $track->channels_count);
        $this->assertNull($track->s3_key, 'source WAV key should be cleared after a successful transcode');

        $channels = $track->channels()->get();
        $this->assertCount(3, $channels);
        $this->assertSame([0, 1, 2], $channels->pluck('channel_index')->all());
        $this->assertSame(['Kick', 'Snare', null], $channels->pluck('label')->all());

        $disk = Storage::disk('local');
        $this->assertFalse($disk->exists($key), 'source WAV should be deleted after a successful transcode');

        foreach ($channels as $channel) {
            $this->assertTrue($disk->exists($channel->s3_key), "missing opus blob for channel {$channel->channel_index}");
            $this->assertNotNull($channel->peaks_s3_key);
            $this->assertTrue($disk->exists($channel->peaks_s3_key), "missing peaks blob for channel {$channel->channel_index}");

            // Each peaks file is a flat [max, min, max, min, ...] array with values in [-1, 1].
            $envelope = json_decode($disk->get($channel->peaks_s3_key), true);
            $this->assertIsArray($envelope['peaks']);
            $this->assertSame(0, count($envelope['peaks']) % 2);
            $this->assertSame(48000, $envelope['sample_rate']);
            foreach ($envelope['peaks'] as $value) {
                $this->assertGreaterThanOrEqual(-1.0, $value);
                $this->assertLessThanOrEqual(1.0, $value);
            }
            // The 440 Hz tone swings full scale; both extremes should appear.
            $this->assertGreaterThan(0.3, max($envelope['peaks']));
            $this->assertLessThan(-0.3, min($envelope['peaks']));

            // Opus-in-WebM starts with EBML magic.
            $head = $disk->get($channel->s3_key);
            $this->assertSame("\x1A\x45\xDF\xA3", substr($head, 0, 4), 'output is not a WebM stream');
        }
    }

    public function test_a_missing_source_key_is_a_noop(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create(['s3_key' => null]);

        (new TranscodeTrackToChannels($track))->handle(app(TrackStorage::class));

        $this->assertSame(0, $track->channels()->count());
    }

    /**
     * Generate an N-channel test WAV where every channel carries the same
     * full-scale sine. ffmpeg's `-ac N` upmix from mono will zero some channels
     * depending on the implied layout (e.g. LFE in 2.1 stays silent), which
     * would give the transcode job nothing to measure on those channels — so we
     * pan the mono source onto every output channel explicitly instead.
     */
    private function generateTone(string $path, int $seconds, int $channels): void
    {
        @mkdir(dirname($path), 0777, true);

        $copies = implode('|', array_map(fn ($c) => "c{$c}=c0", range(0, $channels - 1)));

        $process = proc_open([
            'ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}",
            '-af', "volume=10,pan={$channels}c|{$copies}",
            '-ar', '44100',
            $path,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        $this->assertSame(0, proc_close($process), 'Failed to generate test tone.');
    }
}
