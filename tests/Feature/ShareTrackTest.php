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
        $track = Track::factory()->for(User::factory())->withPeaks()->create([
            'share_token' => 'publicshowtoken12345',
            'original_name' => 'shared.wav',
        ]);

        $this->get('/share/publicshowtoken12345')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Show')
                ->where('canEdit', false)
                ->where('track.id', $track->id)
                ->where('track.name', 'shared.wav')
                ->where('track.stream_url', route('tracks.shared-stream', $track->share_token))
            );
    }

    public function test_public_show_404s_for_unknown_token(): void
    {
        $this->get('/share/does-not-exist')->assertNotFound();
    }

    public function test_public_stream_serves_audio_for_a_valid_token(): void
    {
        config(['filesystems.tracks_disk' => 'local']);
        Storage::fake('local');

        $user = User::factory()->create();
        $key = "users/{$user->id}/shared.wav";
        Storage::disk('local')->put($key, 'RIFF....WAVEfake');
        $track = Track::factory()->for($user)->create([
            's3_key' => $key,
            'mime' => 'audio/wav',
            'share_token' => 'publicstreamtoken123',
        ]);

        $response = $this->get("/share/{$track->share_token}/stream");

        $response->assertOk();
        $this->assertSame('audio/wav', $response->headers->get('Content-Type'));
    }

    public function test_public_stream_404s_for_unknown_token(): void
    {
        $this->get('/share/nope/stream')->assertNotFound();
    }
}
