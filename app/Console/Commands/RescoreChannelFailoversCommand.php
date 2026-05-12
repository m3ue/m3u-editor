<?php

namespace App\Console\Commands;

use App\Jobs\RescoreChannelFailovers;
use App\Models\Playlist;
use Illuminate\Console\Command;

class RescoreChannelFailoversCommand extends Command
{
    /**
     * Allowed interval keys → number of seconds between rescores.
     */
    private const INTERVALS = [
        'daily' => 86400,
        'weekly' => 604800,
    ];

    protected $signature = 'app:rescore-channel-failovers {playlist? : Optional playlist ID to rescore directly}';

    protected $description = 'Dispatch RescoreChannelFailovers for any playlist whose configured interval has elapsed';

    public function handle(): int
    {
        $playlistId = $this->argument('playlist');

        if ($playlistId !== null) {
            $playlist = Playlist::find($playlistId);
            if (! $playlist) {
                $this->error("Playlist {$playlistId} not found");

                return Command::FAILURE;
            }

            dispatch(new RescoreChannelFailovers($playlist->id));
            $this->info("Dispatched failover rescore for playlist {$playlist->id}");

            return Command::SUCCESS;
        }

        $playlists = Playlist::query()
            ->whereNotNull('auto_rescore_failovers_interval')
            ->get();

        $dispatched = 0;
        foreach ($playlists as $playlist) {
            $intervalKey = strtolower((string) $playlist->auto_rescore_failovers_interval);
            $intervalSeconds = self::INTERVALS[$intervalKey] ?? null;
            if ($intervalSeconds === null) {
                continue;
            }

            $lastRun = $playlist->last_failover_rescore_at;
            if ($lastRun !== null && $lastRun->copy()->addSeconds($intervalSeconds)->gt(now())) {
                continue;
            }

            dispatch(new RescoreChannelFailovers($playlist->id));
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} playlist(s) for failover rescoring");

        return Command::SUCCESS;
    }
}
