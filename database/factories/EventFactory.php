<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(Event::TYPES),
            'event_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'location' => fake()->city(),
            'description' => fake()->sentence(),
            'share_token' => null,
        ];
    }

    public function shared(): self
    {
        return $this->state(fn () => ['share_token' => \Illuminate\Support\Str::random(32)]);
    }
}
