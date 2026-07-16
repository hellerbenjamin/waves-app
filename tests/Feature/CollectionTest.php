<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Event;
use App\Models\Media;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionTest extends TestCase
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
        $this->get('/collections')->assertRedirect('/login');
    }

    public function test_index_lists_only_own_collections_with_counts(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create(['name' => 'Best Of']);
        $collection->tracks()->attach(Track::factory()->count(2)->for($user)->create());
        $collection->media()->attach(Media::factory()->for($user)->create());

        Collection::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->get('/collections')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Collections/Index')
                ->has('collections', 1)
                ->where('collections.0.name', 'Best Of')
                ->where('collections.0.tracks_count', 2)
                ->where('collections.0.media_count', 1)
            );
    }

    public function test_store_creates_collection_and_redirects_to_show(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/collections', [
            'name' => 'Tour Highlights',
            'description' => 'Best bits from the tour',
        ]);

        $collection = Collection::firstWhere('name', 'Tour Highlights');
        $this->assertNotNull($collection);
        $this->assertSame($user->id, $collection->user_id);
        $response->assertRedirect(route('collections.show', $collection->id));
    }

    public function test_show_403s_for_other_users_collection(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for(User::factory())->create();

        $this->actingAs($user)->get("/collections/{$collection->id}")->assertForbidden();
    }

    public function test_show_returns_tracks_and_media_from_multiple_events(): void
    {
        // Local disk so playback/object URLs are plain app routes (no presigning).
        config(['filesystems.tracks_disk' => 'local']);

        $user = User::factory()->create();
        $eventA = Event::factory()->for($user)->create();
        $eventB = Event::factory()->for($user)->create();

        $collection = Collection::factory()->for($user)->create();
        $collection->tracks()->attach(Track::factory()->for($user)->withChannels()->create(['event_id' => $eventA->id]));
        $collection->media()->attach(Media::factory()->for($user)->withThumb()->create(['event_id' => $eventB->id]));

        $this->actingAs($user)
            ->get("/collections/{$collection->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Collections/Show')
                ->where('canEdit', true)
                ->has('collection.tracks', 1)
                ->has('collection.media', 1)
                ->where('collection.media.0.kind', 'image')
            );
    }

    public function test_attach_adds_only_owned_items_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $mine = Track::factory()->count(2)->for($user)->create();
        $theirs = Track::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->post("/collections/{$collection->id}/items", [
                'type' => 'track',
                'ids' => [$mine[0]->id, $mine[1]->id, $theirs->id],
            ])
            ->assertRedirect();

        // Re-attaching the same items must not create duplicate pivot rows.
        $this->actingAs($user)
            ->post("/collections/{$collection->id}/items", [
                'type' => 'track',
                'ids' => [$mine[0]->id],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$mine[0]->id, $mine[1]->id],
            $collection->tracks()->pluck('tracks.id')->all(),
        );
    }

    public function test_detach_removes_item_but_keeps_it(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create();
        $collection->tracks()->attach($track);

        $this->actingAs($user)
            ->delete("/collections/{$collection->id}/items", [
                'type' => 'track',
                'ids' => [$track->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, $collection->tracks()->count());
        $this->assertDatabaseHas('tracks', ['id' => $track->id]);
    }

    public function test_attach_403s_for_other_users_collection(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for(User::factory())->create();
        $track = Track::factory()->for($user)->create();

        $this->actingAs($user)
            ->post("/collections/{$collection->id}/items", ['type' => 'track', 'ids' => [$track->id]])
            ->assertForbidden();
    }

    public function test_deleting_a_track_detaches_it_from_collections(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create();
        $collection->tracks()->attach($track);

        $track->delete();

        $this->assertDatabaseMissing('collectables', [
            'collection_id' => $collection->id,
            'collectable_type' => Track::class,
            'collectable_id' => $track->id,
        ]);
    }

    public function test_destroy_removes_collection_but_keeps_items(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $track = Track::factory()->for($user)->create();
        $media = Media::factory()->for($user)->create();
        $collection->tracks()->attach($track);
        $collection->media()->attach($media);

        $this->actingAs($user)
            ->delete("/collections/{$collection->id}")
            ->assertRedirect(route('collections.index'));

        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        $this->assertDatabaseHas('tracks', ['id' => $track->id]);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_share_then_unshare_toggles_token(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/collections/{$collection->id}/share")
            ->assertOk()
            ->assertJsonStructure(['share_url']);
        $this->assertNotNull($collection->fresh()->share_token);

        $this->actingAs($user)
            ->deleteJson("/collections/{$collection->id}/share")
            ->assertNoContent();
        $this->assertNull($collection->fresh()->share_token);
    }

    public function test_shared_collection_page_renders_publicly(): void
    {
        config(['filesystems.tracks_disk' => 'local']);

        $collection = Collection::factory()->shared()->create();

        $this->get("/collections/share/{$collection->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Collections/Show')
                ->where('canEdit', false)
            );
    }

    public function test_shared_media_stream_404s_for_non_member(): void
    {
        $collection = Collection::factory()->shared()->create();
        $media = Media::factory()->for($collection->user)->create(); // not in the collection

        $this->get("/collections/share/{$collection->share_token}/media/{$media->id}/stream")
            ->assertNotFound();
    }

    public function test_candidates_returns_only_own_items(): void
    {
        $user = User::factory()->create();
        Track::factory()->for($user)->create(['original_name' => 'mine.wav']);
        Media::factory()->for($user)->create();
        Track::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->getJson('/collections/candidates')
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonCount(1, 'media')
            ->assertJsonPath('tracks.0.name', 'mine.wav');
    }
}
