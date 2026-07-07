<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Jobs\TranscodeVideo;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_url_returns_signed_data_scoped_to_user(): void
    {
        $user = User::factory()->create();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUploadUrl')->once()->andReturn([
            'url' => 'https://s3.example/signed',
            'headers' => ['Content-Type' => 'image/jpeg'],
        ]);
        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->actingAs($user)
            ->postJson('/media/upload-url', [
                'filename' => 'beach.jpg',
                'size' => 2048,
                'content_type' => 'image/jpeg',
            ])
            ->assertOk()
            ->assertJsonStructure(['url', 'headers', 's3_key']);

        $this->assertStringStartsWith("media/users/{$user->id}/", $response->json('s3_key'));
        $this->assertStringEndsWith('.jpg', $response->json('s3_key'));
    }

    public function test_upload_url_rejects_disallowed_content_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/media/upload-url', [
                'filename' => 'doc.pdf',
                'size' => 2048,
                'content_type' => 'application/pdf',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_type');
    }

    public function test_store_rejects_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/media', [
                's3_key' => "media/users/{$other->id}/x.jpg",
                'original_name' => 'x.jpg',
                'mime' => 'image/jpeg',
                'size' => 1024,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('s3_key');
    }

    public function test_store_422s_when_object_missing(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');
        Bus::fake();

        $this->actingAs($user)
            ->postJson('/media', [
                's3_key' => "media/users/{$user->id}/missing.jpg",
                'original_name' => 'x.jpg',
                'mime' => 'image/jpeg',
                'size' => 1024,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('media', 0);
        Bus::assertNotDispatched(GenerateThumbnail::class);
    }

    public function test_store_creates_image_and_dispatches_thumbnail(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        Storage::fake('s3');
        Bus::fake();

        $key = "media/users/{$user->id}/photo.jpg";
        Storage::disk('s3')->put($key, 'fake-bytes');

        $this->actingAs($user)
            ->post('/media', [
                's3_key' => $key,
                'original_name' => 'photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 9,
                'event_id' => $event->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('media', [
            'user_id' => $user->id,
            'event_id' => $event->id,
            's3_key' => $key,
            'kind' => 'image',
        ]);
        Bus::assertDispatched(GenerateThumbnail::class);
    }

    public function test_store_transcodes_video_instead_of_thumbnailing(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');
        Bus::fake();

        $key = "media/users/{$user->id}/clip.mp4";
        Storage::disk('s3')->put($key, 'fake-bytes');

        $this->actingAs($user)
            ->post('/media', [
                's3_key' => $key,
                'original_name' => 'clip.mp4',
                'mime' => 'video/mp4',
                'size' => 9,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('media', ['s3_key' => $key, 'kind' => 'video']);
        Bus::assertDispatched(TranscodeVideo::class);
        Bus::assertNotDispatched(GenerateThumbnail::class);
    }

    public function test_store_rejects_event_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $othersEvent = Event::factory()->for(User::factory())->create();
        Storage::fake('s3');
        Bus::fake();

        $key = "media/users/{$user->id}/photo.jpg";
        Storage::disk('s3')->put($key, 'bytes');

        $this->actingAs($user)
            ->postJson('/media', [
                's3_key' => $key,
                'original_name' => 'photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 5,
                'event_id' => $othersEvent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_id');
    }

    public function test_stream_403s_for_other_users_media(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for(User::factory())->create();

        $this->actingAs($user)->get("/media/{$media->id}/stream")->assertForbidden();
    }

    public function test_stream_redirects_to_temporary_url_on_s3(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for($user)->create();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->actingAs($user)
            ->get("/media/{$media->id}/stream")
            ->assertRedirect('https://s3.example/signed');
    }

    public function test_download_403s_for_other_users_media(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for(User::factory())->create();

        $this->actingAs($user)->get("/media/{$media->id}/download")->assertForbidden();
    }

    public function test_download_redirects_to_presigned_url_with_attachment_disposition(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for($user)->create(['original_name' => 'beach day.jpg', 'mime' => 'image/jpeg']);

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')
            ->once()
            ->with($media->s3_key, Mockery::type(\DateTimeInterface::class), Mockery::on(function ($opts) {
                return ($opts['ResponseContentDisposition'] ?? '') === 'attachment; filename="beach day.jpg"'
                    && ($opts['ResponseContentType'] ?? '') === 'image/jpeg';
            }))
            ->andReturn('https://s3.example/signed-download');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->actingAs($user)
            ->get("/media/{$media->id}/download")
            ->assertRedirect('https://s3.example/signed-download');
    }

    public function test_thumb_404s_when_no_thumbnail(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for($user)->create(['thumb_key' => null]);

        $this->actingAs($user)->get("/media/{$media->id}/thumb")->assertNotFound();
    }

    public function test_destroy_removes_object_thumbnail_and_row(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');

        $key = "media/users/{$user->id}/photo.jpg";
        $thumb = "media/users/{$user->id}/thumbs/photo.jpg";
        Storage::disk('s3')->put($key, 'bytes');
        Storage::disk('s3')->put($thumb, 'thumb-bytes');
        $media = Media::factory()->for($user)->create(['s3_key' => $key, 'thumb_key' => $thumb]);

        $this->actingAs($user)
            ->delete("/media/{$media->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('s3')->assertMissing($key);
        Storage::disk('s3')->assertMissing($thumb);
    }

    public function test_destroy_403s_for_other_users_media(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for(User::factory())->create();

        $this->actingAs($user)->delete("/media/{$media->id}")->assertForbidden();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_cleanup_deletes_an_orphaned_object(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');

        $key = "media/users/{$user->id}/orphan.jpg";
        Storage::disk('s3')->put($key, 'bytes');

        $this->actingAs($user)
            ->postJson('/media/cleanup', ['key' => $key])
            ->assertNoContent();

        Storage::disk('s3')->assertMissing($key);
    }

    public function test_cleanup_rejects_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Storage::fake('s3');

        $key = "media/users/{$other->id}/orphan.jpg";
        Storage::disk('s3')->put($key, 'bytes');

        $this->actingAs($user)
            ->postJson('/media/cleanup', ['key' => $key])
            ->assertForbidden();

        Storage::disk('s3')->assertExists($key);
    }

    public function test_share_then_unshare_toggles_token(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/media/{$media->id}/share")
            ->assertOk()
            ->assertJsonStructure(['share_url']);
        $this->assertNotNull($media->fresh()->share_token);

        $this->actingAs($user)
            ->deleteJson("/media/{$media->id}/share")
            ->assertNoContent();
        $this->assertNull($media->fresh()->share_token);
    }

    public function test_create_multipart_returns_upload_id_scoped_to_user(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('createMultipartUpload')->once()->andReturn(new Result(['UploadId' => 'UP9']));
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($client);
        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->actingAs($user)
            ->postJson('/media/multipart', ['filename' => 'show.mp4', 'size' => 8_000_000_000, 'content_type' => 'video/mp4'])
            ->assertOk()
            ->assertJsonStructure(['key', 'uploadId']);

        $this->assertSame('UP9', $response->json('uploadId'));
        $this->assertStringStartsWith("media/users/{$user->id}/", $response->json('key'));
        $this->assertStringEndsWith('.mp4', $response->json('key'));
    }

    public function test_sign_part_rejects_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/media/multipart/sign?key=media/users/'.$other->id.'/x.mp4&uploadId=UP1&partNumber=1')
            ->assertForbidden();
    }
}
