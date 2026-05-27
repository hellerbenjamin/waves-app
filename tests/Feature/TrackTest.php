<?php

namespace Tests\Feature;

use App\Jobs\TranscodeTrackToChannels;
use App\Models\Event;
use App\Models\Track;
use App\Models\User;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TrackTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_redirects_guests_to_login(): void
    {
        $this->get('/tracks')->assertRedirect('/login');
    }

    public function test_index_only_returns_current_users_tracks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Track::factory()->for($user)->create(['original_name' => 'mine.wav']);
        Track::factory()->for($other)->create(['original_name' => 'theirs.wav']);

        $this->actingAs($user)
            ->get('/tracks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Index')
                ->has('tracks', 1)
                ->where('tracks.0.id', $mine->id)
                ->where('tracks.0.name', 'mine.wav')
            );
    }

    public function test_show_403s_for_other_users_track(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->get("/tracks/{$track->id}")
            ->assertForbidden();
    }

    public function test_show_renders_a_transcoded_track_with_per_channel_stream_urls(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create(['original_name' => 'mix.wav']);

        // Owner pages presign each channel's Opus + peaks URL directly so the
        // player consumes them off-origin (CORS-clean). 1 channel × (opus +
        // peaks) → 2 presigns.
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')
            ->twice()
            ->andReturn('https://s3.example/ch0-opus', 'https://s3.example/ch0-peaks');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->actingAs($user)
            ->get("/tracks/{$track->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Show')
                ->where('track.id', $track->id)
                ->where('track.name', 'mix.wav')
                ->where('track.ready', true)
                ->where('track.channels_count', 1)
                ->has('track.channels', 1)
                ->where('track.channels.0.index', 0)
                ->where('track.stream_cross_origin', 'anonymous') // s3 disk in tests
            );
    }

    public function test_update_403s_for_other_users_track(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for(User::factory())->withChannels()->create();

        $this->actingAs($user)
            ->patchJson("/tracks/{$track->id}", ['channel_labels' => ['Kick']])
            ->assertForbidden();
    }

    public function test_update_persists_and_normalises_channel_labels(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create([
            'channels_count' => 2,
            'sample_rate' => 44100,
            'duration_seconds' => 10.0,
        ]);

        $this->actingAs($user)
            ->patchJson("/tracks/{$track->id}", ['channel_labels' => ['  Kick  ', '']])
            ->assertOk()
            ->assertExactJson(['channel_labels' => ['Kick', null], 'name' => $track->original_name, 'default_mix' => null]);

        $this->assertSame(['Kick', null], $track->fresh()->channel_labels);
    }

    public function test_update_renames_track_without_clearing_labels(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create([
            'original_name' => 'old.wav',
            'channel_labels' => ['Kick', 'Snare'],
            'channels_count' => 2,
            'sample_rate' => 44100,
            'duration_seconds' => 10.0,
        ]);

        $this->actingAs($user)
            ->patchJson("/tracks/{$track->id}", ['original_name' => '  My Mix  '])
            ->assertOk()
            ->assertExactJson(['channel_labels' => ['Kick', 'Snare'], 'name' => 'My Mix', 'default_mix' => null]);

        $fresh = $track->fresh();
        $this->assertSame('My Mix', $fresh->original_name);
        $this->assertSame(['Kick', 'Snare'], $fresh->channel_labels);
    }

    public function test_update_rejects_blank_rename(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create();

        $this->actingAs($user)
            ->patchJson("/tracks/{$track->id}", ['original_name' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('original_name');
    }

    public function test_update_rejects_more_labels_than_channels(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create(); // 1 channel

        $this->actingAs($user)
            ->patchJson("/tracks/{$track->id}", ['channel_labels' => ['a', 'b']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channel_labels');
    }

    public function test_channel_stream_403s_for_other_users_track(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for(User::factory())->withChannels()->create();

        $this->actingAs($user)
            ->get("/tracks/{$track->id}/channels/0/stream")
            ->assertForbidden();
    }

    public function test_channel_stream_redirects_to_temporary_url_on_s3(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed-stream');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->actingAs($user)
            ->get("/tracks/{$track->id}/channels/0/stream")
            ->assertRedirect('https://s3.example/signed-stream');
    }

    public function test_channel_stream_serves_file_on_local_disk(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create();
        $channel = $track->channels()->first();
        Storage::disk('local')->put($channel->s3_key, 'fake-opus');

        $response = $this->actingAs($user)->get("/tracks/{$track->id}/channels/0/stream");

        $response->assertOk();
        $this->assertSame('audio/webm', $response->headers->get('Content-Type'));
    }

    public function test_channel_stream_404s_for_unknown_channel_index(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create(); // 1 channel

        $this->actingAs($user)
            ->get("/tracks/{$track->id}/channels/99/stream")
            ->assertNotFound();
    }

    public function test_upload_url_rejects_non_wav_filename(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/upload-url', [
                'filename' => 'song.mp3',
                'size' => 1024,
                'content_type' => 'audio/wav',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filename');
    }

    public function test_upload_url_rejects_oversize(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/upload-url', [
                'filename' => 'song.wav',
                'size' => 5_368_709_121, // 5GB + 1: over the single-PUT limit
                'content_type' => 'audio/wav',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('size');
    }

    public function test_upload_url_rejects_bad_content_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/upload-url', [
                'filename' => 'song.wav',
                'size' => 1024,
                'content_type' => 'audio/mpeg',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_type');
    }

    public function test_upload_url_returns_signed_data_scoped_to_user(): void
    {
        $user = User::factory()->create();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUploadUrl')
            ->once()
            ->andReturn([
                'url' => 'https://s3.example/signed',
                'headers' => ['Content-Type' => 'audio/wav'],
            ]);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        $response = $this->actingAs($user)
            ->postJson('/tracks/upload-url', [
                'filename' => 'song.wav',
                'size' => 1024,
                'content_type' => 'audio/wav',
            ])
            ->assertOk()
            ->assertJsonStructure(['url', 'headers', 's3_key']);

        $this->assertStringStartsWith("users/{$user->id}/", $response->json('s3_key'));
        $this->assertStringEndsWith('.wav', $response->json('s3_key'));
    }

    public function test_store_rejects_s3_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks', [
                's3_key' => "users/{$other->id}/abc.wav",
                'original_name' => 'song.wav',
                'mime' => 'audio/wav',
                'size' => 1024,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('s3_key');
    }

    public function test_store_422s_when_object_missing_in_storage(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');
        Bus::fake();

        $this->actingAs($user)
            ->postJson('/tracks', [
                's3_key' => "users/{$user->id}/missing.wav",
                'original_name' => 'song.wav',
                'mime' => 'audio/wav',
                'size' => 1024,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('tracks', 0);
        Bus::assertNotDispatched(TranscodeTrackToChannels::class);
    }

    public function test_store_creates_track_and_dispatches_peaks_job(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');
        Bus::fake();

        $key = "users/{$user->id}/abc.wav";
        Storage::disk('s3')->put($key, 'fake-wav-bytes');

        $this->actingAs($user)
            ->post('/tracks', [
                's3_key' => $key,
                'original_name' => 'song.wav',
                'mime' => 'audio/wav',
                'size' => 14,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tracks', [
            'user_id' => $user->id,
            's3_key' => $key,
            'original_name' => 'song.wav',
            'size' => 14,
        ]);

        Bus::assertDispatched(TranscodeTrackToChannels::class, fn ($job) => $job->track->s3_key === $key);
    }

    public function test_store_assigns_the_track_to_an_owned_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        Storage::fake('s3');
        Bus::fake();

        $key = "users/{$user->id}/in-event.wav";
        Storage::disk('s3')->put($key, 'fake-wav-bytes');

        $this->actingAs($user)
            ->post('/tracks', [
                's3_key' => $key,
                'original_name' => 'in-event.wav',
                'mime' => 'audio/wav',
                'size' => 14,
                'event_id' => $event->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tracks', ['s3_key' => $key, 'event_id' => $event->id]);
    }

    public function test_store_rejects_event_belonging_to_another_user(): void
    {
        $user = User::factory()->create();
        $othersEvent = Event::factory()->for(User::factory())->create();
        Storage::fake('s3');
        Bus::fake();

        $key = "users/{$user->id}/x.wav";
        Storage::disk('s3')->put($key, 'bytes');

        $this->actingAs($user)
            ->postJson('/tracks', [
                's3_key' => $key,
                'original_name' => 'x.wav',
                'mime' => 'audio/wav',
                'size' => 14,
                'event_id' => $othersEvent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_id');
    }

    public function test_create_multipart_returns_upload_id_scoped_to_user(): void
    {
        $user = User::factory()->create();

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('createMultipartUpload')->once()->andReturn(new Result(['UploadId' => 'UP123']));
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($client);
        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->actingAs($user)
            ->postJson('/tracks/multipart', ['filename' => 'song.wav', 'size' => 8_000_000_000, 'content_type' => 'audio/wav'])
            ->assertOk()
            ->assertJsonStructure(['key', 'uploadId']);

        $this->assertSame('UP123', $response->json('uploadId'));
        $this->assertStringStartsWith("users/{$user->id}/", $response->json('key'));
        $this->assertStringEndsWith('.wav', $response->json('key'));
    }

    public function test_create_multipart_rejects_non_wav_filename(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/multipart', ['filename' => 'song.mp3', 'size' => 1024, 'content_type' => 'audio/wav'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filename');
    }

    public function test_create_multipart_rejects_oversize(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/multipart', [
                'filename' => 'huge.wav',
                'size' => 53_687_091_201, // 50GB + 1
                'content_type' => 'audio/wav',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('size');
    }

    public function test_sign_part_rejects_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/tracks/multipart/sign?key=users/'.$other->id.'/x.wav&uploadId=UP1&partNumber=1')
            ->assertForbidden();
    }

    public function test_complete_multipart_rejects_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/multipart/complete', [
                'key' => "users/{$other->id}/x.wav",
                'uploadId' => 'UP1',
                'parts' => [['PartNumber' => 1, 'ETag' => 'abc']],
            ])
            ->assertForbidden();
    }

    public function test_abort_multipart_aborts_upload(): void
    {
        $user = User::factory()->create();
        $key = "users/{$user->id}/abc.wav";

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('abortMultipartUpload')->once();
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($client);
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->actingAs($user)
            ->postJson('/tracks/multipart/abort', ['key' => $key, 'uploadId' => 'UP1'])
            ->assertNoContent();
    }

    public function test_destroy_403s_for_other_users_track(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $track = Track::factory()->for($other)->create();

        $this->actingAs($user)
            ->delete("/tracks/{$track->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tracks', ['id' => $track->id]);
    }

    public function test_destroy_removes_object_and_row(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');

        $key = "users/{$user->id}/del.wav";
        Storage::disk('s3')->put($key, 'bytes');
        $track = Track::factory()->for($user)->create(['s3_key' => $key]);

        $this->actingAs($user)
            ->delete("/tracks/{$track->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('tracks', ['id' => $track->id]);
        Storage::disk('s3')->assertMissing($key);
    }

    public function test_cleanup_deletes_an_orphaned_object(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');

        $key = "users/{$user->id}/orphan.wav";
        Storage::disk('s3')->put($key, 'bytes');

        $this->actingAs($user)
            ->postJson('/tracks/cleanup', ['key' => $key])
            ->assertNoContent();

        Storage::disk('s3')->assertMissing($key);
    }

    public function test_cleanup_rejects_key_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Storage::fake('s3');

        $key = "users/{$other->id}/orphan.wav";
        Storage::disk('s3')->put($key, 'bytes');

        $this->actingAs($user)
            ->postJson('/tracks/cleanup', ['key' => $key])
            ->assertForbidden();

        Storage::disk('s3')->assertExists($key);
    }
}
