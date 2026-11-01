<?php

use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;

it('clears the schedules-direct and plugin-invocations queues along with the existing ones', function () {
    foreach (['default', 'import', 'file_sync', 'schedules-direct', 'plugin-invocations'] as $queue) {
        // config/queue.php hardcodes 'redis' as the default, so earlier tests in
        // the same parallel worker (same key prefix) that dispatch real jobs, e.g.
        // ProcessEpgImport onto schedules-direct, can leave jobs behind here.
        Queue::connection('redis')->clear($queue);
        Queue::connection('redis')->push(CallQueuedClosure::create(fn () => null), '', $queue);
    }

    expect(Queue::connection('redis')->size('schedules-direct'))->toBe(1)
        ->and(Queue::connection('redis')->size('plugin-invocations'))->toBe(1);

    $this->artisan('app:restart-queue')->assertExitCode(0);

    expect(Queue::connection('redis')->size('default'))->toBe(0)
        ->and(Queue::connection('redis')->size('import'))->toBe(0)
        ->and(Queue::connection('redis')->size('file_sync'))->toBe(0)
        ->and(Queue::connection('redis')->size('schedules-direct'))->toBe(0)
        ->and(Queue::connection('redis')->size('plugin-invocations'))->toBe(0);
});
