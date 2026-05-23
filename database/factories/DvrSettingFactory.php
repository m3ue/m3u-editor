<?php

namespace Database\Factories;

use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DvrSetting>
 */
class DvrSettingFactory extends Factory
{
    protected $model = DvrSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'user_id' => User::factory(),
            'enabled' => false,
            'use_proxy' => false,
            'storage_disk' => 'dvr',
            'max_concurrent_recordings' => 2,
            'default_start_early_seconds' => 30,
            'default_end_late_seconds' => 30,
            'enable_metadata_enrichment' => true,
            'tmdb_api_key' => null,
            'global_disk_quota_gb' => null,
            'retention_days' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
        ]);
    }
}
