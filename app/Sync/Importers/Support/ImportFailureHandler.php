<?php

namespace App\Sync\Importers\Support;

use App\Enums\Status;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Consolidates the half-dozen near-duplicate failure paths previously inlined
 * in ProcessM3uImport.
 *
 * Every failure does the same core work — log, notify (broadcast + database),
 * mark the Playlist as Failed, clear processing flags, dispatch the synced
 * event — and varies only in a small set of column overrides plus whether to
 * attempt a 503 auto-retry. Callers express those variations via named args.
 *
 * Stateless and side-effect heavy by nature; intended for direct invocation.
 */
final class ImportFailureHandler
{
    /**
     * @param  string  $errors  Stored verbatim in playlists.errors and shown in the database notification.
     * @param  string|null  $broadcastBody  Defaults to the generic "view notifications" message.
     * @param  Throwable|null  $exception  Optional cause; when paired with $tryRetry503, may schedule a retry.
     */
    public function fail(
        Playlist $playlist,
        string $errors,
        ?string $broadcastBody = null,
        ?Throwable $exception = null,
        int $progress = 100,
        ?int $vodProgress = null,
        ?int $seriesProgress = null,
        ?int $channels = null,
        bool $clearSeriesProcessing = false,
        bool $tryRetry503 = false,
    ): void {
        $name = $playlist->name;
        $broadcastBody ??= 'Please view your notifications for details.';

        // Log with the exception message suffix when available; mirrors prior
        // "Error processing \"%s\": %s" / "Error processing \"%s\"" wording.
        $logSuffix = $exception !== null ? ': '.$exception->getMessage() : (
            $errors !== '' ? ': '.$errors : ''
        );
        logger()->error("Error processing \"{$name}\"{$logSuffix}");

        Notification::make()
            ->danger()
            ->title("Error processing \"{$name}\"")
            ->body($broadcastBody)
            ->broadcast($playlist->user);

        Notification::make()
            ->danger()
            ->title("Error processing \"{$name}\"")
            ->body($errors)
            ->sendToDatabase($playlist->user);

        $processing = [
            ...($playlist->fresh()->processing ?? []),
            'live_processing' => false,
            'vod_processing' => false,
        ];

        if ($clearSeriesProcessing) {
            $processing['series_processing'] = false;
        }

        $updates = [
            'status' => Status::Failed,
            'synced' => now(),
            'errors' => $errors,
            'progress' => $progress,
            'processing' => $processing,
        ];

        if ($vodProgress !== null) {
            $updates['vod_progress'] = $vodProgress;
        }
        if ($seriesProgress !== null) {
            $updates['series_progress'] = $seriesProgress;
        }
        if ($channels !== null) {
            $updates['channels'] = $channels;
        }

        $playlist->update($updates);

        if ($tryRetry503 && $exception !== null && Retry503Strategy::isHttp503($exception)) {
            Retry503Strategy::scheduleRetry($playlist);
        }

        // Idempotent within this sync window.
        $playlist->dispatchSyncCompletedOnce();
    }
}
