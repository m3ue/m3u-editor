<?php

use App\Jobs\DvrRetentionCleanup;
use App\Jobs\DvrSchedulerTick;
use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

// Check for updates
Schedule::command('app:update-check')
    ->hourly();

// Refresh playlists
Schedule::command('app:refresh-playlist')
    ->everyMinute()
    ->withoutOverlapping();

// Refresh media server integrations
Schedule::command('app:refresh-media-server-integrations')
    ->everyMinute()
    ->withoutOverlapping();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyMinute()
    ->withoutOverlapping();

// EPG cache health
Schedule::command('app:epg-cache-health-check')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Check backup
Schedule::command('app:run-scheduled-backups')
    ->everyTwoMinutes()
    ->withoutOverlapping();

// Cleanup logos
Schedule::command('app:logo-cleanup --force')
    ->daily()
    ->withoutOverlapping();

// Prune failed jobs
Schedule::command('queue:prune-failed --hours=48')
    ->daily();

// Prune old notifications
Schedule::command('app:prune-old-notifications --days=7')
    ->daily();

// Prune old model history
Schedule::command('model:prune')->daily();

// Ensure m3u-proxy webhook is registered (handles proxy restarts, delayed startup, etc.)
Schedule::command('m3u-proxy:register-webhook')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Refresh provider profile info (every 15 minutes)
Schedule::command('app:refresh-playlist-profiles')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Run scheduled plugin invocations
Schedule::command('plugins:run-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

// Mark abandoned plugin runs stale so operators can resume them.
Schedule::command('plugins:recover-stale-runs --minutes=15')
    ->everyMinute()
    ->withoutOverlapping();

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
        // DVR scheduler tick — every minute, trigger and stop scheduled recordings
        Schedule::job(new DvrSchedulerTick)->everyMinute()->withoutOverlapping();

        // DVR retention cleanup — run hourly to enforce keepLast, age, and quota policies
        Schedule::job(new DvrRetentionCleanup)->hourly()->withoutOverlapping();
    }
}
