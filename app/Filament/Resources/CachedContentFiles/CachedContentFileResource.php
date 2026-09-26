<?php

namespace App\Filament\Resources\CachedContentFiles;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\CachedContentFiles\Pages\ListCachedContentFiles;
use App\Livewire\ArrQueueMonitor;
use App\Models\CachedContentFile;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use App\Tables\Columns\ProgressColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cached Downloads: every cached_content_files row the current user owns
 * (admins see all), with live progress, ETA, and retry / cancel / delete
 * actions. Read-only apart from those actions; rows are created by the
 * Cache Now actions. Only reachable while `enable_cache` is on.
 */
class CachedContentFileResource extends Resource
{
    protected static ?string $model = CachedContentFile::class;

    protected static ?string $slug = 'cached-downloads';

    protected static ?int $navigationSort = 90;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Cached Downloads');
    }

    public static function getModelLabel(): string
    {
        return __('Cached Download');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Cached Downloads');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && (bool) (app(GeneralSettings::class)->enable_cache ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->ownedBy((int) auth()->id());
        }

        return $query;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery();

        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->ownedBy((int) auth()->id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['playlist:id,name']))
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->defaultSort('updated_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100])
            // 5s while anything visible is in flight, otherwise 60s so newly
            // queued downloads still show up without a reload.
            ->poll(
                fn (): string => static::getEloquentQuery()
                    ->whereIn('status', [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading])
                    ->exists() ? '5s' : '60s',
            )
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->state(fn (CachedContentFile $record): string => self::getContentLabel($record))
                    ->searchable(['title', 'tmdb_id', 'tvdb_id'])
                    ->wrap()
                    ->limit(60),
                TextColumn::make('content_type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'movie' => __('VOD'),
                        'episode' => __('Episode'),
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'movie' => 'info',
                        'episode' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (CachedContentFileStatus $state): string => $state->getLabel())
                    ->color(fn (CachedContentFileStatus $state): string => $state->getColor())
                    ->icon(fn (CachedContentFileStatus $state): string => $state->getIcon())
                    ->tooltip(fn (CachedContentFile $record): ?string => $record->status === CachedContentFileStatus::Failed
                        ? $record->last_error_message
                        : null),
                ProgressColumn::make('progress')
                    ->label(__('Progress'))
                    ->progress(fn (CachedContentFile $record): int => self::getProgressPercent($record))
                    ->color(fn (CachedContentFile $record): string => self::getProgressColor($record)),
                TextColumn::make('size')
                    ->label(__('Size'))
                    ->placeholder('-')
                    ->getStateUsing(fn (CachedContentFile $record): ?string => self::getProgressLabel($record)),
                TextColumn::make('eta')
                    ->label(__('ETA'))
                    ->placeholder('-')
                    ->getStateUsing(fn (CachedContentFile $record): ?string => self::getEtaLabel($record)),
                TextColumn::make('failure_count')
                    ->label(__('Failures'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->label(__('Last Activity'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(CachedContentFileStatus::cases())
                        ->mapWithKeys(fn (CachedContentFileStatus $status): array => [$status->value => $status->getLabel()])
                        ->all()),
                SelectFilter::make('content_type')
                    ->label(__('Type'))
                    ->options([
                        'movie' => __('VOD'),
                        'episode' => __('Episode'),
                    ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('viewError')
                        ->label(__('View error'))
                        ->icon('heroicon-o-exclamation-triangle')
                        ->visible(fn (CachedContentFile $record): bool => $record->status === CachedContentFileStatus::Failed)
                        ->modalHeading(__('Download failure'))
                        ->schema([
                            TextEntry::make('last_failed_at')
                                ->label(__('Last failed'))
                                ->dateTime()
                                ->placeholder(__('never')),
                            TextEntry::make('failure_count')
                                ->label(__('Total failures')),
                            TextEntry::make('last_error_message')
                                ->label(__('Error message'))
                                ->placeholder(__('No error message recorded.'))
                                ->fontFamily('mono')
                                ->copyable(),
                        ])
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close')),

                    Action::make('retry')
                        ->label(__('Retry download'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->visible(fn (CachedContentFile $record): bool => $record->status === CachedContentFileStatus::Failed)
                        ->requiresConfirmation()
                        ->modalHeading(__('Retry this download?'))
                        ->modalDescription(__('Queue this download again.'))
                        ->modalSubmitActionLabel(__('Retry'))
                        ->action(function (CachedContentFile $record): void {
                            if (! self::retryCachedFile($record)) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Could not retry'))
                                    ->body(__('The source movie or episode no longer exists, or caching is disabled.'))
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Retry queued'))
                                ->send();
                        }),

                    Action::make('cancel')
                        ->label(__('Cancel download'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn (CachedContentFile $record): bool => in_array($record->status, [
                            CachedContentFileStatus::Pending,
                            CachedContentFileStatus::Downloading,
                        ], true))
                        ->requiresConfirmation()
                        ->modalHeading(__('Cancel this download?'))
                        ->modalDescription(__('Stops the transfer within a few seconds and removes the entry and any partial file.'))
                        ->modalSubmitActionLabel(__('Cancel download'))
                        ->action(function (CachedContentFile $record): void {
                            self::deleteCachedFile($record);

                            Notification::make()
                                ->success()
                                ->title(__('Download cancelled'))
                                ->send();
                        }),

                    Action::make('deleteCache')
                        ->label(__('Delete cache'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete this cached file?'))
                        ->modalDescription(__('Removes the cached file. Playback will use the provider again.'))
                        ->modalSubmitActionLabel(__('Delete'))
                        ->action(function (CachedContentFile $record): void {
                            self::deleteCachedFile($record);

                            Notification::make()
                                ->success()
                                ->title(__('Cached file deleted'))
                                ->send();
                        }),
                ])->button()->hiddenLabel()->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkRetry')
                        ->label(__('Retry selected'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading(__('Retry selected downloads?'))
                        ->modalDescription(__('Queue every failed download in the selection again. Other rows are skipped.'))
                        ->modalSubmitActionLabel(__('Retry selected'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $retried = 0;
                            $skipped = 0;
                            foreach (self::filterToOwnedRecords($records) as $record) {
                                self::retryCachedFile($record) ? $retried++ : $skipped++;
                            }

                            Notification::make()
                                ->success()
                                ->title($retried === 1 ? __('Retry queued for 1 row') : __('Retry queued for :count rows', ['count' => $retried]))
                                ->body($skipped > 0 ? __(':skipped row(s) skipped (not Failed, or no source resolvable).', ['skipped' => $skipped]) : null)
                                ->send();
                        }),

                    BulkAction::make('bulkCancel')
                        ->label(__('Cancel selected'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('Cancel selected downloads?'))
                        ->modalDescription(__('Stops every pending or downloading transfer in the selection and removes those entries. Other rows are skipped.'))
                        ->modalSubmitActionLabel(__('Cancel selected'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $cancelled = 0;
                            $skipped = 0;
                            foreach (self::filterToOwnedRecords($records) as $record) {
                                if (! in_array($record->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
                                    $skipped++;

                                    continue;
                                }
                                self::deleteCachedFile($record);
                                $cancelled++;
                            }

                            Notification::make()
                                ->success()
                                ->title($cancelled === 1 ? __('Cancelled 1 download') : __('Cancelled :count downloads', ['count' => $cancelled]))
                                ->body($skipped > 0 ? __(':skipped row(s) skipped (not in-flight).', ['skipped' => $skipped]) : null)
                                ->send();
                        }),

                    BulkAction::make('bulkDelete')
                        ->label(__('Delete selected'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete selected cached files?'))
                        ->modalDescription(__('Removes every selected entry and its file. Downloads still in progress are stopped.'))
                        ->modalSubmitActionLabel(__('Delete selected'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $count = 0;
                            foreach (self::filterToOwnedRecords($records) as $record) {
                                self::deleteCachedFile($record);
                                $count++;
                            }

                            Notification::make()
                                ->success()
                                ->title($count === 1 ? __('Deleted 1 cached file') : __('Deleted :count cached files', ['count' => $count]))
                                ->send();
                        }),
                ]),
            ])
            ->emptyStateHeading(__('No cache activity yet'))
            ->emptyStateDescription(__('Downloads appear here after you use "Cache Now" on a VOD or episode, or "Cache all episodes" on a series.'))
            ->emptyStateIcon('heroicon-o-circle-stack');
    }

    /**
     * Title cell: the title stored at dispatch time, or an identity label
     * when none was recorded.
     */
    public static function getContentLabel(CachedContentFile $record): string
    {
        if ($record->title !== null && $record->title !== '') {
            return $record->title;
        }

        $tmdb = $record->tmdb_id ?: '?';
        $season = $record->season_number !== null ? " S{$record->season_number}" : '';
        $episode = $record->episode_number !== null ? "E{$record->episode_number}" : '';

        return "{$record->content_type}: tmdb {$tmdb}{$season}{$episode}";
    }

    /**
     * Size cell: status label while pending, downloaded / expected bytes
     * while downloading, final size once completed, last-known bytes on
     * failure.
     */
    public static function getProgressLabel(CachedContentFile $record): ?string
    {
        $status = $record->status;

        if ($status === CachedContentFileStatus::Pending) {
            return $status->getLabel();
        }

        if ($status === CachedContentFileStatus::Downloading) {
            $downloaded = (int) ($record->bytes_downloaded ?? 0);
            if ($downloaded <= 0) {
                return null;
            }

            $current = ArrQueueMonitor::formatBytes($downloaded);
            $expected = $record->bytes_expected !== null ? (int) $record->bytes_expected : null;

            if ($expected === null || $expected <= 0) {
                return $current;
            }

            return sprintf('%s / %s', $current, ArrQueueMonitor::formatBytes($expected));
        }

        $size = $status === CachedContentFileStatus::Completed
            ? ($record->file_size_bytes ?? $record->bytes_downloaded)
            : $record->bytes_downloaded;

        return ($size !== null && $size > 0)
            ? ArrQueueMonitor::formatBytes((int) $size)
            : $status->getLabel();
    }

    /**
     * Percent complete for the progress bar: bytes downloaded vs expected
     * while in flight (0 when the provider sent no size), 100 once
     * Completed, and the last-known share for a Failed download.
     */
    public static function getProgressPercent(CachedContentFile $record): int
    {
        if ($record->status === CachedContentFileStatus::Completed) {
            return 100;
        }

        $downloaded = (int) ($record->bytes_downloaded ?? 0);
        $expected = (int) ($record->bytes_expected ?? 0);

        if ($downloaded <= 0 || $expected <= 0) {
            return 0;
        }

        return (int) min(100, floor(($downloaded / $expected) * 100));
    }

    /**
     * Progress bar color: status color, or warning for a download that has
     * made no progress for 30s.
     */
    public static function getProgressColor(CachedContentFile $record): string
    {
        $stalled = $record->status === CachedContentFileStatus::Downloading
            && $record->last_progress_at !== null
            && $record->last_progress_at->lt(now()->subSeconds(30));

        return match (true) {
            $stalled => 'warning',
            $record->status === CachedContentFileStatus::Completed => 'success',
            $record->status === CachedContentFileStatus::Failed => 'danger',
            default => 'primary',
        };
    }

    /**
     * ETA cell for active downloads with a known size and rate.
     */
    public static function getEtaLabel(CachedContentFile $record): ?string
    {
        if ($record->status !== CachedContentFileStatus::Downloading) {
            return null;
        }

        $rate = (int) ($record->bytes_per_second ?? 0);
        $expected = $record->bytes_expected !== null ? (int) $record->bytes_expected : null;
        $downloaded = (int) ($record->bytes_downloaded ?? 0);

        if ($rate <= 0 || $expected === null || $expected <= $downloaded) {
            return null;
        }

        if ($record->last_progress_at !== null && $record->last_progress_at->lt(now()->subSeconds(30))) {
            return null;
        }

        $etaSeconds = (int) ceil(($expected - $downloaded) / $rate);

        return $etaSeconds > 0 ? self::formatEtaSeconds($etaSeconds) : null;
    }

    /**
     * Compact duration label (45s, 3m 10s, 2h 5m, 1d 4h).
     */
    public static function formatEtaSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;

            return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        }

        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);

            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);

        return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
    }

    /**
     * Delete (or cancel) a row: signal any running worker to stop, remove
     * the file, then the row.
     */
    public static function deleteCachedFile(CachedContentFile $record): void
    {
        if (! self::canActOnRecord($record)) {
            return;
        }

        if (in_array($record->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
            // Also flag Pending rows: a worker may claim it between our read
            // and the delete.
            Cache::put(CachedContentFile::cancellationCacheKey($record->id), true, now()->addHours(48));
        }

        $record->deleteStoredFile();
        $record->delete();
    }

    /**
     * Queue a Failed row again. Returns false when the user can't act on it,
     * it isn't Failed, or its source item is gone.
     */
    public static function retryCachedFile(CachedContentFile $record): bool
    {
        if (! self::canActOnRecord($record) || $record->status !== CachedContentFileStatus::Failed) {
            return false;
        }

        return app(CachedContentDispatchService::class)->requeue($record);
    }

    private static function canActOnRecord(CachedContentFile $record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->isAdmin() || $record->user_id === (int) $user->id;
    }

    /**
     * @param  Collection<int, CachedContentFile>  $records
     * @return Collection<int, CachedContentFile>
     */
    private static function filterToOwnedRecords(Collection $records): Collection
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin()) {
            return $records;
        }

        return $records->filter(fn (CachedContentFile $record): bool => $record->user_id === (int) $user->id);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCachedContentFiles::route('/'),
        ];
    }
}
