<?php

namespace App\Filament\Resources\Plugins\RelationManagers;

use App\Filament\Resources\Plugins\PluginResource;
use App\Models\PluginRunLog;
use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Live Activity';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Live Activity Feed'))
            ->description(__('Streaming notes from running and recent jobs. Open any run to inspect the payload, result snapshot, and full trail.'))
            ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('run', fn (Builder $runQuery) => $runQuery->visibleTo(auth()->user())))
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Refine feed')))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->poll('2s')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('No live activity yet'))
            ->emptyStateDescription(__('Run a plugin action to see step-by-step activity appear here.'))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('message')
                            ->label(__('Activity'))
                            ->weight('medium')
                            ->wrap()
                            ->searchable(),
                        TextColumn::make('created_at')
                            ->label(__('Seen'))
                            ->since()
                            ->color('gray')
                            ->tooltip(fn (PluginRunLog $record): ?string => $record->created_at ? app(DateFormatService::class)->format($record->created_at) : null),
                    ]),
                    Stack::make([
                        TextColumn::make('level')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state))
                            ->color(fn (string $state) => match ($state) {
                                'error' => 'danger',
                                'warning' => 'warning',
                                default => 'info',
                            }),
                        TextColumn::make('run_reference')
                            ->label(__('Run'))
                            ->badge()
                            ->state(fn (PluginRunLog $record): string => self::runLabel($record))
                            ->color(fn (PluginRunLog $record): string => match ($record->run?->status) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'running' => 'warning',
                                'stale' => 'warning',
                                'cancelled' => 'gray',
                                default => 'gray',
                            })
                            ->url(fn (PluginRunLog $record): ?string => $record->run
                                ? PluginResource::getUrl('run', [
                                    'record' => $this->getOwnerRecord(),
                                    'run' => $record->run,
                                ])
                                : null),
                    ])->grow(false),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('context_summary')
                            ->label(__('Structured Context'))
                            ->state(fn (PluginRunLog $record): ?string => self::contextSummary($record->context ?? []))
                            ->placeholder(__('No structured context was attached to this activity line.'))
                            ->wrap(),
                        TextColumn::make('run_summary')
                            ->label(__('Run Summary'))
                            ->state(fn (PluginRunLog $record): ?string => $record->run?->summary)
                            ->placeholder(__('This run has not written a summary yet.'))
                            ->wrap(),
                    ]),
                ])->collapsible()->collapsed(),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->options([
                        'info' => 'Info',
                        'warning' => 'Warning',
                        'error' => 'Error',
                    ]),
                SelectFilter::make('run_status')
                    ->label(__('Run status'))
                    ->options([
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        if (! $status) {
                            return $query;
                        }

                        return $query->whereHas('run', fn (Builder $runQuery) => $runQuery->where('status', $status));
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open run'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (PluginRunLog $record): ?string => $record->run
                        ? PluginResource::getUrl('run', [
                            'record' => $this->getOwnerRecord(),
                            'run' => $record->run,
                        ])
                        : null),
            ])
            ->toolbarActions([]);
    }

    public function getTabs(): array
    {
        $pluginId = $this->getOwnerRecord()->getKey();

        $allCount = PluginRunLog::query()
            ->whereHas('run', fn (Builder $query) => $query->where('extension_plugin_id', $pluginId)->visibleTo(auth()->user()))
            ->count();
        $runningCount = PluginRunLog::query()
            ->whereHas('run', fn (Builder $query) => $query->where('extension_plugin_id', $pluginId)->visibleTo(auth()->user())->where('status', 'running'))
            ->count();
        $warningCount = PluginRunLog::query()
            ->whereHas('run', fn (Builder $query) => $query->where('extension_plugin_id', $pluginId)->visibleTo(auth()->user()))
            ->where('level', 'warning')
            ->count();
        $errorCount = PluginRunLog::query()
            ->whereHas('run', fn (Builder $query) => $query->where('extension_plugin_id', $pluginId)->visibleTo(auth()->user()))
            ->where('level', 'error')
            ->count();

        return [
            'all' => Tab::make(__('All Activity'))
                ->badge($allCount),
            'running' => Tab::make(__('Running'))
                ->badge($runningCount)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('run', fn (Builder $runQuery) => $runQuery->where('status', 'running'))),
            'warnings' => Tab::make(__('Warnings'))
                ->badge($warningCount)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('level', 'warning')),
            'errors' => Tab::make(__('Errors'))
                ->badge($errorCount)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('level', 'error')),
        ];
    }

    protected static function runLabel(PluginRunLog $record): string
    {
        $name = $record->run?->action ?: $record->run?->hook ?: 'run';

        return Str::headline($name).' #'.$record->extension_plugin_run_id;
    }

    protected static function contextSummary(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        return collect($context)
            ->map(fn ($value, $key) => $key.': '.(is_scalar($value) || $value === null ? json_encode($value) : '[…]'))
            ->take(6)
            ->implode("\n");
    }
}
