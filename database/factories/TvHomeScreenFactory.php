<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\TvHomeScreen;
use App\Models\User;
use App\Support\TvClientSettings;
use App\Support\TvHomeScreenLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TvHomeScreen>
 */
class TvHomeScreenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'name' => fake()->words(2, true),
            'description' => null,
            'mode' => TvClientSettings::MODE_DEFAULT,
            'sections' => TvHomeScreenLayout::defaultSections(),
        ];
    }

    public function enforced(): static
    {
        return $this->state(fn (): array => ['mode' => TvClientSettings::MODE_ENFORCED]);
    }
}
