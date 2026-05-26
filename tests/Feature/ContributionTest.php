<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Models\Event;
use App\Models\EventInvite;
use App\Models\User;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ContributionTest extends TestCase
{
    use RefreshDatabase;

    /** A usable invite on a fresh owner's event. */
    private function invite(array $state = []): EventInvite
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        return EventInvite::factory()->for($event)->create(['created_by' => $owner->id] + $state);
    }

    public function test_show_renders_the_contribution_page(): void
    {
        $this->withoutVite(); // new Inertia page; not in the stale prod manifest
        $invite = $this->invite(['label' => 'Band']);

        $this->get(route('contribute.show', $invite->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Contribute/Show')
                ->where('invite.active', true)
                ->where('invite.label', 'Band')
                ->where('event.name', $invite->event->name)
            );
    }

    public function test_show_marks_a_revoked_link_inactive_without_erroring(): void
    {
        $this->withoutVite();
        $invite = $this->invite(['revoked_at' => now()]);

        $this->get(route('contribute.show', $invite->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invite.active', false));
    }

    public function test_show_404s_for_an_unknown_token(): void
    {
        $this->get('/contribute/does-not-exist')->assertNotFound();
    }

    public function test_upload_url_returns_a_target_scoped_to_the_event(): void
    {
        $invite = $this->invite();

        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('temporaryUploadUrl')->once()->andReturn([
            'url' => 'https://s3.example/signed',
            'headers' => [],
        ]);
        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->postJson(route('contribute.upload-url', $invite->token), [
            'filename' => 'beach.jpg',
            'size' => 2048,
            'content_type' => 'image/jpeg',
        ])->assertOk()->assertJsonStructure(['url', 'headers', 's3_key']);

        $this->assertStringStartsWith("media/events/{$invite->event_id}/contrib/", $response->json('s3_key'));
        $this->assertStringEndsWith('.jpg', $response->json('s3_key'));
    }

    public function test_upload_url_410s_for_an_expired_link(): void
    {
        $invite = $this->invite(['expires_at' => now()->subDay()]);

        $this->postJson(route('contribute.upload-url', $invite->token), [
            'filename' => 'x.jpg',
            'size' => 100,
            'content_type' => 'image/jpeg',
        ])->assertStatus(410);
    }

    public function test_store_creates_owner_owned_media_with_attribution_and_no_auth(): void
    {
        Storage::fake('s3');
        Bus::fake();
        $invite = $this->invite();

        $key = "media/events/{$invite->event_id}/contrib/photo.jpg";
        Storage::disk('s3')->put($key, 'bytes');

        // No actingAs: this is the whole point — an anonymous contributor.
        $this->post(route('contribute.store', $invite->token), [
            's3_key' => $key,
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
            'contributor_name' => 'Jane',
        ])->assertRedirect();

        $this->assertDatabaseHas('media', [
            's3_key' => $key,
            'user_id' => $invite->event->user_id, // belongs to the owner, not a guest
            'event_id' => $invite->event_id,
            'event_invite_id' => $invite->id,
            'contributor_name' => 'Jane',
            'kind' => 'image',
        ]);
        $this->assertSame(1, $invite->fresh()->uploads_count);
        Bus::assertDispatched(GenerateThumbnail::class);
    }

    public function test_store_410s_for_a_revoked_link(): void
    {
        Storage::fake('s3');
        Bus::fake();
        $invite = $this->invite(['revoked_at' => now()]);

        $key = "media/events/{$invite->event_id}/contrib/x.jpg";
        Storage::disk('s3')->put($key, 'bytes');

        $this->postJson(route('contribute.store', $invite->token), [
            's3_key' => $key,
            'original_name' => 'x.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
        ])->assertStatus(410);

        $this->assertDatabaseCount('media', 0);
    }

    public function test_store_rejects_a_key_for_another_event(): void
    {
        Storage::fake('s3');
        Bus::fake();
        $invite = $this->invite();

        $this->postJson(route('contribute.store', $invite->token), [
            's3_key' => 'media/events/999999/contrib/x.jpg',
            'original_name' => 'x.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('s3_key');
    }

    public function test_sign_part_rejects_a_key_for_another_event(): void
    {
        $invite = $this->invite();
        $otherEvent = "media/events/{$invite->event_id}999/contrib/x.mp4";

        $this->getJson(route('contribute.multipart.sign', $invite->token)
            .'?key='.urlencode($otherEvent).'&uploadId=UP1&partNumber=1')
            ->assertForbidden();
    }

    public function test_create_multipart_returns_an_upload_id_scoped_to_the_event(): void
    {
        $invite = $this->invite();

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('createMultipartUpload')->once()->andReturn(new Result(['UploadId' => 'UP9']));
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->andReturn($client);
        Storage::shouldReceive('disk')->andReturn($disk);

        $response = $this->postJson(route('contribute.multipart.create', $invite->token), [
            'filename' => 'show.mp4',
            'size' => 8_000_000_000,
            'content_type' => 'video/mp4',
        ])->assertOk()->assertJsonStructure(['key', 'uploadId']);

        $this->assertSame('UP9', $response->json('uploadId'));
        $this->assertStringStartsWith("media/events/{$invite->event_id}/contrib/", $response->json('key'));
    }

    // --- Owner-side invite management -----------------------------------------

    public function test_owner_can_mint_an_invite(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.invites.store', $event->id), ['label' => 'Band'])
            ->assertRedirect();

        $this->assertDatabaseHas('event_invites', [
            'event_id' => $event->id,
            'label' => 'Band',
            'created_by' => $owner->id,
        ]);
    }

    public function test_non_owner_cannot_mint_an_invite(): void
    {
        $event = Event::factory()->for(User::factory())->create();

        $this->actingAs(User::factory()->create())
            ->post(route('events.invites.store', $event->id), ['label' => 'X'])
            ->assertForbidden();

        $this->assertDatabaseCount('event_invites', 0);
    }

    public function test_owner_can_revoke_an_invite(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $invite = EventInvite::factory()->for($event)->create();

        $this->actingAs($owner)
            ->delete(route('events.invites.destroy', [$event->id, $invite->id]))
            ->assertRedirect();

        $this->assertNotNull($invite->fresh()->revoked_at);
    }

    public function test_revoke_403s_for_a_non_owner(): void
    {
        $event = Event::factory()->for(User::factory())->create();
        $invite = EventInvite::factory()->for($event)->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('events.invites.destroy', [$event->id, $invite->id]))
            ->assertForbidden();

        $this->assertNull($invite->fresh()->revoked_at);
    }
}
