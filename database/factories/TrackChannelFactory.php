<?php

namespace Database\Factories;

use App\Models\Track;
use App\Models\TrackChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrackChannel>
 */
class TrackChannelFactory extends Factory
{
    protected $model = TrackChannel::class;

    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'channel_index' => 0,
            // Owner-scoped keys; finalized in configure() once the track id is
            // known so the paths mirror what the transcode job writes.
            's3_key' => 'pending/'.(string) Str::ulid().'.webm',
            'peaks_s3_key' => 'pending/'.(string) Str::ulid().'.peaks.json',
            'label' => null,
            'size' => fake()->numberBetween(100_000, 50_000_000),
        ];
    }

    public function configure(): self
    {
        return $this->afterMaking(function (TrackChannel $channel) {
            if (str_starts_with($channel->s3_key ?? '', 'pending/')) {
                $track = $channel->track ?? Track::find($channel->track_id);
                $userId = $track?->user_id ?? 'x';
                $channel->s3_key = "users/{$userId}/tracks/{$channel->track_id}/ch{$channel->channel_index}.webm";
            }
            if (str_starts_with($channel->peaks_s3_key ?? '', 'pending/')) {
                $track = $channel->track ?? Track::find($channel->track_id);
                $userId = $track?->user_id ?? 'x';
                $channel->peaks_s3_key = "users/{$userId}/tracks/{$channel->track_id}/ch{$channel->channel_index}.peaks.json";
            }
        });
    }
}
