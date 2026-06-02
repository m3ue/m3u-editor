<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Services\SyncPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CompleteSyncPhase implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(
        public int $syncRunId,
        public SyncRunPhase $phase,
    ) {}

    public function handle(SyncPipelineService $pipeline): void
    {
        $pipeline->completePhase($this->syncRunId, $this->phase);
    }
}
