<?php

namespace Tests\Feature;

use App\Jobs\BackfillVideoDimensions;
use App\Jobs\TranscodeVideo;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackfillVideoDimensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_corrects_dimensions_from_the_rendition(): void
    {
        if (! (new ExecutableFinder)->find('ffmpeg')) {
            $this->markTestSkipped('ffmpeg is not available.');
        }

        Storage::fake('s3');

        $user = User::factory()->create();
        $renditionKey = "media/users/{$user->id}/web/clip.mp4";
        Storage::disk('s3')->put($renditionKey, $this->portraitRendition());

        // A row left with wrong (swapped) landscape dimensions by the old path.
        $media = Media::factory()->for($user)->video()->create([
            's3_key' => "media/users/{$user->id}/clip.mp4",
            'playback_key' => $renditionKey,
            'width' => 640,
            'height' => 480,
        ]);

        (new BackfillVideoDimensions($media))->handle(app(MediaStorage::class));

        $media->refresh();
        $this->assertSame(480, $media->width);
        $this->assertSame(640, $media->height);
    }

    public function test_job_skips_videos_without_a_rendition(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $media = Media::factory()->for($user)->video()->create([
            'playback_key' => null,
            'width' => 111,
            'height' => 222,
        ]);

        (new BackfillVideoDimensions($media))->handle(app(MediaStorage::class));

        $media->refresh();
        $this->assertSame(111, $media->width); // untouched
    }

    public function test_command_reprobes_rendition_videos_and_transcodes_the_rest(): void
    {
        Storage::fake('s3');
        Bus::fake();

        $user = User::factory()->create();
        $withRendition = Media::factory()->for($user)->video()->create(['playback_key' => 'media/users/1/web/a.mp4']);
        $without = Media::factory()->for($user)->video()->create(['playback_key' => null]);

        $this->artisan('media:backfill-video-dimensions')->assertSuccessful();

        Bus::assertDispatched(BackfillVideoDimensions::class, fn ($job) => $job->media->is($withRendition));
        Bus::assertDispatched(TranscodeVideo::class, fn ($job) => $job->media->is($without));
    }

    /** A real portrait (480x640) rendition to probe. */
    private function portraitRendition(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'waves_test_portrait_').'.mp4';

        (new Process([
            'ffmpeg', '-y', '-nostdin',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=480x640:rate=15',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
            $tmp,
        ]))->setTimeout(60)->run();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
