<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Media;
use App\Models\Track;
use App\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests render full pages; the dev workflow serves assets from the
        // Vite dev server (no built manifest), so stub the Vite tags out.
        $this->withoutVite();
    }

    public function test_index_redirects_guests_to_login(): void
    {
        $this->get('/events')->assertRedirect('/login');
    }

    public function test_index_lists_only_own_events_with_counts(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['name' => 'My Show']);
        Track::factory()->count(2)->for($user)->create(['event_id' => $event->id]);
        Media::factory()->for($user)->create(['event_id' => $event->id]);

        Event::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->get('/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Events/Index')
                ->has('events', 1)
                ->where('events.0.name', 'My Show')
                ->where('events.0.tracks_count', 2)
                ->where('events.0.media_count', 1)
            );
    }

    public function test_store_creates_event_and_redirects_to_show(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/events', [
            'name' => 'Spring Rehearsal',
            'type' => 'rehearsal',
            'event_date' => '2026-03-01',
            'location' => 'Studio B',
        ]);

        $event = Event::firstWhere('name', 'Spring Rehearsal');
        $this->assertNotNull($event);
        $this->assertSame($user->id, $event->user_id);
        $response->assertRedirect(route('events.show', $event->id));
    }

    public function test_store_rejects_unknown_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/events', ['name' => 'X', 'type' => 'party'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_show_403s_for_other_users_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for(User::factory())->create();

        $this->actingAs($user)->get("/events/{$event->id}")->assertForbidden();
    }

    public function test_show_returns_event_with_tracks_and_media(): void
    {
        // Local disk so playback/object URLs are plain app routes (no presigning).
        config(['filesystems.tracks_disk' => 'local']);

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        Track::factory()->for($user)->withChannels()->create(['event_id' => $event->id]);
        Media::factory()->for($user)->withThumb()->create(['event_id' => $event->id]);

        $this->actingAs($user)
            ->get("/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Events/Show')
                ->where('canEdit', true)
                ->has('event.tracks', 1)
                ->has('event.media', 1)
                ->where('event.media.0.kind', 'image')
            );
    }

    public function test_destroy_removes_event_but_keeps_tracks_and_media(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create(['event_id' => $event->id]);
        $media = Media::factory()->for($user)->create(['event_id' => $event->id]);

        $this->actingAs($user)
            ->delete("/events/{$event->id}")
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseHas('tracks', ['id' => $track->id, 'event_id' => null]);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'event_id' => null]);
    }

    public function test_destroy_403s_for_other_users_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for(User::factory())->create();

        $this->actingAs($user)->delete("/events/{$event->id}")->assertForbidden();
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_share_then_unshare_toggles_token(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/events/{$event->id}/share")
            ->assertOk()
            ->assertJsonStructure(['share_url']);
        $this->assertNotNull($event->fresh()->share_token);

        $this->actingAs($user)
            ->deleteJson("/events/{$event->id}/share")
            ->assertNoContent();
        $this->assertNull($event->fresh()->share_token);
    }

    public function test_attach_tracks_assigns_only_owned_tracks(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $mine = Track::factory()->count(2)->for($user)->create();
        $theirs = Track::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->post("/events/{$event->id}/tracks", [
                'track_ids' => [$mine[0]->id, $mine[1]->id, $theirs->id],
            ])
            ->assertRedirect();

        $this->assertSame($event->id, $mine[0]->fresh()->event_id);
        $this->assertSame($event->id, $mine[1]->fresh()->event_id);
        $this->assertNull($theirs->fresh()->event_id);
    }

    public function test_detach_track_clears_event_id(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create(['event_id' => $event->id]);

        $this->actingAs($user)
            ->delete("/events/{$event->id}/tracks/{$track->id}")
            ->assertRedirect();

        $this->assertNull($track->fresh()->event_id);
    }

    public function test_detach_404s_when_track_not_in_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create(); // no event

        $this->actingAs($user)
            ->delete("/events/{$event->id}/tracks/{$track->id}")
            ->assertNotFound();
    }

    public function test_shared_event_page_renders_publicly(): void
    {
        config(['filesystems.tracks_disk' => 'local']);

        $event = Event::factory()->shared()->create();

        $this->get("/events/share/{$event->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Events/Show')
                ->where('canEdit', false)
            );
    }

    public function test_shared_track_stream_404s_for_non_member(): void
    {
        $event = Event::factory()->shared()->create();
        $track = Track::factory()->for($event->user)->create(); // not in the event

        $this->get("/events/share/{$event->share_token}/tracks/{$track->id}/stream")
            ->assertNotFound();
    }

    public function test_shared_track_stream_redirects_for_member_on_s3(): void
    {
        $event = Event::factory()->shared()->create();
        $track = Track::factory()->for($event->user)->create(['event_id' => $event->id]);

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->get("/events/share/{$event->share_token}/tracks/{$track->id}/stream")
            ->assertRedirect('https://s3.example/signed');
    }

    public function test_shared_media_stream_404s_for_non_member(): void
    {
        $event = Event::factory()->shared()->create();
        $media = Media::factory()->for($event->user)->create(); // not in the event

        $this->get("/events/share/{$event->share_token}/media/{$media->id}/stream")
            ->assertNotFound();
    }

    public function test_shared_media_download_redirects_publicly_for_member(): void
    {
        $event = Event::factory()->shared()->create();
        $media = Media::factory()->for($event->user)->create(['event_id' => $event->id]);

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed-download');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->get("/events/share/{$event->share_token}/media/{$media->id}/download")
            ->assertRedirect('https://s3.example/signed-download');
    }

    public function test_shared_media_download_404s_for_non_member(): void
    {
        $event = Event::factory()->shared()->create();
        $media = Media::factory()->for($event->user)->create(); // not in the event

        $this->get("/events/share/{$event->share_token}/media/{$media->id}/download")
            ->assertNotFound();
    }
}
