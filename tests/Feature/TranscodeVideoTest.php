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

        // Metadata is read from the rendition: the 640x480 (4:3) source is
        // downscaled into the 720p box as 960x720, and duration is ~2s.
        $this->assertSame(960, $media->width);
        $this->assertSame(720, $media->height);
        $this->assertGreaterThanOrEqual(1, $media->duration);

        // playbackKey() now resolves to the rendition, not the original.
        $this->assertSame($media->playback_key, $media->playbackKey());
        $this->assertSame('video/mp4', $media->playbackMime());
    }

    public function test_it_uprights_a_sideways_video(): void
    {
        if (! (new ExecutableFinder)->find('ffmpeg')) {
            $this->markTestSkipped('ffmpeg is not available.');
        }

        Storage::fake('s3');

        $user = User::factory()->create();
        $key = "media/users/{$user->id}/portrait.mp4";
        // A 640x480 landscape frame carrying a 90deg rotation matrix — i.e. a
        // portrait clip shot on a sideways-held phone. Naively storing the coded
        // dimensions would record it as landscape.
        Storage::disk('s3')->put($key, $this->rotatedVideo());

        $media = Media::factory()->for($user)->video()->create([
            's3_key' => $key,
            'playback_key' => null,
            'width' => null,
            'height' => null,
        ]);

        (new TranscodeVideo($media))->handle(app(MediaStorage::class));

        $media->refresh();

        // Autorotate baked the orientation into the rendition, so the recorded
        // dimensions come out portrait, not the source's coded 640x480.
        $this->assertNotNull($media->width);
        $this->assertNotNull($media->height);
        $this->assertLessThan($media->height, $media->width, 'rendition should be portrait');
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

    /** A landscape-coded H.264 sample carrying a 90deg display-rotation matrix. */
    private function rotatedVideo(): string
    {
        $plain = tempnam(sys_get_temp_dir(), 'waves_test_plain_').'.mp4';
        $rotated = tempnam(sys_get_temp_dir(), 'waves_test_rot_').'.mp4';

        (new Process([
            'ffmpeg', '-y', '-nostdin',
            '-f', 'lavfi', '-i', 'testsrc=duration=2:size=640x480:rate=15',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            $plain,
        ]))->setTimeout(60)->run();

        // -display_rotation is an input option; stream-copying stamps the matrix
        // onto the output without re-encoding.
        (new Process([
            'ffmpeg', '-y', '-nostdin',
            '-display_rotation', '90', '-i', $plain,
            '-c', 'copy', $rotated,
        ]))->setTimeout(60)->run();

        $bytes = (string) file_get_contents($rotated);
        @unlink($plain);
        @unlink($rotated);

        return $bytes;
    }
}
