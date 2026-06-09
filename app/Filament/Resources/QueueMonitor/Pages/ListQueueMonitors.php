<?php

namespace App\Filament\Resources\QueueMonitor\Pages;

use App\Filament\Resources\QueueMonitor\QueueMonitorResource;
use App\Filament\Resources\QueueMonitor\Widgets\ActiveBatchesWidget;
use App\Filament\Resources\QueueMonitor\Widgets\QueueStatsOverview;
use App\Jobs\RestartQueue;
use App\Models\QueueMonitor;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class ListQueueMonitors extends ListRecords
{
    protected static string $resource = QueueMonitorResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            QueueStatsOverview::class,
            ActiveBatchesWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('clear_history')
                    ->label(__('Clear History'))
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear Job History'))
                    ->modalDescription(__('This will permanently delete all recorded job history. Running jobs will not be affected.'))
                    ->modalSubmitActionLabel(__('Clear history'))
                    ->action(function (): void {
                        QueueMonitor::query()->whereNotNull('finished_at')->delete();

                        Notification::make()
                            ->title(__('Job history cleared'))
                            ->success()
                            ->send();
                    }),

                Action::make('reset_queue')
                    ->label(__('Reset Queue'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Reset Queue'))
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription(__('Resetting the queue will restart the queue workers and flush any pending jobs. Any syncs or background processes will be stopped and removed. Only perform this action if you are having sync issues.'))
                    ->modalSubmitActionLabel(__('I understand, reset now'))
                    ->action(function (Dispatcher $dispatcher): void {
                        $dispatcher->dispatch(new RestartQueue);

                        Notification::make()
                            ->title(__('Queue reset'))
                            ->body(__('The queue workers have been restarted and any pending jobs flushed.'))
                            ->success()
                            ->duration(10000)
                            ->send();
                    }),
            ])->button()->color('gray')->label(__('Actions')),
        ];
    }

    public function getTabs(): array
    {
        $base = QueueMonitor::query();
        $total = $base->count();
        $running = (clone $base)->whereNull('finished_at')->where('failed', false)->count();
        $succeeded = (clone $base)->whereNotNull('finished_at')->where('failed', false)->count();
        $failed = (clone $base)->where('failed', true)->count();

        return [
            'all' => Tab::make(__('All'))
                ->badge($total),

            'running' => Tab::make(__('Running'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('finished_at')->where('failed', false))
                ->badge($running)
                ->badgeColor('primary'),

            'succeeded' => Tab::make(__('Succeeded'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('finished_at')->where('failed', false))
                ->badge($succeeded)
                ->badgeColor('success'),

            'failed' => Tab::make(__('Failed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('failed', true))
                ->badge($failed)
                ->badgeColor('danger'),
        ];
    }
}
