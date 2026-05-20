<?php

namespace Tests\Feature;

use App\Jobs\ExtractPeaks;
use App\Models\Track;
use App\Models\User;
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

    public function test_index_requires_verified_email(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($unverified)
            ->get('/tracks')
            ->assertRedirect(route('verification.notice'));
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

    public function test_show_renders_track_with_stream_url(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withPeaks()->create(['original_name' => 'mix.wav']);

        $this->actingAs($user)
            ->get("/tracks/{$track->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Show')
                ->where('track.id', $track->id)
                ->where('track.name', 'mix.wav')
                ->where('track.peaks_ready', true)
                ->where('track.stream_url', route('tracks.stream', $track->id))
                ->where('track.streams_same_origin', false) // s3 disk in tests
                ->has('track.peaks.channels')
            );
    }

    public function test_stream_403s_for_other_users_track(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->get("/tracks/{$track->id}/stream")
            ->assertForbidden();
    }

    public function test_stream_redirects_to_temporary_url_on_s3(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed-stream');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->actingAs($user)
            ->get("/tracks/{$track->id}/stream")
            ->assertRedirect('https://s3.example/signed-stream');
    }

    public function test_stream_serves_file_on_local_disk(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $key = "users/{$user->id}/play.wav";
        Storage::disk('local')->put($key, 'RIFF....WAVEfake');
        $track = Track::factory()->for($user)->create(['s3_key' => $key, 'mime' => 'audio/wav']);

        $response = $this->actingAs($user)->get("/tracks/{$track->id}/stream");

        $response->assertOk();
        $this->assertSame('audio/wav', $response->headers->get('Content-Type'));
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
                'size' => 5_368_709_121, // 5GB + 1
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
        Bus::assertNotDispatched(ExtractPeaks::class);
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
            ->assertRedirect(route('tracks.index'));

        $this->assertDatabaseHas('tracks', [
            'user_id' => $user->id,
            's3_key' => $key,
            'original_name' => 'song.wav',
            'size' => 14,
        ]);

        Bus::assertDispatched(ExtractPeaks::class, fn ($job) => $job->track->s3_key === $key);
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
}
