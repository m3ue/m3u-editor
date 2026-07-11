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
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'failed' => false,
        'attempt' => 1,
    ]);

    $alreadyFailed = QueueMonitor::create([
        'job_id' => 'already-failed-job',
        'name' => 'App\\Jobs\\SyncMediaServer',
        'queue' => 'default',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'failed' => true,
        'exception_message' => 'Original failure',
        'attempt' => 1,
    ]);

    $this->artisan('app:heal-stale-queue-monitors')->assertExitCode(0);

    expect($succeeded->refresh()->exception_message)->toBeNull()
        ->and($alreadyFailed->refresh()->exception_message)->toBe('Original failure');
});
