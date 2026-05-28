<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\TrackChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_has_channels_in_index_order(): void
    {
        $track = Track::factory()->create();
        // Insert out of order to make sure the relationship's orderBy bites.
        TrackChannel::factory()->create(['track_id' => $track->id, 'channel_index' => 2, 'label' => 'Bass']);
        TrackChannel::factory()->create(['track_id' => $track->id, 'channel_index' => 0, 'label' => 'Kick']);
        TrackChannel::factory()->create(['track_id' => $track->id, 'channel_index' => 1, 'label' => 'Snare']);

        $labels = $track->channels()->pluck('label')->all();

        $this->assertSame(['Kick', 'Snare', 'Bass'], $labels);
    }

    public function test_deleting_a_track_cascades_to_its_channels(): void
    {
        $track = Track::factory()->create();
        TrackChannel::factory()->count(3)->sequence(
            ['channel_index' => 0],
            ['channel_index' => 1],
            ['channel_index' => 2],
        )->create(['track_id' => $track->id]);

        $this->assertSame(3, TrackChannel::where('track_id', $track->id)->count());

        $track->delete();

        $this->assertSame(0, TrackChannel::where('track_id', $track->id)->count());
    }

    public function test_a_track_channel_index_is_unique_within_a_track(): void
    {
        $track = Track::factory()->create();
        TrackChannel::factory()->create(['track_id' => $track->id, 'channel_index' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        TrackChannel::factory()->create(['track_id' => $track->id, 'channel_index' => 0]);
    }
}
