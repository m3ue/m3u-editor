<?php

namespace App\Sync\Importers\Support;

use App\Models\Playlist;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Dispatches an import job chain with the standard connection/queue wiring
 * and the shared failure handler. Centralizes the previously-duplicated
 * Bus::chain(...)->onConnection->onQueue->catch->dispatch() boilerplate that
 * lived at the bottom of every chain builder.
 */
final class ChainDispatcher
{
    public function __construct(
        private readonly ImportFailureHandler $failureHandler = new ImportFailureHandler,
    ) {}

    /**
     * @param  array<int, mixed>  $jobs  Chain of queueable jobs to run sequentially.
     */
    public function dispatch(array $jobs, Playlist $playlist): void
    {
        $failureHandler = $this->failureHandler;

        Bus::chain($jobs)
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($playlist, $failureHandler) {
                $failureHandler->fail(
                    playlist: $playlist,
                    errors: "Error processing \"{$playlist->name}\": {$e->getMessage()}",
                    exception: $e,
                    channels: null,
                    clearSeriesProcessing: true,
                    tryRetry503: true,
                );
            })->dispatch();
    }
}
