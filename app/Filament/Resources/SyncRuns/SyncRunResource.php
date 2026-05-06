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
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
                    DeleteBulkAction::make(),
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
}
