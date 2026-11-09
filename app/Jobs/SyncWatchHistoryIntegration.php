<?php

namespace App\Jobs;

use App\Models\PlaylistAuth;
use App\Models\WatchHistoryIntegration;
use App\Services\WatchHistory\WatchHistorySyncService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs an on-demand import / export between one Playlist Auth's watch
 * progress and a Trakt / SIMKL account (see WatchHistorySyncService), then
 * notifies the owner with what changed.
 */
class SyncWatchHistoryIntegration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 1800;

    public int $uniqueFor = 1800;

    /**
     * @param  'import'|'replace'|'export'|'sync'  $mode
     */
    public function __construct(
        public int $integrationId,
        public int $playlistAuthId,
        public string $mode,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->integrationId}:{$this->playlistAuthId}";
    }

    public function handle(WatchHistorySyncService $service): void
    {
        $integration = WatchHistoryIntegration::with('user')->find($this->integrationId);
        $playlistAuth = PlaylistAuth::find($this->playlistAuthId);

        if (! $integration || ! $playlistAuth || ! $integration->user) {
            return;
        }

        $label = $integration->provider->getLabel();

        try {
            $stats = $service->run($integration, $playlistAuth, $this->mode);
        } catch (Throwable $exception) {
            $integration->forceFill(['last_error' => $exception->getMessage()])->save();

            Notification::make()
                ->danger()
                ->title(__(':provider sync failed for :auth', ['provider' => $label, 'auth' => $playlistAuth->name]))
                ->body($exception->getMessage())
                ->sendToDatabase($integration->user)
                ->broadcast($integration->user);

            return;
        }

        $parts = array_filter([
            $stats['imported'] ? __(':count added', ['count' => $stats['imported']]) : null,
            $stats['updated'] ? __(':count updated', ['count' => $stats['updated']]) : null,
            $stats['cleared'] ? __(':count local entries cleared', ['count' => $stats['cleared']]) : null,
            $stats['exported'] ? __(':count sent to :provider', ['count' => $stats['exported'], 'provider' => $label]) : null,
            $stats['unchanged'] ? __(':count already up to date', ['count' => $stats['unchanged']]) : null,
            $stats['unmatched'] ? __(':count not found in the library', ['count' => $stats['unmatched']]) : null,
        ]);

        Notification::make()
            ->success()
            ->title(__(':provider sync finished for :auth', ['provider' => $label, 'auth' => $playlistAuth->name]))
            ->body($parts === [] ? __('Nothing to change.') : implode(', ', $parts).'.')
            ->sendToDatabase($integration->user)
            ->broadcast($integration->user);
    }
}
