<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Services\Arr\ArrService;
use App\Services\Arr\BaseArrService;
use App\Services\Arr\RadarrService;
use App\Services\Arr\SonarrService;
use Illuminate\Support\Facades\Log;

/**
 * Hands arr-sourced cache requests back to the provider when the arr can't
 * deliver, and drops rows the arr did deliver.
 *
 * Rows are created by CachedContentArrService when an integration with
 * "Fail back to the provider" sends a movie or single episode to the arr
 * (source='arr'). The sweep, scheduled every 10 minutes, health-checks each
 * such integration, reads its queue once, then decides per row:
 *
 * - in the queue and failed -> fall back now;
 * - in the queue and downloading or importing -> wait, however long it takes;
 * - in the queue but stuck (import blocked, needs manual interaction) -> wait
 *   until the deadline, then fall back;
 * - the arr has the file -> drop the row (playback picks the title up from
 *   the media server, like #1585's InArrLibrary result);
 * - otherwise (never grabbed, or grabbed and gone) -> fall back once the
 *   deadline passes.
 *
 * Falling back is an atomic conditional update on source='arr', so a row is
 * never dispatched twice. The arr title is then unmonitored, never deleted.
 */
class ArrCacheFailbackService
{
    /**
     * How long an arr has to deliver a title before the provider is used.
     */
    public const DEADLINE_HOURS = 24;

    /**
     * Queue states where the arr is still making progress on its own.
     */
    private const ACTIVE_QUEUE_STATES = ['downloading', 'importPending', 'importing', 'imported'];

    /**
     * Sonarr episode files per TVDB id (season => [episode => hasFile]),
     * looked up once per series per sweep.
     *
     * @var array<int, array<int, array<int, bool>>>
     */
    private array $seriesFiles = [];

