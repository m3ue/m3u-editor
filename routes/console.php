<?php

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// Determine HLS cleanup interval from settings (defaults to 5 minutes)
$hlsCleanupInterval = 5;
try {
    $hlsCleanupInterval = app(GeneralSettings::class)->broadcast_segment_cleanup_interval ?? 5;
} catch (\Throwable $e) {
    // Settings unavailable, use default
}

// Check for updates
Schedule::command('app:update-check')
    ->daily();

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

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
    ->everyMinute()
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

// Reconcile profile connection counts
Schedule::command('profiles:reconcile')
    ->everyMinute()
    ->withoutOverlapping();

// Refresh provider profile info (every 15 minutes)
Schedule::job(new \App\Jobs\RefreshPlaylistProfiles)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Regenerate network schedules (hourly check, regenerates when needed)
Schedule::command('networks:regenerate-schedules')
    ->hourly()
    ->withoutOverlapping();

// Cleanup old HLS segments from network broadcasts
Schedule::command('network:cleanup-segments')
    ->cron("*/{$hlsCleanupInterval} * * * *")
    ->withoutOverlapping();
