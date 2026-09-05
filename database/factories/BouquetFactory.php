<?php

namespace Database\Factories;

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BouquetFactory extends Factory
{
    protected $model = Bouquet::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'group_selections' => null,
            'auto_include_new_live' => false,
            'auto_include_new_vod' => false,
        ];
    }

    /**
     * Target a custom playlist instead of a standard one.
     */
    public function forCustomPlaylist(CustomPlaylist|int|null $customPlaylist = null): static
    {
        return $this->state(fn () => [
            'playlist_id' => null,
            'custom_playlist_id' => $customPlaylist ?? CustomPlaylist::factory(),
        ]);
    }

    /**
     * Target a merged playlist; selections are stored as {playlist_id, name} pairs.
     */
    public function forMergedPlaylist(MergedPlaylist|int|null $mergedPlaylist = null): static
    {
        return $this->state(fn () => [
            'playlist_id' => null,
            'merged_playlist_id' => $mergedPlaylist ?? MergedPlaylist::factory(),
        ]);
    }
}