    public function sweep(): void
    {
        $this->releaseUntracked();

        ArrIntegration::query()
            ->enabled()
            ->cacheEnabled()
            ->cacheFailback()
            ->whereHas('cachedContentFiles', fn ($query) => $query->arrTracked())
            ->orderBy('id')
            ->get()
            ->each(function (ArrIntegration $integration): void {
                try {
                    $this->sweepIntegration($integration);
                } catch (\Throwable $e) {
                    // One bad integration must never stop the sweep.
                    Log::warning('ArrCacheFailback: integration sweep failed', [
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Hand one tracked row to the provider: switch it to a provider download
     * and unmonitor the title in the arr. False when another sweep, a cancel,
     * or a Cache Now got there first.
     */
    public function fallBack(CachedContentFile $row): bool
    {
        $switched = CachedContentFile::query()
            ->whereKey($row->getKey())
            ->arrTracked()
            ->update([
                'source' => 'provider',
                'status' => CachedContentFileStatus::Pending->value,
                'last_error_message' => null,
            ]);

        if ($switched !== 1) {
            return false;
        }

        dispatch(new DownloadCachedContentFile((int) $row->getKey()));

        $this->unmonitor($row);

        return true;
    }

    /**
     * Stop the arr looking for the title, without touching its library
     * files. Failures are logged, never fatal: the row is already handled.
     */
    public function unmonitor(CachedContentFile $row): void
    {
        try {
            $integration = $row->arrIntegration;

            if (! $integration instanceof ArrIntegration) {
                return;
            }

            $service = ArrService::make($integration);

            if ($row->content_type === 'movie') {
                /** @var RadarrService $service */
                $movieId = $service->checkExists((int) $row->tmdb_id)['id'] ?? null;

                if ($movieId !== null) {
                    $service->unmonitorMovie($movieId);
                }

                return;
            }

            /** @var SonarrService $service */
            $seriesId = $service->checkExists((int) $row->tvdb_id)['id'] ?? null;

            if ($seriesId !== null) {
                $service->unmonitorEpisode($seriesId, (int) $row->season_number, (int) $row->episode_number);
            }
        } catch (\Throwable $e) {
            Log::warning('ArrCacheFailback: unmonitor failed', [
                'row_id' => $row->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop tracking rows whose integration was deleted, disabled, or no
     * longer has caching and failback on. The arr request stands, exactly as
     * it would without failback; nothing falls back to the provider.
     */
    private function releaseUntracked(): void
    {
        CachedContentFile::query()
            ->arrTracked()
            ->whereDoesntHave('arrIntegration', fn ($query) => $query->enabled()->cacheEnabled()->cacheFailback())
            ->delete();
    }

    private function sweepIntegration(ArrIntegration $integration): void
    {
        $service = ArrService::make($integration);

        // fetchQueue() and checkExists() read failures as "nothing there", so
        // the health gate is what keeps a broken arr from being read as
        // "never grabbed".
        $health = $service->testConnection();

        if (! ($health['ok'] ?? false)) {
            Log::warning('ArrCacheFailback: arr unreachable, skipping sweep', [
                'integration_id' => $integration->id,
                'error' => $health['error'] ?? null,
            ]);

            return;
        }

        $queue = $service->fetchQueue();
        $this->seriesFiles = [];

        $rows = $integration->cachedContentFiles()->arrTracked()->orderBy('id')->get();

        foreach ($rows as $row) {
            try {
                $this->sweepRow($service, $row, $queue);
            } catch (\Throwable $e) {
                Log::warning('ArrCacheFailback: row evaluation failed', [
                    'row_id' => $row->getKey(),
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $queue
     */
    private function sweepRow(BaseArrService $service, CachedContentFile $row, array $queue): void
    {
        $queueRecord = collect($queue)->first(fn (array $record): bool => $this->matchesQueueRecord($row, $record));

        if ($queueRecord !== null) {
            $state = (string) ($queueRecord['trackedDownloadState'] ?? '');
            $failed = ($queueRecord['status'] ?? '') === 'failed' || in_array($state, ['failedPending', 'failed'], true);
            $stuck = ! in_array($state, self::ACTIVE_QUEUE_STATES, true) && $this->deadlinePassed($row);

            if ($failed || $stuck) {
                $this->fallBack($row);
            }

            return;
        }

        if ($this->arrHasFile($service, $row)) {
            CachedContentFile::query()->whereKey($row->getKey())->arrTracked()->delete();

            return;
        }

        if ($this->deadlinePassed($row)) {
            $this->fallBack($row);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function matchesQueueRecord(CachedContentFile $row, array $record): bool
    {
        if ($row->content_type === 'movie') {
            return (int) ($record['externalId'] ?? 0) === (int) $row->tmdb_id;
        }

        return (int) ($record['externalId'] ?? 0) === (int) $row->tvdb_id
            && (int) ($record['seasonNumber'] ?? -1) === (int) $row->season_number
            && (int) ($record['episodeNumber'] ?? -1) === (int) $row->episode_number;
    }

    private function arrHasFile(BaseArrService $service, CachedContentFile $row): bool
    {
        if ($row->content_type === 'movie') {
            /** @var RadarrService $service */
            return $service->checkExists((int) $row->tmdb_id)['hasFile'] ?? false;
        }

        /** @var SonarrService $service */
        $tvdbId = (int) $row->tvdb_id;

        if (! array_key_exists($tvdbId, $this->seriesFiles)) {
            $seriesId = $service->checkExists($tvdbId)['id'] ?? null;
            $this->seriesFiles[$tvdbId] = $seriesId !== null ? $service->fetchEpisodes($seriesId) : [];
        }

        return $this->seriesFiles[$tvdbId][(int) $row->season_number][(int) $row->episode_number] ?? false;
    }

    private function deadlinePassed(CachedContentFile $row): bool
    {
        return $row->arr_requested_at !== null
            && $row->arr_requested_at->lt(now()->subHours(self::DEADLINE_HOURS));
    }
}
