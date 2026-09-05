<?php

namespace Database\Factories;

use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DynamicGroupItemSnapshot>
 */
class DynamicGroupItemSnapshotFactory extends Factory
{
    protected $model = DynamicGroupItemSnapshot::class;

    public function definition(): array
    {
        return [
            'dynamic_group_id' => DynamicGroup::factory(),
            'sync_run_id' => SyncRun::factory(),
            'item_type' => 'App\\Models\\Channel',
            'item_id' => $this->faker->numberBetween(1, 1_000_000),
            'captured_at' => now(),
        ];
    }
}
