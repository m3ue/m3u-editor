<?php

namespace Database\Factories;

use App\Models\PlaylistViewer;
use App\Models\ViewerSportsFollow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ViewerSportsFollow>
 */
class ViewerSportsFollowFactory extends Factory
{
    protected $model = ViewerSportsFollow::class;

    public function definition(): array
    {
        $teamId = (string) fake()->unique()->numberBetween(1, 99999);

        return [
            'playlist_viewer_id' => PlaylistViewer::factory(),
            'team_key' => "hockey/nhl:{$teamId}",
            'league_id' => 'hockey/nhl',
            'team_id' => $teamId,
            'name' => fake()->city().' '.fake()->word(),
            'followed_at' => now(),
        ];
    }
}
