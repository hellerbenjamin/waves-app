<?php

namespace Database\Factories;

use App\Models\Track;
use App\Models\TrackChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            's3_key' => 'users/x/'.(string) Str::ulid().'.wav',
            'original_name' => fake()->word().'.wav',
            'mime' => 'audio/wav',
            'size' => fake()->numberBetween(1_000_000, 50_000_000),
            'content_hash' => null,
            'channels_count' => null,
            'sample_rate' => null,
            'duration_seconds' => null,
        ];
    }

    public function configure(): self
    {
        return $this->afterMaking(function (Track $track) {
            if ($track->user_id && str_starts_with($track->s3_key ?? '', 'users/x/')) {
                $track->s3_key = 'users/'.$track->user_id.'/'.(string) Str::ulid().'.wav';
            }
        });
    }

    /**
     * Tests that need a "fully transcoded" track use this: attaches N
     * TrackChannel rows, sets channels_count to match, and nulls out s3_key
     * (transcode is the step that drops the WAV).
     */
    public function withChannels(int $channels = 1): self
    {
        return $this->state(fn () => [
            's3_key' => null,
            'channels_count' => $channels,
            'sample_rate' => 48000,
            'duration_seconds' => 123.4,
        ])->afterCreating(function (Track $track) use ($channels) {
            for ($c = 0; $c < $channels; $c++) {
                TrackChannel::factory()->create([
                    'track_id' => $track->id,
                    'channel_index' => $c,
                ]);
            }
        });
    }
}
