<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventInvite>
 */
class EventInviteFactory extends Factory
{
    protected $model = EventInvite::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'created_by' => User::factory(),
            'token' => Str::random(40),
            'label' => fake()->randomElement(['Band', 'Audience', 'Crew', null]),
            'expires_at' => null,
            'revoked_at' => null,
            'uploads_count' => 0,
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): self
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
