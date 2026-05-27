<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Media;
use App\Models\Track;
use App\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProfileShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests render full Inertia pages; stub Vite (no built manifest).
        $this->withoutVite();
    }

    public function test_share_then_unshare_toggles_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/profile/share')
            ->assertOk()
            ->assertJsonStructure(['share_url']);
        $this->assertNotNull($user->fresh()->share_token);

        $this->actingAs($user)
            ->deleteJson('/profile/share')
            ->assertNoContent();
        $this->assertNull($user->fresh()->share_token);
    }

    public function test_edit_page_exposes_share_url_only_when_shared(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')
            ->assertInertia(fn ($page) => $page->where('shareUrl', null));

        $user->update(['share_token' => Str::random(32)]);

        $this->actingAs($user)->get('/profile')
            ->assertInertia(fn ($page) => $page->where('shareUrl', route('profile.shared', $user->share_token)));
    }

    public function test_public_profile_lists_all_owned_events_including_future_ones(): void
    {
        $user = User::factory()->create(['name' => 'Ben', 'share_token' => Str::random(32)]);
        Event::factory()->for($user)->create(['name' => 'First Show']);

        // Another user's event must never appear.
        Event::factory()->for(User::factory())->create(['name' => 'Someone Else']);

        // An event created "later" is still covered by the same link.
        Event::factory()->for($user)->create(['name' => 'Later Show']);

        $this->get("/u/{$user->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Shared')
                ->where('name', 'Ben')
                ->has('events', 2)
                ->where('events', fn ($events) => collect($events)->pluck('name')->doesntContain('Someone Else'))
            );
    }

    public function test_unknown_profile_token_404s(): void
    {
        $this->get('/u/'.Str::random(32))->assertNotFound();
    }

    public function test_event_page_renders_through_profile_token(): void
    {
        config(['filesystems.tracks_disk' => 'local']);

        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $event = Event::factory()->for($user)->create();
        Track::factory()->for($user)->withPeaks()->create(['event_id' => $event->id]);

        $this->get("/u/{$user->share_token}/events/{$event->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Events/Show')
                ->where('canEdit', false)
                ->has('event.tracks', 1)
                // The per-event token is never leaked through the profile view.
                ->where('event.share_url', null)
            );
    }

    public function test_event_page_404s_for_event_owned_by_another_user(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $foreign = Event::factory()->for(User::factory())->create();

        $this->get("/u/{$user->share_token}/events/{$foreign->id}")->assertNotFound();
    }

    public function test_track_stream_redirects_for_owned_track_on_s3(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $event = Event::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create(['event_id' => $event->id]);

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->get("/u/{$user->share_token}/events/{$event->id}/tracks/{$track->id}/stream")
            ->assertRedirect('https://s3.example/signed');
    }

    public function test_track_stream_404s_when_track_not_in_event(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $event = Event::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create(); // not in the event

        $this->get("/u/{$user->share_token}/events/{$event->id}/tracks/{$track->id}/stream")
            ->assertNotFound();
    }

    public function test_media_stream_404s_when_event_not_owned_by_token_user(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $foreignEvent = Event::factory()->for(User::factory())->create();
        $media = Media::factory()->for($foreignEvent->user)->create(['event_id' => $foreignEvent->id]);

        $this->get("/u/{$user->share_token}/events/{$foreignEvent->id}/media/{$media->id}/stream")
            ->assertNotFound();
    }

    public function test_media_download_redirects_publicly_for_token_owner(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $event = Event::factory()->for($user)->create();
        $media = Media::factory()->for($user)->create(['event_id' => $event->id]);

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed-download');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->get("/u/{$user->share_token}/events/{$event->id}/media/{$media->id}/download")
            ->assertRedirect('https://s3.example/signed-download');
    }

    public function test_media_download_404s_when_event_not_owned_by_token_user(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $foreignEvent = Event::factory()->for(User::factory())->create();
        $media = Media::factory()->for($foreignEvent->user)->create(['event_id' => $foreignEvent->id]);

        $this->get("/u/{$user->share_token}/events/{$foreignEvent->id}/media/{$media->id}/download")
            ->assertNotFound();
    }

    public function test_revoking_token_kills_the_public_links(): void
    {
        $user = User::factory()->create(['share_token' => Str::random(32)]);
        $event = Event::factory()->for($user)->create();
        $oldToken = $user->share_token;

        $this->get("/u/{$oldToken}")->assertOk();

        $user->update(['share_token' => null]);

        $this->get("/u/{$oldToken}")->assertNotFound();
        $this->get("/u/{$oldToken}/events/{$event->id}")->assertNotFound();
    }

    public function test_profile_share_is_independent_of_event_share_tokens(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->shared()->create();
        $eventToken = $event->share_token;

        // Minting the profile token must not touch the event's own token...
        $this->actingAs($user)->postJson('/profile/share')->assertOk();
        $this->assertSame($eventToken, $event->fresh()->share_token);

        // ...nor must revoking it.
        $this->actingAs($user)->deleteJson('/profile/share')->assertNoContent();
        $this->assertSame($eventToken, $event->fresh()->share_token);
    }
}
