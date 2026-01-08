<?php

namespace Database\Factories;

use App\Models\Epg;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EpgFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Epg::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'url' => $this->faker->url(),
            'user_id' => User::factory(),
        ];
    }
}
