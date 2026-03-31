<?php

namespace App\Filament\Resources\Plugins\RelationManagers;

use App\Filament\Resources\Plugins\PluginResource;
use App\Models\PluginRun;
use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Run History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Run History'))
            ->description(__('Manual actions, hook-triggered automation, and scheduled jobs. Open a run to inspect payload, metrics, and live activity.'))
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Refine runs')))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->poll('3s')
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn ($record): string => PluginResource::getUrl('run', [
                'record' => $this->getOwnerRecord(),
                'run' => $record,
            ]))
            ->emptyStateHeading(__('No run history yet'))
            ->emptyStateDescription(__('Queue a plugin action from the page header to create the first run.'))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('run_reference')
                            ->label(__('Run'))
                            ->state(fn (PluginRun $record): string => self::runLabel($record))
                            ->weight('medium')
                            ->wrap()
                            ->searchable(query: function (Builder $query, string $search): Builder {
                                return $query->where(function (Builder $runQuery) use ($search): void {
                                    $runQuery
                                        ->where('action', 'like', "%{$search}%")
                                        ->orWhere('hook', 'like', "%{$search}%")
                                        ->orWhere('summary', 'like', "%{$search}%");
                                });
                            }),
                        TextColumn::make('summary')
                            ->label(__('Summary'))
                            ->placeholder(__('This run has not written a summary yet.'))
                            ->wrap(),
                        TextColumn::make('created_at')
                            ->label(__('Queued'))
                            ->since()
                            ->color('gray')
                            ->tooltip(fn (PluginRun $record): ?string => $record->created_at ? app(DateFormatService::class)->format($record->created_at) : null),
                    ]),
                    Stack::make([
                        TextColumn::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state))
                            ->color(fn (string $state) => match ($state) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'running' => 'warning',
                                'stale' => 'warning',
                                'cancelled' => 'gray',
                                default => 'gray',
                            }),
                        TextColumn::make('trigger')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                        TextColumn::make('dry_run')
                            ->badge()
                            ->label(__('Mode'))
                            ->state(fn (PluginRun $record): string => $record->dry_run ? 'Dry Run' : 'Apply')
                            ->color(fn (PluginRun $record): string => $record->dry_run ? 'gray' : 'primary'),
                    ])->grow(false),
                ])->from('lg'),
                Panel::make([
                    Split::make([
                        Stack::make([
                            TextColumn::make('scope')
                                ->label(__('Target Scope'))
                                ->state(fn (PluginRun $record): string => self::targetScope($record))
                                ->wrap(),
                            TextColumn::make('timing')
                                ->label(__('Timing'))
                                ->state(fn (PluginRun $record): string => self::timingSummary($record))
                                ->wrap(),
                        ]),
                        Stack::make([
                            TextColumn::make('invocation_type')
                                ->label(__('Invocation'))
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                            TextColumn::make('metrics')
                                ->label(__('Returned Metrics'))
                                ->state(fn (PluginRun $record): ?string => self::metricsSummary($record))
                                ->placeholder(__('No aggregate totals were returned by this run.'))
                                ->wrap(),
                        ])->grow(false),
                    ])->from('md'),
                ])->collapsible()->collapsed(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'stale' => 'Stale',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('trigger')
                    ->options([
                        'manual' => 'Manual',
                        'hook' => 'Hook',
                        'schedule' => 'Schedule',
                    ]),
                SelectFilter::make('invocation_type')
                    ->label(__('Invocation'))
                    ->options([
                        'action' => 'Action',
                        'hook' => 'Hook',
                    ]),
            ])
            ->headerActions([
                Action::make('clearHistory')
                    ->label(__('Clear History'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear run history'))
                    ->modalDescription(__('This permanently deletes all completed, failed, cancelled, and stale runs for this plugin. Any currently running jobs are not affected.'))
                    ->modalSubmitActionLabel(__('Clear history'))
                    ->action(function (): void {
                        $plugin = $this->getOwnerRecord();
                        PluginRun::query()
                            ->where('extension_plugin_id', $plugin->getKey())
                            ->whereNotIn('status', ['running'])
                            ->delete();
                    }),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->disabled(fn (PluginRun $record): bool => $record->status === 'running')
                    ->action(fn (PluginRun $record) => $record->delete()),
                Action::make('open')
                    ->label(__('Open run'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record): string => PluginResource::getUrl('run', [
                        'record' => $this->getOwnerRecord(),
                        'run' => $record,
                    ])),
            ])
            ->bulkActions([
                BulkAction::make('delete')
                    ->label(__('Delete selected'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records->each->delete()),
            ]);
    }

    public function getTabs(): array
    {
        $pluginId = $this->getOwnerRecord()->getKey();

        $allCount = PluginRun::query()
            ->where('extension_plugin_id', $pluginId)
            ->visibleTo(auth()->user())
            ->count();
        $runningCount = PluginRun::query()
            ->where('extension_plugin_id', $pluginId)
            ->visibleTo(auth()->user())
            ->where('status', 'running')
            ->count();
        $failedCount = PluginRun::query()
            ->where('extension_plugin_id', $pluginId)
            ->visibleTo(auth()->user())
            ->whereIn('status', ['failed', 'stale', 'cancelled'])
            ->count();
        $manualCount = PluginRun::query()
            ->where('extension_plugin_id', $pluginId)
            ->visibleTo(auth()->user())
            ->where('trigger', 'manual')
            ->count();
        $hookCount = PluginRun::query()
            ->where('extension_plugin_id', $pluginId)
            ->visibleTo(auth()->user())
            ->where('trigger', 'hook')
            ->count();
        $scheduledCount = PluginRun::query()
            ->where('extension_plugin_id', $pluginId)
            ->visibleTo(auth()->user())
            ->where('trigger', 'schedule')
            ->count();

        return [
            'all' => Tab::make(__('All Runs'))
                ->badge($allCount),
            'running' => Tab::make(__('Running'))
                ->badge($runningCount)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'running')),
            'failed' => Tab::make(__('Failed'))
                ->badge($failedCount)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'failed')),
            'manual' => Tab::make(__('Manual'))
                ->badge($manualCount)
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('trigger', 'manual')),
            'hooks' => Tab::make(__('Hooks'))
                ->badge($hookCount)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('trigger', 'hook')),
            'scheduled' => Tab::make(__('Scheduled'))
                ->badge($scheduledCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('trigger', 'schedule')),
        ];
    }

    protected static function runLabel(PluginRun $record): string
    {
        $name = $record->action ?: $record->hook ?: 'run';

        return Str::headline($name).' #'.$record->getKey();
    }

    protected static function targetScope(PluginRun $record): string
    {
        $payload = $record->payload ?? [];
        $parts = [];

        if ($playlistId = Arr::get($payload, 'playlist_id')) {
            $parts[] = 'Playlist #'.$playlistId;
        }

        if ($epgId = Arr::get($payload, 'epg_id')) {
            $parts[] = 'EPG #'.$epgId;
        }

        if ($channelId = Arr::get($payload, 'channel_id')) {
            $parts[] = 'Channel #'.$channelId;
        }

        if ($parts === []) {
            $parts[] = 'This run did not declare a specific playlist, EPG, or channel target.';
        }

        return implode(' • ', $parts);
    }

    protected static function timingSummary(PluginRun $record): string
    {
        $started = $record->started_at?->toDateTimeString() ?? 'Not started yet';
        $finished = $record->finished_at?->toDateTimeString() ?? 'Still running';

        return "Started: {$started}\nFinished: {$finished}";
    }

    protected static function metricsSummary(PluginRun $record): ?string
    {
        $totals = collect(data_get($record->result, 'data.totals', []))
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value, $key) => Str::headline((string) $key).': '.$value)
            ->values();

        if ($totals->isEmpty()) {
            return null;
        }

        return $totals->implode("\n");
    }
}
