<?php

namespace App\Filament\Resources\QueueMonitor;

use App\Filament\Resources\QueueMonitor\Pages\ListQueueMonitors;
use App\Filament\Resources\QueueMonitor\Widgets\QueueStatsOverview;
use App\Models\QueueMonitor;
use App\Tables\Columns\ProgressColumn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class QueueMonitorResource extends Resource
{
    protected static ?string $model = QueueMonitor::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Job Monitor';

    protected static ?string $modelLabel = 'Job';

    protected static ?string $pluralModelLabel = 'Job Monitor';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll()
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Filters')))
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->label(__('Status'))
                    ->color(fn (string $state): string => match ($state) {
                        'running' => 'primary',
                        'succeeded' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->sortable(false),

                TextColumn::make('name')
                    ->label(__('Job'))
                    ->formatStateUsing(fn (?string $state): string => $state ? Str::headline(class_basename($state)) : '—')
                    ->description(fn (QueueMonitor $record): ?string => $record->batch_name
                        ? __('Batch: :name', ['name' => $record->batch_name])
                        : null)
                    ->tooltip(fn (QueueMonitor $record): string => $record->name ?? '')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('queue')
                    ->label(__('Queue'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('attempt')
                    ->label(__('Attempt'))
                    ->alignCenter()
                    ->sortable(),

                ProgressColumn::make('progress')
                    ->label(__('Progress'))
                    ->color(fn (QueueMonitor $record): string => match ($record->status) {
                        'failed' => 'danger',
                        'succeeded' => 'success',
                        default => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('duration_ms')
                    ->label(__('Duration'))
                    ->formatStateUsing(function (?int $state): string {
                        if ($state === null) {
                            return '—';
                        }

                        if ($state < 1000) {
                            return "{$state}ms";
                        }

                        $seconds = intdiv($state, 1000);

                        if ($seconds < 60) {
                            return "{$seconds}s";
                        }

                        $minutes = intdiv($seconds, 60);
                        $remaining = $seconds % 60;

                        return $remaining > 0 ? "{$minutes}m {$remaining}s" : "{$minutes}m";
                    })
                    ->alignRight()
                    ->sortable(false),

                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->since()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('exception_message')
                    ->label(__('Error'))
                    ->limit(60)
                    ->tooltip(fn (QueueMonitor $record): ?string => $record->exception_message)
                    ->color(fn (QueueMonitor $record): ?string => $record->exception_message ? 'danger' : null)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->poll('10s')
            ->filters([
                SelectFilter::make('queue')
                    ->label(__('Queue'))
                    ->options(fn () => QueueMonitor::query()
                        ->whereNotNull('queue')
                        ->distinct()
                        ->pluck('queue', 'queue')
                        ->toArray()),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label(__('Retry'))
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->button()->hiddenLabel()->size('sm')
                    ->visible(fn (QueueMonitor $record): bool => $record->failed)
                    ->action(function (QueueMonitor $record): void {
                        Artisan::call('queue:retry', ['id' => [$record->job_id]]);

                        Notification::make()
                            ->title(__('Job queued for retry'))
                            ->success()
                            ->send();
                    }),

                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->button()->hiddenLabel()->size('sm')
                    ->action(fn (QueueMonitor $record) => $record->delete()),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retry_failed')
                        ->label(__('Retry Failed'))
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->action(function (Collection $records): void {
                            $ids = $records->where('failed', true)->pluck('job_id')->all();

                            if (! empty($ids)) {
                                Artisan::call('queue:retry', ['id' => $ids]);
                            }

                            Notification::make()
                                ->title(__('Failed jobs queued for retry'))
                                ->success()
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No jobs recorded yet'))
            ->emptyStateDescription(__('Jobs will appear here as they run.'))
            ->emptyStateIcon('heroicon-o-queue-list');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQueueMonitors::route('/'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            QueueStatsOverview::class,
        ];
    }
}
