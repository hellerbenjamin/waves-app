<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateThumbnailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_thumbnail_and_records_dimensions_for_an_image(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $key = "media/users/{$user->id}/photo.jpg";
        Storage::disk('s3')->put($key, $this->jpegBytes(1200, 800));

        $media = Media::factory()->for($user)->create([
            's3_key' => $key,
            'kind' => 'image',
            'thumb_key' => null,
        ]);

        (new GenerateThumbnail($media))->handle(app(MediaStorage::class));

        $media->refresh();
        $this->assertNotNull($media->thumb_key);
        $this->assertSame(1200, $media->width);
        $this->assertSame(800, $media->height);
        Storage::disk('s3')->assertExists($media->thumb_key);
    }

    public function test_it_skips_videos(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $media = Media::factory()->for($user)->video()->create(['thumb_key' => null]);

        (new GenerateThumbnail($media))->handle(app(MediaStorage::class));

        $this->assertNull($media->fresh()->thumb_key);
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
