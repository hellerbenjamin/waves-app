<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Track;
use App\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_init_channels_returns_presigned_targets_scoped_to_user(): void
    {
        $user = User::factory()->create();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        // 2 channels × (opus + peaks) = 4 presigns.
        $disk->shouldReceive('temporaryUploadUrl')
            ->times(4)
            ->andReturn(['url' => 'https://s3.example/put', 'headers' => []]);
        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->actingAs($user)
            ->postJson('/tracks/channels/init', ['channels' => 2])
            ->assertOk()
            ->assertJsonStructure([
                'group',
                'targets' => [['index', 'opus_key', 'opus' => ['url', 'headers'], 'peaks_key', 'peaks' => ['url', 'headers']]],
            ]);

        $this->assertCount(2, $response->json('targets'));
        foreach ($response->json('targets') as $target) {
            $this->assertStringStartsWith("users/{$user->id}/", $target['opus_key']);
            $this->assertStringStartsWith("users/{$user->id}/", $target['peaks_key']);
            $this->assertStringEndsWith('.webm', $target['opus_key']);
            $this->assertStringEndsWith('.peaks.json', $target['peaks_key']);
        }
    }

    public function test_init_channels_rejects_a_zero_channel_count(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks/channels/init', ['channels' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channels');
    }

    public function test_store_rejects_channel_keys_belonging_to_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/tracks', [
                'original_name' => 'song',
                'duration_seconds' => 12.3,
                'sample_rate' => 48000,
                'channels' => [
                    ['index' => 0, 'opus_key' => "users/{$other->id}/g/ch00.webm", 'peaks_key' => "users/{$other->id}/g/ch00.peaks.json", 'size' => 100],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channels.0.opus_key', 'channels.0.peaks_key']);
    }

    public function test_store_422s_when_a_channel_object_is_missing(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');

        $this->actingAs($user)
            ->postJson('/tracks', [
                'original_name' => 'song',
                'duration_seconds' => 12.3,
                'sample_rate' => 48000,
                'channels' => [
                    ['index' => 0, 'opus_key' => "users/{$user->id}/g/ch00.webm", 'peaks_key' => "users/{$user->id}/g/ch00.peaks.json", 'size' => 100],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('tracks', 0);
    }

    public function test_store_creates_a_track_and_its_channels_from_uploaded_objects(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');

        $group = 'GROUP1';
        for ($i = 0; $i < 2; $i++) {
            Storage::disk('s3')->put("users/{$user->id}/{$group}/ch0{$i}.webm", 'opus');
            Storage::disk('s3')->put("users/{$user->id}/{$group}/ch0{$i}.peaks.json", '{"peaks":[]}');
        }

        $this->actingAs($user)
            ->post('/tracks', [
                'original_name' => 'My Song',
                'duration_seconds' => 200.5,
                'sample_rate' => 48000,
                'channels' => [
                    ['index' => 0, 'label' => 'Kick', 'opus_key' => "users/{$user->id}/{$group}/ch00.webm", 'peaks_key' => "users/{$user->id}/{$group}/ch00.peaks.json", 'size' => 10],
                    ['index' => 1, 'label' => 'Snare', 'opus_key' => "users/{$user->id}/{$group}/ch01.webm", 'peaks_key' => "users/{$user->id}/{$group}/ch01.peaks.json", 'size' => 20],
                ],
            ])
            ->assertRedirect();

        $track = Track::firstWhere('original_name', 'My Song');
        $this->assertNotNull($track);
        $this->assertNull($track->s3_key);
        $this->assertSame(2, $track->channels_count);
        $this->assertSame(48000, $track->sample_rate);
        $this->assertSame(30, $track->size);
        $this->assertSame(['Kick', 'Snare'], $track->channels()->pluck('label')->all());
    }

    public function test_store_assigns_the_track_to_an_owned_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        Storage::fake('s3');

        Storage::disk('s3')->put("users/{$user->id}/g/ch00.webm", 'opus');
        Storage::disk('s3')->put("users/{$user->id}/g/ch00.peaks.json", '{}');

        $this->actingAs($user)
            ->post('/tracks', [
                'original_name' => 'in-event',
                'duration_seconds' => 5,
                'sample_rate' => 48000,
                'event_id' => $event->id,
                'channels' => [
                    ['index' => 0, 'opus_key' => "users/{$user->id}/g/ch00.webm", 'peaks_key' => "users/{$user->id}/g/ch00.peaks.json", 'size' => 14],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tracks', ['original_name' => 'in-event', 'event_id' => $event->id]);
    }

    public function test_store_rejects_event_belonging_to_another_user(): void
    {
        $user = User::factory()->create();
        $othersEvent = Event::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->postJson('/tracks', [
                'original_name' => 'x',
                'duration_seconds' => 5,
                'sample_rate' => 48000,
                'event_id' => $othersEvent->id,
                'channels' => [
                    ['index' => 0, 'opus_key' => "users/{$user->id}/g/ch00.webm", 'peaks_key' => "users/{$user->id}/g/ch00.peaks.json", 'size' => 14],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_id');
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
