<?php

namespace App\Console\Commands;

use App\Models\MediaServerIntegration;
use App\Models\Season;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateMediaServerSeries extends Command
{
    protected $signature = 'media-server:cleanup-duplicates 
        {--dry-run : Show what would be done without making changes}
        {--integration= : Specific integration ID to clean up}';

    protected $description = 'Clean up duplicate series created by media server sync with different source_series_id formats';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $integrationId = $this->option('integration');

        if ($dryRun) {
            $this->info('DRY RUN - No changes will be made');
        }

        $query = MediaServerIntegration::query();
        if ($integrationId) {
            $query->where('id', $integrationId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->error('No media server integrations found');

            return 1;
        }

        foreach ($integrations as $integration) {
            $this->cleanupIntegration($integration, $dryRun);
        }

        return 0;
    }

    protected function cleanupIntegration(MediaServerIntegration $integration, bool $dryRun): void
    {
        $this->info("Processing integration: {$integration->name} (ID: {$integration->id})");

        $playlistId = $integration->playlist_id;

        // Get all series for this playlist grouped by media_server_id
        $seriesByMediaServerId = [];
        Series::where('playlist_id', $playlistId)
            ->whereNotNull('metadata->media_server_id')
            ->each(function ($series) use (&$seriesByMediaServerId, $integration) {
                $mediaServerId = $series->metadata['media_server_id'] ?? null;
                if ($mediaServerId) {
                    $expectedCrc = crc32("media-server-{$integration->id}-{$mediaServerId}");
                    $hasCrcFormat = $series->source_series_id == $expectedCrc;

                    $seriesByMediaServerId[$mediaServerId][] = [
                        'series' => $series,
                        'has_crc_format' => $hasCrcFormat,
                        'episode_count' => $series->episodes()->count(),
                        'season_count' => $series->seasons()->count(),
                    ];
                }
            });

        $duplicateCount = 0;
        $mergedEpisodes = 0;
        $mergedSeasons = 0;
        $deletedSeries = 0;

        foreach ($seriesByMediaServerId as $mediaServerId => $entries) {
            if (count($entries) < 2) {
                continue; // No duplicates
            }

            $duplicateCount++;

            // Find the "keeper" (the one with CRC format)
            $keeper = null;
            $toDelete = [];

            foreach ($entries as $entry) {
                if ($entry['has_crc_format']) {
                    $keeper = $entry;
                } else {
                    $toDelete[] = $entry;
                }
            }

            // If no CRC format series exists, keep the first one with episodes
            if (! $keeper) {
                usort($entries, fn ($a, $b) => $b['episode_count'] <=> $a['episode_count']);
                $keeper = array_shift($entries);
                $toDelete = $entries;
            }

            $keeperSeries = $keeper['series'];

            $this->line("  Duplicate found: {$keeperSeries->name}");
            $this->line("    Keeper: ID {$keeperSeries->id} (episodes: {$keeper['episode_count']}, seasons: {$keeper['season_count']})");

            foreach ($toDelete as $entry) {
                $oldSeries = $entry['series'];
                $this->line("    Merging: ID {$oldSeries->id} (episodes: {$entry['episode_count']}, seasons: {$entry['season_count']})");

                if (! $dryRun) {
                    DB::transaction(function () use ($oldSeries, $keeperSeries, &$mergedEpisodes, &$mergedSeasons) {
                        // Map old seasons to new seasons by season_number
                        $seasonMap = [];
                        $keeperSeasons = $keeperSeries->seasons()->get()->keyBy('season_number');

                        foreach ($oldSeries->seasons as $oldSeason) {
                            $keeperSeason = $keeperSeasons->get($oldSeason->season_number);
                            if ($keeperSeason) {
                                $seasonMap[$oldSeason->id] = $keeperSeason->id;
                            } else {
                                // Move the season to the keeper series
                                $oldSeason->update(['series_id' => $keeperSeries->id]);
                                $seasonMap[$oldSeason->id] = $oldSeason->id;
                                $mergedSeasons++;
                            }
                        }

                        // Move episodes to keeper series
                        foreach ($oldSeries->episodes as $episode) {
                            $newSeasonId = $seasonMap[$episode->season_id] ?? null;
                            $episode->update([
                                'series_id' => $keeperSeries->id,
                                'season_id' => $newSeasonId ?? $episode->season_id,
                            ]);
                            $mergedEpisodes++;
                        }

                        // Delete old seasons that were mapped (not moved)
                        Season::where('series_id', $oldSeries->id)->delete();

                        // Delete the old series
                        $oldSeries->delete();
                    });
                } else {
                    $mergedEpisodes += $entry['episode_count'];
                    $mergedSeasons += $entry['season_count'];
                }

                $deletedSeries++;
            }
        }

        $this->newLine();
        $this->info("Summary for {$integration->name}:");
        $this->line("  Duplicate groups found: {$duplicateCount}");
        $this->line("  Episodes merged: {$mergedEpisodes}");
        $this->line("  Seasons merged: {$mergedSeasons}");
        $this->line("  Series deleted: {$deletedSeries}");
        $this->newLine();
    }
}
