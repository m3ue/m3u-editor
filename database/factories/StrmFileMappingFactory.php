<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\StrmFileMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

class StrmFileMappingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = StrmFileMapping::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'syncable_type' => Channel::class,
            'syncable_id' => fn (array $attributes) => Channel::factory()->create()->id,
            'sync_location' => '/tmp/strm-test',
            'current_path' => '/tmp/strm-test/'.$this->faker->word().'.strm',
            'current_url' => $this->faker->url(),
            'path_options' => [
                'use_group_folders' => true,
                'use_title_folders' => false,
            ],
        ];
    }

    /**
     * Configure the model to use an existing syncable model.
     */
    public function forSyncable($syncable): self
    {
        return $this->state(fn (array $attributes) => [
            'syncable_type' => get_class($syncable),
            'syncable_id' => $syncable->id,
        ]);
    }

    /**
     * Configure the sync location.
     */
    public function syncLocation(string $location): self
    {
        return $this->state(fn (array $attributes) => [
            'sync_location' => $location,
        ]);
    }

    /**
     * Configure the current path.
     */
    public function path(string $path): self
    {
        return $this->state(fn (array $attributes) => [
            'current_path' => $path,
        ]);
    }

    /**
     * Configure the current URL.
     */
    public function url(string $url): self
    {
        return $this->state(fn (array $attributes) => [
            'current_url' => $url,
        ]);
    }
}
