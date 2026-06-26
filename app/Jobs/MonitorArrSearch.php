<?php

namespace App\Jobs;

use App\Models\ArrIntegration;
use App\Models\User;
use App\Services\Arr\ArrService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MonitorArrSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $integrationId,
        public int $contentId,
        public string $contentTitle,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $integration = ArrIntegration::find($this->integrationId);
        $user = User::find($this->userId);

        if (! $integration || ! $user || ! $integration->isRadarr()) {
            return;
        }

        $service = ArrService::make($integration);
        $releases = $service->fetchReleases($this->contentId);

        $allRejected = empty($releases) || collect($releases)->every(fn ($r) => ! ($r['approved'] ?? false));

        if ($allRejected) {
            Notification::make()
                ->warning()
                ->title(__('No Approved Releases'))
                ->body(__('All releases found for ":title" are rejected by your quality profile or indexer settings. Try adjusting your settings in Radarr, or use Interactive Search to pick a release manually.', [
                    'title' => $this->contentTitle,
                ]))
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }

    public function failed(\Throwable $e): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('Search Check Failed'))
            ->body(__('Could not verify release availability for ":title". Check Radarr directly.', [
                'title' => $this->contentTitle,
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
