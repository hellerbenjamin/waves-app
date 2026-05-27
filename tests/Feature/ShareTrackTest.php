<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShareTrackTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_generates_a_token_and_returns_the_url(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/tracks/{$track->id}/share")
            ->assertOk()
            ->assertJsonStructure(['share_url']);

        $track->refresh();
        $this->assertNotNull($track->share_token);
        $this->assertStringContainsString($track->share_token, $response->json('share_url'));
    }

    public function test_share_keeps_the_same_token_when_called_again(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create(['share_token' => 'existingtoken12345']);

        $this->actingAs($user)
            ->postJson("/tracks/{$track->id}/share")
            ->assertOk();

        $this->assertSame('existingtoken12345', $track->fresh()->share_token);
    }

    public function test_share_403s_for_non_owner(): void
    {
        $track = Track::factory()->for(User::factory())->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/tracks/{$track->id}/share")
            ->assertForbidden();
    }

    public function test_unshare_clears_the_token(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->create(['share_token' => 'tokentodelete12345']);

        $this->actingAs($user)
            ->delete("/tracks/{$track->id}/share")
            ->assertNoContent();

        $this->assertNull($track->fresh()->share_token);
    }

    public function test_public_show_renders_for_a_valid_token_without_auth(): void
    {
        $track = Track::factory()->for(User::factory())->withChannels()->create([
            'share_token' => 'publicshowtoken12345',
            'original_name' => 'shared.wav',
        ]);

        // The share token is the access control: per-channel stream/peaks URLs
        // are tucked behind it and 404 the moment it's cleared (see
        // test_public_stream_stops_working_after_unshare). The presenter emits
        // those token-scoped routes — no presigned object URLs bake into a
        // share page.
        $this->get('/share/publicshowtoken12345')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Show')
                ->where('canEdit', false)
                ->where('track.id', $track->id)
                ->where('track.name', 'shared.wav')
                ->where('track.ready', true)
                ->has('track.channels', 1)
                ->where('track.channels.0.index', 0)
                ->where('track.channels.0.stream_url', route('tracks.shared-channels.stream', [$track->share_token, 0]))
                ->where('track.stream_cross_origin', 'anonymous')
            );
    }

    public function test_public_show_404s_for_unknown_token(): void
    {
        $this->get('/share/does-not-exist')->assertNotFound();
    }

    public function test_public_channel_stream_serves_audio_for_a_valid_token(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create(['share_token' => 'publicstreamtoken123']);
        $channel = $track->channels()->first();
        Storage::disk('local')->put($channel->s3_key, 'fake-opus');

        $response = $this->get("/share/{$track->share_token}/channels/0/stream");

        $response->assertOk();
        $this->assertSame('audio/webm', $response->headers->get('Content-Type'));
    }

    public function test_public_channel_stream_404s_for_unknown_token(): void
    {
        $this->get('/share/nope/channels/0/stream')->assertNotFound();
    }

    public function test_public_channel_stream_stops_working_after_unshare(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create(['share_token' => 'revoketoken123456789']);
        $channel = $track->channels()->first();
        Storage::disk('local')->put($channel->s3_key, 'fake-opus');

        // Share pages embed the token route, never a long-lived presigned URL,
        // so clearing the token revokes playback immediately rather than
        // leaving an already-issued URL valid until expiry.
        $this->get("/share/{$track->share_token}/channels/0/stream")->assertOk();

        $this->actingAs($user)->delete("/tracks/{$track->id}/share")->assertNoContent();

        $this->get('/share/revoketoken123456789/channels/0/stream')->assertNotFound();
    }
}
