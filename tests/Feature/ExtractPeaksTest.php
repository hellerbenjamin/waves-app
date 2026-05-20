<?php

namespace Tests\Feature;

use App\Jobs\ExtractPeaks;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class ExtractPeaksTest extends TestCase
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

    public function test_it_extracts_normalised_peaks_and_duration(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $key = "users/{$user->id}/tone.wav";
        $this->generateTone(Storage::disk('local')->path($key), seconds: 2, channels: 2);

        $track = Track::factory()->for($user)->create([
            's3_key' => $key,
            'peaks' => null,
            'duration_seconds' => null,
        ]);

        (new ExtractPeaks($track))->handle();

        $track->refresh();

        $this->assertEqualsWithDelta(2.0, $track->duration_seconds, 0.1);
        $this->assertSame(44100, $track->peaks['sample_rate']);
        $this->assertCount(2, $track->peaks['channels']);

        foreach ($track->peaks['channels'] as $channel) {
            // Interleaved max/min pairs → even length, every value within [-1, 1].
            $this->assertSame(0, count($channel) % 2);
            foreach ($channel as $value) {
                $this->assertGreaterThanOrEqual(-1.0, $value);
                $this->assertLessThanOrEqual(1.0, $value);
            }

            // A full-scale tone should swing positive and negative somewhere.
            $this->assertGreaterThan(0.5, max($channel));
            $this->assertLessThan(-0.5, min($channel));
        }
    }

    private function generateTone(string $path, int $seconds, int $channels): void
    {
        @mkdir(dirname($path), 0777, true);

        $process = proc_open([
            'ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}",
            // ffmpeg's sine source is quiet; boost it to a near-full-scale level.
            '-af', 'volume=10',
            '-ac', (string) $channels, '-ar', '44100',
            $path,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        $this->assertSame(0, proc_close($process), 'Failed to generate test tone.');
    }
}
