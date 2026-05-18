<?php

namespace Database\Factories;

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    public function definition(): array
    {
        $playlist = Playlist::factory()->create();

        return [
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'trigger' => 'full_sync',
            'status' => SyncRunStatus::Completed->value,
            'phases' => [SyncRunPhase::SyncCompleted->value],
            'phase_statuses' => [SyncRunPhase::SyncCompleted->value => 'completed'],
            'context' => ['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id],
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ];
    }

    public function withPhasesCompleted(SyncRunPhase ...$phases): static
    {
        return $this->state(function (array $attributes) use ($phases): array {
            $statuses = $attributes['phase_statuses'] ?? [];
            $phaseValues = $attributes['phases'] ?? [];

            foreach ($phases as $phase) {
                $statuses[$phase->value] = 'completed';
                if (! in_array($phase->value, $phaseValues)) {
                    $phaseValues[] = $phase->value;
                }
            }

            return ['phases' => $phaseValues, 'phase_statuses' => $statuses];
        });
    }
}
