<?php

namespace App\Jobs;

use App\Models\ArrIntegration;
use App\Models\User;
use App\Services\Arr\SonarrService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RequestArrEpisode implements ShouldQueue
{
    use Queueable;

    /**
     * Retry up to 8 times with 5-second gaps (~40s total), giving Sonarr
     * enough time to finish indexing a freshly-added series.
     */
    public int $tries = 8;

    public int $backoff = 5;

    public function __construct(
        public int $integrationId,
        public int $sonarrSeriesId,
        public int $seasonNumber,
        public int $episodeNumber,
        public int $userId,
        public string $showTitle,
    ) {}

    public function handle(): void
    {
        $integration = ArrIntegration::find($this->integrationId);
        $user = User::find($this->userId);

        if (! $integration || ! $user) {
            return;
        }

        /** @var SonarrService $service */
        $service = new SonarrService($integration);
        $result = $service->monitorAndSearchEpisode($this->sonarrSeriesId, $this->seasonNumber, $this->episodeNumber);

        if (! $result['ok']) {
            // Episode not yet indexed by Sonarr — throw to trigger a retry.
            throw new \RuntimeException($result['error'] ?? 'Episode not yet indexed.');
        }

        $label = $this->episodeLabel();

        Notification::make()
            ->success()
            ->title(__('Episode Queued for Download'))
            ->body(__(':episode of :title has been queued for download.', [
                'episode' => $label,
                'title' => $this->showTitle,
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(\Throwable $e): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('Episode Request Failed'))
            ->body(__('Could not queue :episode of :title: :error', [
                'episode' => $this->episodeLabel(),
                'title' => $this->showTitle,
                'error' => $e->getMessage(),
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    private function episodeLabel(): string
    {
        return 'S'.str_pad((string) $this->seasonNumber, 2, '0', STR_PAD_LEFT)
            .'E'.str_pad((string) $this->episodeNumber, 2, '0', STR_PAD_LEFT);
    }
}
