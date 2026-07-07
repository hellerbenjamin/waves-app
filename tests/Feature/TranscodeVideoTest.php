<?php

namespace Tests\Feature;

use App\Jobs\TranscodeVideo;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TranscodeVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_web_rendition_poster_and_metadata(): void
    {
        if (! (new ExecutableFinder)->find('ffmpeg')) {
            $this->markTestSkipped('ffmpeg is not available.');
        }

        Storage::fake('s3');

        $user = User::factory()->create();
        $key = "media/users/{$user->id}/clip.mp4";
        Storage::disk('s3')->put($key, $this->sampleVideo());

        $media = Media::factory()->for($user)->video()->create([
            's3_key' => $key,
            'playback_key' => null,
            'thumb_key' => null,
            'width' => null,
            'height' => null,
            'duration' => null,
        ]);

        (new TranscodeVideo($media))->handle(app(MediaStorage::class));

        $media->refresh();

        // Rendition written beside the original and recorded on the row.
        $this->assertSame("media/users/{$user->id}/web/clip.mp4", $media->playback_key);
        Storage::disk('s3')->assertExists($media->playback_key);

        // Poster pulled from the rendition.
        $this->assertNotNull($media->thumb_key);
        Storage::disk('s3')->assertExists($media->thumb_key);

        // ffprobe metadata captured. Source is 640x480, ~2s.
        $this->assertSame(640, $media->width);
        $this->assertSame(480, $media->height);
        $this->assertGreaterThanOrEqual(1, $media->duration);

        // playbackKey() now resolves to the rendition, not the original.
        $this->assertSame($media->playback_key, $media->playbackKey());
        $this->assertSame('video/mp4', $media->playbackMime());
    }

    public function test_it_ignores_non_videos(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $media = Media::factory()->for($user)->create(['kind' => 'image', 'playback_key' => null]);

        (new TranscodeVideo($media))->handle(app(MediaStorage::class));

        $this->assertNull($media->fresh()->playback_key);
    }

    /** A tiny H.264 sample so the encode has real frames to work on. */
    private function sampleVideo(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'waves_test_src_').'.mp4';

        $process = new Process([
            'ffmpeg', '-y', '-nostdin',
            '-f', 'lavfi', '-i', 'testsrc=duration=2:size=640x480:rate=15',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            $tmp,
        ]);
        $process->setTimeout(60);
        $process->run();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
