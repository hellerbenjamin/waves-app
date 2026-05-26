<?php

namespace Tests\Feature;

use App\Jobs\ExtractPeaks;
use App\Jobs\SplitTrackSegment;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class SplitTrackSegmentTest extends TestCase
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

    public function test_it_cuts_a_child_track_and_uploads_it(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');
        Bus::fake([ExtractPeaks::class]);

        $user = User::factory()->create();
        $key = "users/{$user->id}/source.wav";
        $this->generateTone(Storage::disk('local')->path($key), seconds: 6);

        $parent = Track::factory()->for($user)->create([
            's3_key' => $key,
            'original_name' => 'show.wav',
            'duration_seconds' => 6.0,
        ]);

        (new SplitTrackSegment($parent, [
            'id' => 'r1',
            'start' => 1.0,
            'end' => 3.0,
            'name' => 'Song A',
        ]))->handle(app(\App\Services\TrackStorage::class));

        $child = $parent->children()->first();
        $this->assertNotNull($child);
        $this->assertSame('show - Song A.wav', $child->original_name);
        $this->assertSame($parent->id, $child->parent_track_id);
        $this->assertSame($user->id, $child->user_id);
        $this->assertTrue(Storage::disk('local')->exists($child->s3_key));

        // ExtractPeaks should be queued for the child so it becomes playable.
        Bus::assertDispatched(ExtractPeaks::class, fn ($job) => $job->track->is($child));
    }

    private function generateTone(string $path, int $seconds): void
    {
        @mkdir(dirname($path), 0777, true);

        $process = proc_open([
            'ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}",
            '-af', 'volume=10',
            '-ac', '1', '-ar', '44100',
            $path,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        $this->assertSame(0, proc_close($process));
    }
}
