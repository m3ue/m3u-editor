<?php

namespace App\Filament\Actions;

use App\Facades\SortFacade;
use App\Models\Category;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared "Sort by Date" actions for Series: sorts by the series release date
 * (default) or by most recent activity (latest released episode). Used by the
 * Category table row/bulk actions, the Edit Category header, and the Series
 * list header, so every entry point offers the same sort methods.
 */
class SortSeriesByDateAction
{
    /**
     * Row/header action sorting the series within one category.
     */
    public static function forCategory(bool $refreshRelation = false): Action
    {
        return Action::make('sort_release_date')
            ->label(__('Sort by Date'))
            ->icon('heroicon-o-calendar-days')
            ->schema(static::schema())
            ->action(function (Category $record, array $data): void {
                static::sortCategory($record, $data);
            })
            ->after(function ($livewire, array $data) use ($refreshRelation): void {
                if ($refreshRelation) {
                    $livewire->dispatch('refreshRelation');
                }

                static::notify($data, 'category');
            })
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-calendar-days')
            ->modalDescription(__('Sort all series in this category by date? This will update the sort order.'));
    }

    /**
     * Bulk action sorting the series within each selected category.
     */
    public static function forCategoriesBulk(): BulkAction
    {
        return BulkAction::make('sort_release_date_bulk')
            ->label(__('Sort by Date'))
            ->icon('heroicon-o-calendar-days')
            ->schema(static::schema())
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $record) {
                    static::sortCategory($record, $data);
                }
            })
            ->after(function (array $data): void {
                static::notify($data, 'categories');
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-calendar-days')
            ->modalDescription(__('Sort all series in the selected categories by date? This will update the sort order.'));
    }

    /**
     * Header action sorting every series in a chosen playlist.
     */
    public static function forPlaylist(): Action
    {
        return Action::make('sort_release_date')
            ->label(__('Sort by Date'))
            ->icon('heroicon-o-calendar-days')
            ->schema([
                Select::make('playlist')
                    ->label(__('Playlist'))
                    ->required()
                    ->helperText(__('Select the Playlist you would like to sort Series by date for.'))
                    ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->searchable(),
                ...static::schema(),
            ])
            ->action(function (array $data): void {
                $playlist = Playlist::find($data['playlist'] ?? null);
                if (! $playlist) {
                    return;
                }

                $order = $data['sort'] ?? 'DESC';
                match ($data['column'] ?? 'release_date') {
                    'recent_activity' => SortFacade::bulkSortPlaylistSeriesByRecentActivity($playlist, $order),
                    default => SortFacade::bulkSortPlaylistSeriesByReleaseDate($playlist, $order),
                };
            })
            ->after(function (array $data): void {
                static::notify($data, 'playlist');
            })
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-calendar-days')
            ->modalDescription(__('Sort all Series in the selected playlist by date? This will update the sort order within each category.'))
            ->modalSubmitActionLabel(__('Yes, sort now'));
    }

    /**
     * @return array<int, Select>
     */
    private static function schema(): array
    {
        return [
            Select::make('column')
                ->label(__('Sort By'))
                ->options([
                    'release_date' => __('Release Date'),
                    'recent_activity' => __('Most Recent Activity'),
                ])
                ->default('release_date')
                ->helperText(__('Most Recent Activity orders series by their latest released episode, falling back to the series release date.'))
                ->required(),
            Select::make('sort')
                ->label(__('Sort Order'))
                ->options([
                    'DESC' => 'Newest first (2026 to 1950)',
                    'ASC' => 'Oldest first (1950 to 2026)',
                ])
                ->default('DESC')
                ->required(),
        ];
    }

    /**
     * @param  array{column?: string, sort?: string}  $data
     */
    private static function sortCategory(Category $category, array $data): void
    {
        $order = $data['sort'] ?? 'DESC';

        match ($data['column'] ?? 'release_date') {
            'recent_activity' => SortFacade::bulkSortCategorySeriesByRecentActivity($category, $order),
            default => SortFacade::bulkSortCategorySeriesByReleaseDate($category, $order),
        };
    }

    /**
     * @param  array{column?: string}  $data
     * @param  'category'|'categories'|'playlist'  $scope
     */
    private static function notify(array $data, string $scope): void
    {
        $isRecentActivity = ($data['column'] ?? 'release_date') === 'recent_activity';

        Notification::make()
            ->success()
            ->title($isRecentActivity ? __('Series Sorted by Most Recent Activity') : __('Series Sorted by Release Date'))
            ->body(match ($scope) {
                'category' => $isRecentActivity
                    ? __('The series in this category have been sorted by most recent activity.')
                    : __('The series in this category have been sorted by release date.'),
                'categories' => $isRecentActivity
                    ? __('The series in the selected categories have been sorted by most recent activity.')
                    : __('The series in the selected categories have been sorted by release date.'),
                'playlist' => $isRecentActivity
                    ? __('Series have been sorted by most recent activity across the playlist.')
                    : __('Series have been sorted by release date across the playlist.'),
            })
            ->send();
    }
}
