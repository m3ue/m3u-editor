<?php

namespace Database\Factories;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DvrRecording>
 */
class DvrRecordingFactory extends Factory
{
    protected $model = DvrRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+7 days');
        $end = (clone $start)->modify('+1 hour');

        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'dvr_setting_id' => DvrSetting::factory(),
            'dvr_recording_rule_id' => null,
            'channel_id' => null,
            'status' => DvrRecordingStatus::Scheduled,
            'title' => fake()->words(3, true),
            'subtitle' => fake()->optional()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'season' => fake()->optional()->numberBetween(1, 20),
            'episode' => fake()->optional()->numberBetween(1, 24),
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'actual_start' => null,
            'actual_end' => null,
            'duration_seconds' => null,
            'file_path' => null,
            'file_size_bytes' => null,
            'stream_url' => null,
            'metadata' => null,
            'error_message' => null,
            'programme_start' => $start,
            'programme_end' => $end,
            'epg_programme_data' => null,
            'pid' => null,
        ];
    }

    public function recording(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DvrRecordingStatus::Recording,
            'actual_start' => now(),
            'pid' => fake()->numberBetween(1000, 65535),
        ]);
    }

    public function completed(): static
    {
        $start = now()->subHour();
        $end = now()->subMinutes(5);

        return $this->state(fn (array $attributes) => [
            'status' => DvrRecordingStatus::Completed,
            'actual_start' => $start,
            'actual_end' => $end,
            'duration_seconds' => 3300,
            'file_path' => 'recordings/show/Season 01/show - S01E01 - episode.ts',
            'file_size_bytes' => fake()->numberBetween(500_000_000, 5_000_000_000),
            'scheduled_start' => $start,
            'scheduled_end' => $end,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DvrRecordingStatus::Failed,
            'error_message' => fake()->sentence(),
        ]);
    }
}
