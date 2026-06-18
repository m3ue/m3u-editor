<?php

namespace Database\Factories;

use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrQueueEvent>
 */
class ArrQueueEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'arr_integration_id' => ArrIntegration::factory(),
            'user_id' => User::factory(),
            'download_id' => null,
            'external_id' => (string) fake()->randomNumber(6),
            'title' => fake()->words(3, true),
            'event_type' => fake()->randomElement(['MovieAdded', 'SeriesAdd', 'Grab', 'Download']),
            'status' => 'monitored',
            'quality' => null,
            'release_title' => null,
            'size' => 0,
            'progress' => 0,
            'last_event_at' => now(),
        ];
    }
}
