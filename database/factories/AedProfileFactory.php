<?php

namespace Database\Factories;

use App\Models\AedProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AedProfileFactory extends Factory
{
    protected $model = AedProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'event_duration_minutes' => 180,
            'source_timezone' => 'UTC',
            'output_timezone' => 'UTC',
            'title_format' => '{title}',
            'pre_event_format' => 'Live in {time_until}: {title}',
            'post_event_format' => 'Signing Off',
        ];
    }
}
