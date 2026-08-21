<?php

namespace Tests\Support;

use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A batchable job that always throws, used to test Bus::batch() failure handling
 * (finally()/catch() callbacks) without depending on real chunk-job internals.
 */
class AlwaysFailsBatchJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function handle(): void
    {
        throw new Exception('Simulated chunk failure');
    }
}
