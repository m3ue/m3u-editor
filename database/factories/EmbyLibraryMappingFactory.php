<?php

namespace Database\Factories;

use App\Models\EmbyLibraryMapping;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbyLibraryMapping>
 */
class EmbyLibraryMappingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_server_integration_id' => fn (array $attributes): int => MediaServerIntegration::factory()
                ->for(User::query()->findOrFail($attributes['user_id']))
                ->createQuietly(['type' => 'emby'])
                ->id,
            'enabled' => true,
            'source_kind' => 'vod_group',
            'source_identifier' => (string) fake()->unique()->numberBetween(1, 100000),
            'source_label' => fake()->words(2, true),
            'target_library_id' => fake()->uuid(),
            'target_library_name' => fake()->words(2, true),
            'collection_type' => 'movies',
            'output_path' => '/media/m3u-editor/'.fake()->slug(),
            'is_managed' => true,
            'options' => [
                'naming' => 'media-year',
                'nfo' => true,
                'versions' => true,
                'cleanup' => 'replace',
                'refresh' => true,
            ],
        ];
    }
}
