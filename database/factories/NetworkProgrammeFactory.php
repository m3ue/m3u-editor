<?php

namespace Database\Factories;

use App\Models\Network;
use App\Models\NetworkProgramme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NetworkProgramme>
 */
class NetworkProgrammeFactory extends Factory
{
    protected $model = NetworkProgramme::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addMinutes(fake()->numberBetween(0, 60));
        $duration = fake()->numberBetween(30, 120); // 30-120 minutes

        return [
            'network_id' => Network::factory(),
            'title' => fake()->words(3, true),
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($duration),
            'duration_seconds' => $duration * 60,
            'contentable_type' => \App\Models\Channel::class,
            'contentable_id' => 1, // Dummy ID - tests should override with real content
        ];
    }
}
