<?php

namespace App\Jobs;

use App\Services\CachedContentRetentionService;
use App\Settings\GeneralSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReleaseWatchedManagedCache implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(CachedContentRetentionService $retention, ?GeneralSettings $settings = null): void
    {
        $settings ??= app(GeneralSettings::class);
        $settings->refresh();

        if (! (bool) ($settings->enable_cache ?? false)) {
            return;
        }

        $retention->releaseManagedCacheFiles();
    }
}
