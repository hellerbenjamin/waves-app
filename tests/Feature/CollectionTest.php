<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
        $collection = Collection::factory()->for($user)->create(['name' => 'Best of 2026']);
        Media::factory()->count(2)->for($user)->create(['collection_id' => $collection->id]);

        Collection::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->get('/collections')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Collections/Index')
                ->has('collections', 1)
                ->where('collections.0.name', 'Best of 2026')
                ->where('collections.0.media_count', 2)
            );
    }

    public function test_store_creates_collection_and_redirects_to_show(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/collections', [
            'name' => 'Tour Highlights',
            'description' => 'Favourite shots from the run.',
        ]);

        $collection = Collection::firstWhere('name', 'Tour Highlights');
        $this->assertNotNull($collection);
        $this->assertSame($user->id, $collection->user_id);
        $response->assertRedirect(route('collections.show', $collection->id));
    }

    public function test_store_requires_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/collections', ['description' => 'no name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_show_403s_for_other_users_collection(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for(User::factory())->create();

        $this->actingAs($user)->get("/collections/{$collection->id}")->assertForbidden();
    }

    public function test_show_returns_collection_with_media(): void
    {
        // Local disk so playback/object URLs are plain app routes (no presigning).
        config(['filesystems.tracks_disk' => 'local']);

        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        Media::factory()->for($user)->withThumb()->create(['collection_id' => $collection->id]);

        $this->actingAs($user)
            ->get("/collections/{$collection->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Collections/Show')
                ->where('canEdit', true)
                ->has('collection.media', 1)
                ->where('collection.media.0.kind', 'image')
            );
    }

    public function test_destroy_removes_collection_but_keeps_media(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $media = Media::factory()->for($user)->create(['collection_id' => $collection->id]);

        $this->actingAs($user)
            ->delete("/collections/{$collection->id}")
            ->assertRedirect(route('collections.index'));

        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'collection_id' => null]);
    }

    public function test_destroy_403s_for_other_users_collection(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for(User::factory())->create();

        $this->actingAs($user)->delete("/collections/{$collection->id}")->assertForbidden();
        $this->assertDatabaseHas('collections', ['id' => $collection->id]);
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

    public function test_attach_media_assigns_only_owned_media(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $mine = Media::factory()->count(2)->for($user)->create();
        $theirs = Media::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->post("/collections/{$collection->id}/media", [
                'media_ids' => [$mine[0]->id, $mine[1]->id, $theirs->id],
            ])
            ->assertRedirect();

        $this->assertSame($collection->id, $mine[0]->fresh()->collection_id);
        $this->assertSame($collection->id, $mine[1]->fresh()->collection_id);
        $this->assertNull($theirs->fresh()->collection_id);
    }

    public function test_detach_media_clears_collection_id(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $media = Media::factory()->for($user)->create(['collection_id' => $collection->id]);

        $this->actingAs($user)
            ->delete("/collections/{$collection->id}/media/{$media->id}")
            ->assertRedirect();

        $this->assertNull($media->fresh()->collection_id);
    }

    public function test_detach_404s_when_media_not_in_collection(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();
        $media = Media::factory()->for($user)->create(); // no collection

        $this->actingAs($user)
            ->delete("/collections/{$collection->id}/media/{$media->id}")
            ->assertNotFound();
    }

    public function test_uploaded_media_can_land_straight_in_a_collection(): void
    {
        config(['filesystems.tracks_disk' => 'local']);

        $user = User::factory()->create();
        $collection = Collection::factory()->for($user)->create();

        $key = "media/users/{$user->id}/".Str::ulid().'.jpg';
        Storage::disk('local')->put($key, 'bytes');

        $this->actingAs($user)
            ->post('/media', [
                's3_key' => $key,
                'original_name' => 'photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 1234,
                'collection_id' => $collection->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('media', [
            's3_key' => $key,
            'collection_id' => $collection->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_store_media_rejects_collection_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $collection = Collection::factory()->for(User::factory())->create();

        $key = "media/users/{$user->id}/".Str::ulid().'.jpg';

        $this->actingAs($user)
            ->postJson('/media', [
                's3_key' => $key,
                'original_name' => 'photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 1234,
                'collection_id' => $collection->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection_id');
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

    public function test_shared_media_download_redirects_publicly_for_member(): void
    {
        $collection = Collection::factory()->shared()->create();
        $media = Media::factory()->for($collection->user)->create(['collection_id' => $collection->id]);

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://s3.example/signed-download');
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->get("/collections/share/{$collection->share_token}/media/{$media->id}/download")
            ->assertRedirect('https://s3.example/signed-download');
    }

    public function test_shared_media_download_404s_for_non_member(): void
    {
        $collection = Collection::factory()->shared()->create();
        $media = Media::factory()->for($collection->user)->create(); // not in the collection

        $this->get("/collections/share/{$collection->share_token}/media/{$media->id}/download")
            ->assertNotFound();
    }
}
