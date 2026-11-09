<?php

use App\Models\QueueMonitor;
use App\Providers\QueueMonitorServiceProvider;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;

function makeDatabaseJob(string $uuid, int $attempts): DatabaseJob
{
    $payload = json_encode(['uuid' => $uuid, 'attempts' => $attempts]);

    $dbRecord = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $payload,
        'attempts' => $attempts,
        'reserved_at' => time(),
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $job = (object) [
        'id' => $dbRecord,
        'queue' => 'default',
        'payload' => $payload,
        'attempts' => $attempts,
        'reserved_at' => time(),
        'available_at' => time(),
        'created_at' => time(),
    ];

    return new DatabaseJob(
        app(),
        app('queue')->connection('database'),
        $job,
        'database',
        'default'
    );
}

test('jobFinished only updates the still-running monitor for the current execution, not a finished historical row', function () {
    $uuid = 'shared-retry-uuid';
    $now = now();

    $oldFailedMonitor = QueueMonitor::create([
        'job_id' => $uuid,
        'name' => 'App\\Jobs\\AddItemsToCustomPlaylistChunk',
        'queue' => 'default',
        'started_at' => $now,
        'finished_at' => $now,
        'failed' => true,
        'attempt' => 1,
        'progress' => 100,
    ]);

    $newRunningMonitor = QueueMonitor::create([
        'job_id' => $uuid,
        'name' => 'App\\Jobs\\AddItemsToCustomPlaylistChunk',
        'queue' => 'default',
        'started_at' => $now,
        'finished_at' => null,
        'failed' => false,
        'attempt' => 1,
        'progress' => 0,
    ]);

    $job = makeDatabaseJob($uuid, 1);

    $reflection = new ReflectionClass(QueueMonitorServiceProvider::class);
    $method = $reflection->getMethod('jobFinished');
    $method->setAccessible(true);
    $method->invoke(null, $job, false, null);

    expect($oldFailedMonitor->refresh())
        ->failed->toBeTrue()
        ->progress->toBe(100)
        ->and($newRunningMonitor->refresh())
        ->failed->toBeFalse()
        ->finished_at->not->toBeNull()
        ->progress->toBe(100);
});
