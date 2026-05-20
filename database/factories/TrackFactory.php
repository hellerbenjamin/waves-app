<?php

namespace Database\Factories;

use App\Models\Track;
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
            'peaks' => null,
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

    public function withPeaks(): self
    {
        return $this->state(fn () => [
            'peaks' => ['channels' => [[0.1, -0.1, 0.2, -0.2]], 'sample_rate' => 44100],
            'duration_seconds' => 123.4,
        ]);
    }
}
