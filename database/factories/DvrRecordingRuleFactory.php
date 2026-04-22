<?php

namespace Database\Factories;

use App\Enums\DvrRuleType;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DvrRecordingRule>
 */
class DvrRecordingRuleFactory extends Factory
{
    protected $model = DvrRecordingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'dvr_setting_id' => DvrSetting::factory(),
            'type' => DvrRuleType::Once,
            'programme_id' => null,
            'series_title' => null,
            'channel_id' => null,
            'epg_channel_id' => null,
            'new_only' => false,
            'priority' => 50,
            'start_early_seconds' => null,
            'end_late_seconds' => null,
            'keep_last' => null,
            'enabled' => true,
            'manual_start' => null,
            'manual_end' => null,
        ];
    }

    public function series(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DvrRuleType::Series,
            'series_title' => fake()->words(3, true),
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DvrRuleType::Manual,
            'manual_start' => now()->addHour(),
            'manual_end' => now()->addHours(2),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
