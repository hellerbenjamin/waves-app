<?php

namespace Tests\Feature;

use App\Jobs\DetectSongs;
use App\Jobs\SplitTrackSegment;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TrackSplitEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_queues_the_job_and_stages_a_proposal(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withPeaks()->create();

        $this->actingAs($user)
            ->postJson(route('tracks.detect-songs', $track), [
                'silence_db' => -35,
                'min_silence' => 2,
                'min_region' => 45,
            ])
            ->assertOk();

        $track->refresh();
        $this->assertSame('detecting', $track->split_proposal['status']);
        $this->assertEqualsWithDelta(-35, $track->split_proposal['params']['silence_db'], 0.01);
        Bus::assertDispatched(DetectSongs::class, fn ($job) => $job->track->is($track) && (int) $job->silenceDb === -35);
    }

    public function test_detect_rejects_unowned_tracks(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $track = Track::factory()->for($owner)->withPeaks()->create();

        $this->actingAs($stranger)
            ->postJson(route('tracks.detect-songs', $track))
            ->assertForbidden();
    }

    public function test_proposal_update_normalises_regions(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withPeaks()->create([
            'split_proposal' => ['status' => 'ready', 'params' => null, 'regions' => []],
        ]);

        $this->actingAs($user)
            ->patchJson(route('tracks.split-proposal.update', $track), [
                'regions' => [
                    ['id' => 'r1', 'start' => 0.1234567, 'end' => 12.5, 'name' => '  '],
                    ['start' => 13, 'end' => 30],
                ],
            ])
            ->assertOk();

        $regions = $track->fresh()->split_proposal['regions'];
        $this->assertSame('Part 1', $regions[0]['name']); // empty/blank → default
        $this->assertSame(0.123, $regions[0]['start']);
        $this->assertSame('r2', $regions[1]['id']); // missing id → default
    }

    public function test_commit_queues_one_job_per_region_and_clears_proposal(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withPeaks()->create([
            'split_proposal' => [
                'status' => 'ready',
                'params' => null,
                'regions' => [
                    ['id' => 'r1', 'start' => 0, 'end' => 10, 'name' => 'A'],
                    ['id' => 'r2', 'start' => 10, 'end' => 20, 'name' => 'B'],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->postJson(route('tracks.split', $track))
            ->assertOk()
            ->assertJson(['queued' => 2]);

        $this->assertNull($track->fresh()->split_proposal);
        Bus::assertDispatchedTimes(SplitTrackSegment::class, 2);
    }

    public function test_discard_clears_the_proposal(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user)->withPeaks()->create([
            'split_proposal' => ['status' => 'ready', 'params' => null, 'regions' => []],
        ]);

        $this->actingAs($user)
            ->deleteJson(route('tracks.split-proposal.destroy', $track))
            ->assertNoContent();

        $this->assertNull($track->fresh()->split_proposal);
    }
}
