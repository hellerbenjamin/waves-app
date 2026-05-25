<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => null,
            's3_key' => 'media/users/x/'.(string) Str::ulid().'.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(100_000, 10_000_000),
            'kind' => 'image',
            'width' => null,
            'height' => null,
            'thumb_key' => null,
            'share_token' => null,
        ];
    }

    public function configure(): self
    {
        // Rewrite the placeholder owner segment to the real user id once known,
        // so the key matches the ownership-by-prefix checks.
        return $this->afterMaking(function (Media $media) {
            if ($media->user_id && str_starts_with($media->s3_key ?? '', 'media/users/x/')) {
                $ext = pathinfo($media->s3_key, PATHINFO_EXTENSION);
                $media->s3_key = "media/users/{$media->user_id}/".(string) Str::ulid().'.'.$ext;
            }
        });
    }

    public function video(): self
    {
        return $this->state(fn () => [
            's3_key' => 'media/users/x/'.(string) Str::ulid().'.mp4',
            'original_name' => fake()->word().'.mp4',
            'mime' => 'video/mp4',
            'kind' => 'video',
        ]);
    }

    public function withThumb(): self
    {
        return $this->state(fn (array $attrs) => [
            'thumb_key' => 'media/users/x/thumbs/'.(string) Str::ulid().'.jpg',
            'width' => 1600,
            'height' => 1200,
        ]);
    }

    public function shared(): self
    {
        return $this->state(fn () => ['share_token' => Str::random(32)]);
    }
}
