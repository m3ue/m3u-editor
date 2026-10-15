<?php

namespace App\Jobs;

use App\Services\ArrCacheFailbackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sweep arr-sourced cache requests (source='arr') and hand the ones the
 * arr can't deliver to the provider. All logic lives in
 * ArrCacheFailbackService so it can be tested without the queue.
 */
class SweepArrCacheFailback implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 600;

    public function handle(ArrCacheFailbackService $service): void
    {
        $service->sweep();
    }
}
