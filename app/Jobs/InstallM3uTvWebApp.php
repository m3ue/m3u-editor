<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\M3uTvWebAppService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs (or updates to) the latest M3U TV web app build, then tells the
 * admin who started it how it went.
 */
class InstallM3uTvWebApp implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return 'install-m3u-tv-web';
    }

    public function handle(M3uTvWebAppService $webApp): void
    {
        $user = User::find($this->userId);

        try {
            $installed = $webApp->installLatest();
        } catch (Throwable $exception) {
            report($exception);
            $this->notify($user, Notification::make()
                ->danger()
                ->title(__('M3U TV web app install failed'))
                ->body($exception->getMessage()));

            return;
        }

        $this->notify($user, Notification::make()
            ->success()
            ->title(__('M3U TV web app installed'))
            ->body(__('Version :version is now served at /m3u-tv/.', ['version' => $installed['version']])));
    }

    private function notify(?User $user, Notification $notification): void
    {
        if ($user === null) {
            return;
        }

        $notification->broadcast($user);
        $notification->sendToDatabase($user);
    }
}
