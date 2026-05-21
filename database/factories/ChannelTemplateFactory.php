<?php

namespace Database\Factories;

use App\Models\ChannelTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelTemplate>
 */
class ChannelTemplateFactory extends Factory
{
    protected $model = ChannelTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'labels' => ['Kick', 'Snare', 'Bass', 'Vox'],
        ];
    }
}
