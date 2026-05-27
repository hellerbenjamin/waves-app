<?php

namespace Tests\Feature;

use App\Models\ChannelTemplate;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_template_scoped_to_user_and_normalises_labels(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/channel-templates', [
                'name' => 'Drum kit',
                'labels' => ['  Kick  ', '', 'Snare'],
            ])
            ->assertCreated()
            ->assertJson([
                'name' => 'Drum kit',
                'labels' => ['Kick', null, 'Snare'],
            ]);

        $this->assertDatabaseHas('channel_templates', [
            'user_id' => $user->id,
            'name' => 'Drum kit',
        ]);
    }

    public function test_store_requires_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/channel-templates', ['labels' => ['Kick']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_destroy_403s_for_another_users_template(): void
    {
        $user = User::factory()->create();
        $template = ChannelTemplate::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->delete("/channel-templates/{$template->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('channel_templates', ['id' => $template->id]);
    }

    public function test_destroy_removes_own_template(): void
    {
        $user = User::factory()->create();
        $template = ChannelTemplate::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete("/channel-templates/{$template->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('channel_templates', ['id' => $template->id]);
    }

    public function test_track_show_includes_only_the_users_templates(): void
    {
        $user = User::factory()->create();
        $mine = ChannelTemplate::factory()->for($user)->create(['name' => 'Mine']);
        ChannelTemplate::factory()->for(User::factory())->create(['name' => 'Theirs']);

        $track = Track::factory()->for($user)->withChannels()->create();

        $this->actingAs($user)
            ->get("/tracks/{$track->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Show')
                ->has('templates', 1)
                ->where('templates.0.id', $mine->id)
                ->where('templates.0.name', 'Mine')
            );
    }
}
