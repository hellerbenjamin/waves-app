<?php

namespace Tests\Feature;

use App\Jobs\DetectSongs;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class DetectSongsTest extends TestCase
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

    public function test_it_finds_regions_separated_by_silence(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $key = "users/{$user->id}/sequence.wav";
        // Two 2s tones with a 2s silence sandwich on each side and between them.
        // That's plenty of silence and signal for silencedetect to split it.
        $this->generateSequence(Storage::disk('local')->path($key));

        $track = Track::factory()->for($user)->create([
            's3_key' => $key,
            'original_name' => 'show.wav',
            'peaks_ready' => true,
            'channels_count' => 1,
            'sample_rate' => 44100,
            'duration_seconds' => 8.0,
        ]);

        (new DetectSongs($track, silenceDb: -40, minSilence: 0.5, minRegion: 1.0))->handle();

        $track->refresh();

        $this->assertSame('ready', $track->split_proposal['status']);
        $regions = $track->split_proposal['regions'];

        // The two tones, recovered as two distinct regions.
        $this->assertCount(2, $regions);
        $this->assertGreaterThan(0.5, $regions[1]['start'] - $regions[0]['end']);

        foreach ($regions as $i => $r) {
            // Parent base is prepended as a suggested name the user can edit.
            $this->assertSame('show - Part '.($i + 1), $r['name']);
            $this->assertGreaterThan($r['start'], $r['end']);
        }
    }

    /** A test WAV with: silence, tone, silence, tone, silence. */
    private function generateSequence(string $path): void
    {
        @mkdir(dirname($path), 0777, true);

        // Build via a single ffmpeg invocation using anullsrc+sine concatenated
        // through a filter graph. Simpler: synthesise stretches and use
        // concat demuxer — but for a small fixture, inline filter is fine.
        $filter =
            'anullsrc=cl=mono:r=44100:d=1[s1];'.
            'sine=frequency=440:duration=2,volume=10[t1];'.
            'anullsrc=cl=mono:r=44100:d=2[s2];'.
            'sine=frequency=440:duration=2,volume=10[t2];'.
            'anullsrc=cl=mono:r=44100:d=1[s3];'.
            '[s1][t1][s2][t2][s3]concat=n=5:v=0:a=1[out]';

        $process = proc_open([
            'ffmpeg', '-v', 'error', '-y',
            '-filter_complex', $filter,
            '-map', '[out]',
            '-ar', '44100', '-ac', '1',
            $path,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        $this->assertSame(0, proc_close($process), 'Failed to generate test sequence.');
    }
}
