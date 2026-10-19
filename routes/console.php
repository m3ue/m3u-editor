<?php

use App\Jobs\DvrRetentionCleanup;
use App\Jobs\SweepArrCacheFailback;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// The frequent checks below run inside the schedule:run process rather than each
// booting its own `php artisan` process. Most runs find nothing to do in a few
// milliseconds, so the boot was nearly all of their CPU. The lock expires after
// 15 minutes rather than a day: a fatal error now ends the whole schedule:run
// process, which then can't release the lock itself.
$inProcess = fn (string $command): CallbackEvent => Schedule::call(fn (): bool => Artisan::call($command) === Command::SUCCESS)
    ->name($command)
    ->withoutOverlapping(15);

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

// Check for updates
Schedule::command('app:update-check')
    ->hourly();

// Refresh playlists
$inProcess('app:refresh-playlist')
    ->everyMinute();

// Refresh TMDB dynamic groups (trending/popular/etc.) independent of playlist syncs
Schedule::command('app:refresh-dynamic-groups')
    ->dailyAt('04:15')
    ->withoutOverlapping();

// Refresh media server integrations
$inProcess('app:refresh-media-server-integrations')
    ->everyMinute();

// AIOStreams-backed VOD/episodes that have never resolved a stream yet
// (unaired-at-add-time episodes now past their air date, or entries whose
// initial resolution attempts all came up empty): resolve a small capped
// batch. Deliberately never touches already-'resolved' entries — re-checking
// a working link is exactly the "poking" pattern some debrid backends
// (TorBox) ban accounts for. Ordinary link rot on resolved content is
// handled on-demand instead, via M3uProxyService::resolveFailoverUrl's
// exhaustion hook at actual playback-failure time.
Schedule::command('app:resolve-pending-aiostreams-candidates')
    ->hourly()
    ->withoutOverlapping();

// Refresh EPG
$inProcess('app:refresh-epg')
    ->everyMinute();

// EPG cache health
$inProcess('app:epg-cache-health-check')
    ->everyFifteenMinutes();

// Check backup
$inProcess('app:run-scheduled-backups')
    ->everyTwoMinutes();

// Cleanup logos
Schedule::command('app:logo-cleanup --force')
    ->daily()
    ->withoutOverlapping();

// Prune failed jobs
Schedule::command('queue:prune-failed --hours=48')
    ->daily();

// Cached content cleanup: remove files whose channel/episode left its playlist
// (playlists in "automatic" retention mode), then rows that never got a file.
Schedule::command('cache:cleanup')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('cache:cleanup-orphans')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Arr cache failback: hand arr-sourced cache requests the arr can't deliver
// (failed download, or not downloaded or downloading within 24h) to the provider.
Schedule::job(new SweepArrCacheFailback)
    ->everyTenMinutes()
    ->withoutOverlapping();

// Prune old notifications
Schedule::command('app:prune-old-notifications --days=7')
    ->daily();

// Prune old model history (also handles DeviceAuthorization via its Prunable trait)
Schedule::command('model:prune')->daily();

// Ensure m3u-proxy webhook is registered (handles proxy restarts, delayed startup, etc.)
$inProcess('m3u-proxy:register-webhook')
    ->everyFiveMinutes();

// Refresh provider profile info (every 15 minutes)
$inProcess('app:refresh-playlist-profiles')
    ->everyFifteenMinutes();

// Run scheduled plugin invocations. Kept as its own process: it loads plugin code to
// ask each plugin what's due, and a plugin that exits or runs out of memory must not
// take down schedule:run and skip the checks after it (e.g. the DVR scheduler tick).
Schedule::command('plugins:run-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

// Mark abandoned plugin runs stale so operators can resume them.
$inProcess('plugins:recover-stale-runs')
    ->everyMinute();

// Check for plugin updates from GitHub repositories
if (config('plugins.update_check.enabled', true)) {
    $updateFrequencyHours = max(1, (int) config('plugins.update_check.frequency_hours', 4));
    Schedule::command('plugins:check-updates')
        ->cron("0 */{$updateFrequencyHours} * * *")
        ->withoutOverlapping();
}

// Note: HLS broadcast files are managed by m3u-proxy service
if (config('proxy.proxy_integration_enabled', true)) {
    // Regenerate network schedules (hourly check, regenerates when needed)
    Schedule::command('networks:regenerate-schedules')
        ->hourly()
        ->withoutOverlapping();

    if (config('dvr.dvr_enabled', true)) {
        // DVR scheduler tick — every minute, trigger and stop scheduled recordings.
        // Runs in the scheduler (like app:refresh-playlist/app:refresh-epg) rather than
        // as a queued job, so an idle tick doesn't show up in Horizon/queue monitoring.
        $inProcess('app:dvr-scheduler-tick')->everyMinute();

        // DVR retention cleanup — run hourly to enforce keepLast, age, and quota policies
        Schedule::job(new DvrRetentionCleanup)->hourly()->withoutOverlapping();
    }
}
