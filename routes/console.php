<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// Check for updates
Schedule::command('app:update-check')
    ->daily();

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

// Refresh playlists
Schedule::command('app:refresh-playlist')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Prune stale processes
Schedule::command('app:hls-prune')
    ->everyFiveSeconds()
    ->withoutOverlapping();

// Shared stream management jobs
Schedule::job(new \App\Jobs\SharedStreamCleanup())
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('shared-stream-cleanup');
Schedule::job(new \App\Jobs\StreamBufferManager())
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('shared-stream-buffer-management');

// Shared stream maintenance
Schedule::command('app:shared-streams cleanup')
    ->everyFiveMinutes()
    ->withoutOverlapping();
Schedule::command('app:shared-streams sync')
    ->everyTenMinutes()
    ->withoutOverlapping();

// EPG cache health
Schedule::command('app:epg-cache-health-check')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
