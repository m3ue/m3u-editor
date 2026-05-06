<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'uuid' => (string) Str::uuid(),
            'playlist_id' => Playlist::factory()->for($user),
            'user_id' => $user,
            'kind' => 'full',
            'trigger' => 'manual',
            'status' => SyncRunStatus::Pending,
            'phases' => [],
            'errors' => null,
            'meta' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forPlaylist(Playlist $playlist): self
    {
        return $this->state(fn () => [
            'playlist_id' => $playlist->getKey(),
            'user_id' => $playlist->user_id,
        ]);
    }

    public function running(): self
    {
        return $this->state(fn () => [
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn () => [
            'status' => SyncRunStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn () => [
            'status' => SyncRunStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'errors' => [[
                'message' => 'Synthetic failure',
                'phase' => null,
                'at' => now()->toIso8601String(),
            ]],
        ]);
    }
}
