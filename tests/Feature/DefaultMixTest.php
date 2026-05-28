<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultMixTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_persists_and_trims_default_mix_to_channel_count(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create();

        // The factory's withChannels() defaults to 1 channel; extra entries should be
        // trimmed, truthy "muted" coerced to bool, and floats rounded.
        $this->actingAs($user)
            ->patchJson(route('tracks.update', $track), [
                'default_mix' => [
                    ['level' => 75.4, 'pan' => -42.7, 'muted' => true],
                    ['level' => 80, 'pan' => 50, 'muted' => false], // beyond channel count
                ],
            ])
            ->assertOk()
            ->assertJsonPath('default_mix.0.level', 75)
            ->assertJsonPath('default_mix.0.pan', -43)
            ->assertJsonPath('default_mix.0.muted', true)
            ->assertJsonMissingPath('default_mix.1.level');

        $this->assertCount(1, $track->fresh()->default_mix);
    }

    public function test_update_rejects_out_of_range_values(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create();

        $this->actingAs($user)
            ->patchJson(route('tracks.update', $track), [
                'default_mix' => [['level' => 150, 'pan' => 0, 'muted' => false]],
            ])
            ->assertUnprocessable();
    }

    public function test_update_can_clear_default_mix_with_null(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create([
            'default_mix' => [['level' => 50, 'pan' => 0, 'muted' => false]],
        ]);

        $this->actingAs($user)
            ->patchJson(route('tracks.update', $track), ['default_mix' => null])
            ->assertOk()
            ->assertJsonPath('default_mix', null);

        $this->assertNull($track->fresh()->default_mix);
    }

    public function test_update_rejects_malformed_default_mix(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create();

        $this->actingAs($user)
            ->patchJson(route('tracks.update', $track), [
                'default_mix' => [['level' => 50]], // missing pan/muted
            ])
            ->assertUnprocessable();
    }

    public function test_default_mix_is_exposed_on_both_owner_and_shared_views(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withChannels()->create([
            'default_mix' => [['level' => 75, 'pan' => -25, 'muted' => false]],
            'share_token' => 'shared-token',
        ]);

        $this->actingAs($user)
            ->get(route('tracks.show', $track))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('track.default_mix.0.level', 75));

        // Shared (unauthenticated) viewers see the same saved mix.
        $this->get(route('tracks.shared', $track->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('track.default_mix.0.level', 75));
    }
}
