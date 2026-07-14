<?php

namespace Database\Factories;

use App\Models\StreamFileSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreamFileSettingFactory extends Factory
{
    protected $model = StreamFileSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(['series', 'vod']),
            'enabled' => true,
        ];
    }

    public function series(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'series',
            'path_structure' => ['category', 'series', 'season'],
        ]);
    }

    public function vod(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'vod',
            'path_structure' => ['group', 'title'],
        ]);
    }
}
