<?php

namespace App\Filament\Resources\SyncRuns;

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\Pages\ViewSyncRun;
use App\Models\SyncRun;
use App\Sync\SyncPlanRegistry;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SyncRunResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = SyncRun::class;

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Sync Runs');
    }

    public static function getModelLabel(): string
    {
        return __('Sync Run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sync Runs');
    }

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Run'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('uuid')
                            ->label('UUID')
                            ->copyable()
                            ->formatStateUsing(fn (string $state): string => Str::limit($state, 8, '…')),
                        TextEntry::make('playlist.name')
                            ->label(__('Playlist'))
                            ->url(fn (SyncRun $record): ?string => $record->playlist
                                ? PlaylistResource::getUrl('view', ['record' => $record->playlist])
                                : null),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (SyncRunStatus $state): string => match ($state) {
                                SyncRunStatus::Pending => 'gray',
                                SyncRunStatus::Running => 'info',
                                SyncRunStatus::Completed => 'success',
                                SyncRunStatus::Failed => 'danger',
                                SyncRunStatus::Cancelled => 'warning',
                            }),
                        TextEntry::make('kind'),
                        TextEntry::make('trigger'),
                        TextEntry::make('duration')
                            ->label(__('Duration'))
                            ->state(fn (SyncRun $record): string => self::formatDuration($record)),
                        TextEntry::make('started_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('finished_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),

                Section::make(__('Pipeline'))
                    ->collapsible()
                    ->schema([
                        ViewComponent::make('filament.resources.sync-run-resource.phase-diagram'),
                    ]),

                Section::make(__('Phases'))
                    ->schema([
                        ViewComponent::make('filament.resources.sync-run-resource.phase-timeline'),
                    ]),

                Section::make(__('Errors'))
                    ->visible(fn (SyncRun $record): bool => ! empty($record->errors))
                    ->collapsed()
                    ->schema([
                        ViewComponent::make('filament.resources.sync-run-resource.errors'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 8, '…'))
                    ->copyable()
                    ->copyableState(fn (string $state): string => $state)
                    ->toggleable(),
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->url(fn (SyncRun $record): ?string => $record->playlist
                        ? PlaylistResource::getUrl('view', ['record' => $record->playlist])
                        : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('trigger')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SyncRunStatus $state): string => match ($state) {
                        SyncRunStatus::Pending => 'gray',
                        SyncRunStatus::Running => 'info',
                        SyncRunStatus::Completed => 'success',
                        SyncRunStatus::Failed => 'danger',
                        SyncRunStatus::Cancelled => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('phase_progress')
                    ->label(__('Phases'))
                    ->state(fn (SyncRun $record): string => self::formatPhaseProgress($record)),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('duration')
                    ->label(__('Duration'))
                    ->state(fn (SyncRun $record): string => self::formatDuration($record)),
            ])
            ->filters([
                SelectFilter::make('playlist_id')
                    ->label(__('Playlist'))
                    ->relationship('playlist', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(SyncRunStatus::class),
                SelectFilter::make('kind')
                    ->options([
                        'sync' => __('Pre-sync'),
                        'post_sync' => __('Post-sync'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        // Block bulk-deleting actively Running runs: in-flight
                        // phase jobs would have no ledger to write back to and
                        // would either crash on the missing FK or silently
                        // no-op. Pending and terminal runs are safe to delete
                        // (Pending may be orphaned by an orchestrator crash and
                        // needs to be cleanable from the UI).
                        ->before(function (DeleteBulkAction $action, Collection $records) {
                            if ($records->contains(fn (SyncRun $r) => $r->status === SyncRunStatus::Running)) {
                                Notification::make()
                                    ->title(__('Cannot delete running sync runs'))
                                    ->body(__('One or more selected runs are still Running. Wait for them to finish (or cancel them) before deleting.'))
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncRuns::route('/'),
            'view' => ViewSyncRun::route('/{record}'),
        ];
    }

    /**
     * Build the planned + recorded phase view used by the timeline. Each entry
     * merges the planned step (from {@see SyncPlanRegistry}) with whatever the
     * orchestrator has recorded on the run so far.
     *
     * @return array<int, array{slug: string, label: string, status: SyncPhaseStatus, required: bool, parallel_group: ?string, chain_group: ?string, started_at: ?Carbon, finished_at: ?Carbon, error: ?string, recorded: bool}>
     */
    public static function buildPhaseTimeline(SyncRun $run): array
    {
        $plan = SyncPlanRegistry::for($run);
        $planned = $plan?->stepSlugs() ?? [];

        $recorded = $run->phases ?? [];
        $seen = [];
        $rows = [];

        foreach ($planned as $step) {
            $seen[$step['slug']] = true;
            $rows[] = self::buildTimelineRow(
                slug: $step['slug'],
                required: $step['required'],
                parallelGroup: $step['parallel_group'],
                chainGroup: $step['chain_group'],
                recorded: $recorded[$step['slug']] ?? null,
            );
        }

        // Surface any recorded phases that weren't in the plan (defensive: a
        // future plan tweak shouldn't drop history off the timeline).
        foreach ($recorded as $slug => $data) {
            if (isset($seen[$slug])) {
                continue;
            }
            $rows[] = self::buildTimelineRow(
                slug: $slug,
                required: false,
                parallelGroup: null,
                chainGroup: null,
                recorded: $data,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $recorded
     * @return array{slug: string, label: string, status: SyncPhaseStatus, required: bool, parallel_group: ?string, chain_group: ?string, started_at: ?Carbon, finished_at: ?Carbon, error: ?string, recorded: bool}
     */
    private static function buildTimelineRow(
        string $slug,
        bool $required,
        ?string $parallelGroup,
        ?string $chainGroup,
        ?array $recorded,
    ): array {
        $status = isset($recorded['status'])
            ? SyncPhaseStatus::from($recorded['status'])
            : SyncPhaseStatus::Pending;

        return [
            'slug' => $slug,
            'label' => Str::headline($slug),
            'status' => $status,
            'required' => $required,
            'parallel_group' => $parallelGroup,
            'chain_group' => $chainGroup,
            'started_at' => isset($recorded['started_at']) ? Carbon::parse($recorded['started_at']) : null,
            'finished_at' => isset($recorded['finished_at']) ? Carbon::parse($recorded['finished_at']) : null,
            'error' => $recorded['error'] ?? null,
            'recorded' => $recorded !== null,
        ];
    }

    private static function formatDuration(SyncRun $run): string
    {
        if (! $run->started_at) {
            return '-';
        }

        $end = $run->finished_at ?? now();
        $seconds = $end->diffInSeconds($run->started_at);

        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
    }

    private static function formatPhaseProgress(SyncRun $run): string
    {
        $phases = $run->phases ?? [];
        if ($phases === []) {
            return '0 / 0';
        }

        $finished = 0;
        foreach ($phases as $data) {
            $status = $data['status'] ?? null;
            if (in_array($status, [
                SyncPhaseStatus::Completed->value,
                SyncPhaseStatus::Failed->value,
                SyncPhaseStatus::Skipped->value,
            ], strict: true)) {
                $finished++;
            }
        }

        return "{$finished} / ".count($phases);
    }

    /**
     * Render the planned + recorded phases as a Mermaid `flowchart LR` source
     * string. Sequential phases form a linear chain; parallel groups branch
     * out and merge back; chain groups render as inner sequences. Status
     * classes (pending/running/completed/failed/skipped) drive node colors.
     */
    public static function buildPhaseMermaid(SyncRun $run): string
    {
        $rows = self::buildPhaseTimeline($run);
        if ($rows === []) {
            return '';
        }

        $lines = ['flowchart LR'];
        $classes = [];
        $previousExits = ['_start']; // edges flow from these nodes into the next group
        $lines[] = '    _start([Start])';
        $classes['_start'] = 'plan_start';

        // Group consecutive rows by their parallel/chain group key.
        $groups = [];
        $currentKey = null;
        $currentGroup = [];
        foreach ($rows as $row) {
            $key = $row['parallel_group'] ?? ($row['chain_group'] !== null ? 'C:'.$row['chain_group'] : null);
            if ($key !== $currentKey) {
                if ($currentGroup !== []) {
                    $groups[] = ['key' => $currentKey, 'rows' => $currentGroup];
                }
                $currentGroup = [];
                $currentKey = $key;
            }
            $currentGroup[] = $row;
        }
        if ($currentGroup !== []) {
            $groups[] = ['key' => $currentKey, 'rows' => $currentGroup];
        }

        foreach ($groups as $group) {
            $key = $group['key'];
            $rowsInGroup = $group['rows'];
            $isParallel = $key !== null && ! str_starts_with((string) $key, 'C:');
            $isChain = $key !== null && str_starts_with((string) $key, 'C:');

            if ($isParallel) {
                $exits = [];
                foreach ($rowsInGroup as $row) {
                    $nodeId = self::mermaidNodeId($row['slug']);
                    $lines[] = '    '.$nodeId.'["'.self::mermaidEscape($row['label']).'"]';
                    $classes[$nodeId] = 'phase_'.$row['status']->value;
                    foreach ($previousExits as $entry) {
                        $lines[] = '    '.$entry.' --> '.$nodeId;
                    }
                    $exits[] = $nodeId;
                }
                $previousExits = $exits;
            } elseif ($isChain) {
                // Sequential nodes inside a chain block.
                foreach ($rowsInGroup as $row) {
                    $nodeId = self::mermaidNodeId($row['slug']);
                    $lines[] = '    '.$nodeId.'["'.self::mermaidEscape($row['label']).'"]';
                    $classes[$nodeId] = 'phase_'.$row['status']->value;
                    foreach ($previousExits as $entry) {
                        $lines[] = '    '.$entry.' --> '.$nodeId;
                    }
                    $previousExits = [$nodeId];
                }
            } else {
                // Single non-grouped phase.
                foreach ($rowsInGroup as $row) {
                    $nodeId = self::mermaidNodeId($row['slug']);
                    $lines[] = '    '.$nodeId.'["'.self::mermaidEscape($row['label']).'"]';
                    $classes[$nodeId] = 'phase_'.$row['status']->value;
                    foreach ($previousExits as $entry) {
                        $lines[] = '    '.$entry.' --> '.$nodeId;
                    }
                    $previousExits = [$nodeId];
                }
            }
        }

        $lines[] = '    _end([End])';
        $classes['_end'] = 'plan_end';
        foreach ($previousExits as $entry) {
            $lines[] = '    '.$entry.' --> _end';
        }

        // classDef style declarations — match the timeline color scheme.
        $lines[] = '    classDef phase_pending fill:#e5e7eb,stroke:#9ca3af,color:#374151';
        $lines[] = '    classDef phase_running fill:#3b82f6,stroke:#2563eb,color:#fff';
        $lines[] = '    classDef phase_completed fill:#10b981,stroke:#059669,color:#fff';
        $lines[] = '    classDef phase_failed fill:#ef4444,stroke:#dc2626,color:#fff';
        $lines[] = '    classDef phase_skipped fill:#9ca3af,stroke:#6b7280,color:#fff';
        $lines[] = '    classDef plan_start fill:#1f2937,stroke:#111827,color:#fff';
        $lines[] = '    classDef plan_end fill:#1f2937,stroke:#111827,color:#fff';

        // Apply classes (group node ids per class for compactness).
        $byClass = [];
        foreach ($classes as $node => $class) {
            $byClass[$class][] = $node;
        }
        foreach ($byClass as $class => $nodes) {
            $lines[] = '    class '.implode(',', $nodes).' '.$class;
        }

        return implode("\n", $lines);
    }

    private static function mermaidNodeId(string $slug): string
    {
        // Mermaid node ids must be alphanumeric/underscore; slugs already are.
        return 'n_'.preg_replace('/[^a-z0-9_]/i', '_', $slug);
    }

    private static function mermaidEscape(string $label): string
    {
        return str_replace(['"', "\n"], ['&quot;', ' '], $label);
    }
}
