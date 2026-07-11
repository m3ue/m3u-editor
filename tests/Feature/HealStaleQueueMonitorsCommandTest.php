<?php

use App\Models\QueueMonitor;

test('marks running queue monitor records as failed', function () {
    $stale = QueueMonitor::create([
        'job_id' => 'stale-job',
        'name' => 'App\\Jobs\\GenerateEpgCache',
        'queue' => 'default',
        'started_at' => now()->subDays(6),
        'attempt' => 1,
    ]);

    $this->artisan('app:heal-stale-queue-monitors')->assertExitCode(0);

    $stale->refresh();

    expect($stale->failed)->toBeTrue()
        ->and($stale->finished_at)->not->toBeNull()
        ->and($stale->exception_message)->toContain('Orphaned');
});

test('does not touch already finished or already failed records', function () {
    $succeeded = QueueMonitor::create([
        'job_id' => 'succeeded-job',
        'name' => 'App\\Jobs\\SyncMediaServer',
        'queue' => 'default',
        'started_at' => now()->subDays(6),
        'finished_at' => now(),
        'failed' => false,
        'attempt' => 1,
    ]);

    $alreadyFailed = QueueMonitor::create([
        'job_id' => 'already-failed-job',
        'name' => 'App\\Jobs\\SyncMediaServer',
        'queue' => 'default',
        'started_at' => now()->subDays(6),
        'finished_at' => now(),
        'failed' => true,
        'exception_message' => 'Original failure',
        'attempt' => 1,
    ]);

    $this->artisan('app:heal-stale-queue-monitors')->assertExitCode(0);

    expect($succeeded->refresh()->exception_message)->toBeNull()
        ->and($alreadyFailed->refresh()->exception_message)->toBe('Original failure');
});

test('does not mark recently started records as stale', function () {
    $recent = QueueMonitor::create([
        'job_id' => 'recent-job',
        'name' => 'App\\Jobs\\SyncMediaServer',
        'queue' => 'default',
        'started_at' => now()->subMinutes(5),
        'attempt' => 1,
    ]);

    $this->artisan('app:heal-stale-queue-monitors')->assertExitCode(0);

    $recent->refresh();

    expect($recent->failed)->toBeFalse()
        ->and($recent->finished_at)->toBeNull()
        ->and($recent->exception_message)->toBeNull();
});

test('--max-age option overrides the default threshold', function () {
    $record = QueueMonitor::create([
        'job_id' => 'thirty-min-job',
        'name' => 'App\\Jobs\\SyncMediaServer',
        'queue' => 'default',
        'started_at' => now()->subMinutes(30),
        'attempt' => 1,
    ]);

    // Default (~120 min from retry_after) leaves it alone.
    $this->artisan('app:heal-stale-queue-monitors')->assertExitCode(0);
    expect($record->refresh()->failed)->toBeFalse();

    // --max-age=15 should mark it as stale.
    $this->artisan('app:heal-stale-queue-monitors --max-age=15')->assertExitCode(0);

    $record->refresh();
    expect($record->failed)->toBeTrue()
        ->and($record->finished_at)->not->toBeNull();
});
