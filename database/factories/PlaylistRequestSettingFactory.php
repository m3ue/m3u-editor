<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistRequestSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaylistRequestSetting>
 */
class PlaylistRequestSettingFactory extends Factory
{
    protected $model = PlaylistRequestSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'user_id' => User::factory(),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
