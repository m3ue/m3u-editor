<?php

namespace App\Filament\Resources\QueueMonitor\Widgets;

use App\Models\QueueMonitor;
use App\Services\QueueIndicatorService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class ActiveBatchesWidget extends Widget
{
    protected string $view = 'filament.resources.queue-monitor.widgets.active-batches';

    protected ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        try {
            $snapshot = app(QueueIndicatorService::class)->getSnapshot(20);

            return [
                'batches' => $snapshot['batches'] ?? [],
                'running_jobs' => $snapshot['running_jobs'] ?? [],
                'degraded' => $snapshot['degraded'] ?? false,
            ];
        } catch (Throwable) {
            return ['batches' => [], 'running_jobs' => [], 'degraded' => true];
        }
    }

    public function retryBatch(string $batchId): void
    {
        try {
            Artisan::call('queue:retry-batch', ['id' => [$batchId]]);

            // Clear failed status on monitor records so the batch stops showing as "failing"
            QueueMonitor::where('batch_id', $batchId)
                ->where('failed', true)
                ->update(['failed' => false, 'finished_at' => null, 'exception_message' => null]);

            // Reset the batch's failed_jobs counter so the live queue reflects the retry immediately
            DB::table('job_batches')
                ->where('id', $batchId)
                ->update(['failed_jobs' => 0]);

            Notification::make()
                ->title(__('Batch jobs queued for retry'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Could not retry batch'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function cancelBatch(string $batchId): void
    {
        try {
            $batch = Bus::findBatch($batchId);

            if ($batch && ! $batch->cancelled()) {
                $batch->cancel();
            }

            Notification::make()
                ->title(__('Batch cancelled'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Could not cancel batch'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function dismissBatch(string $batchId): void
    {
        try {
            QueueMonitor::where('batch_id', $batchId)->delete();

            $batch = Bus::findBatch($batchId);
            if ($batch) {
                $batch->cancel();
            }

            Notification::make()
                ->title(__('Batch dismissed'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Could not dismiss batch'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
