<?php

namespace Database\Factories;

use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DynamicGroup>
 */
class DynamicGroupFactory extends Factory
{
    protected $model = DynamicGroup::class;

    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'user_id' => User::factory(),
            'type' => 'vod',
            'source' => 'trending',
            'name' => $this->faker->words(3, true),
            'tmdb_params' => [],
            'sort_order' => 0,
            'enabled' => true,
        ];
    }
}
