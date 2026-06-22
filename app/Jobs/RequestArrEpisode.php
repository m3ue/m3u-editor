<?php

namespace App\Jobs;

use App\Models\ArrIntegration;
use App\Models\User;
use App\Services\Arr\ArrService;
use App\Services\Arr\SonarrService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RequestArrEpisode implements ShouldQueue
{
    use Queueable;

    /**
     * Retry up to 8 times with 5-second gaps (40 s total window).
     * Sonarr typically indexes episodes within a few seconds of a series being added.
     * Throwing from handle() triggers the automatic retry with backoff.
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
        $service = ArrService::make($integration);

        $result = $service->monitorAndSearchEpisode(
            $this->sonarrSeriesId,
            $this->seasonNumber,
            $this->episodeNumber,
        );

        if (! $result['ok']) {
            // Episode not yet indexed — throw to trigger a retry with backoff.
            throw new \RuntimeException($result['error'] ?? "Episode S{$this->seasonNumber}E{$this->episodeNumber} not yet indexed.");
        }

        Notification::make()
            ->success()
            ->title(__('Episode Queued'))
            ->body(__('":show" S:s E:e has been queued for download.', [
                'show' => $this->showTitle,
                's' => str_pad((string) $this->seasonNumber, 2, '0', STR_PAD_LEFT),
                'e' => str_pad((string) $this->episodeNumber, 2, '0', STR_PAD_LEFT),
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
            ->body(__('Could not queue ":show" S:s E:e after multiple attempts. Sonarr may still be indexing — try again in a moment.', [
                'show' => $this->showTitle,
                's' => str_pad((string) $this->seasonNumber, 2, '0', STR_PAD_LEFT),
                'e' => str_pad((string) $this->episodeNumber, 2, '0', STR_PAD_LEFT),
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
