<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Widgets\DynamicGroupsWidget;
use App\Jobs\CategoryFindAndReplace;
use App\Jobs\CategoryFindAndReplaceReset;
use App\Models\Playlist;
use App\Services\FindReplaceService;
use App\Services\MergedGroupService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Manage series categories. Only enabled series will be automatically updated on Playlist sync, this includes fetching episodes and metadata. You can also manually sync series to update episodes and metadata.');
    }

    protected function getHeaderActions(): array
    {
        return [
            MergedGroupService::createMergedCategoryAction(),
            ActionGroup::make([
                Action::make('find-replace')
                    ->label(__('Find & Replace'))
                    ->schema(fn () => FindReplaceService::getHeaderActionSchema('categories'))
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new CategoryFindAndReplace(
                                user_id: auth()->id(),
                                use_regex: $data['use_regex'] ?? true,
                                find_replace: $data['find_replace'] ?? '',
                                replace_with: $data['replace_with'] ?? '',
                                playlist_id: $data['playlist'] ?? null,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Find & Replace started'))
                            ->body(__('Find & Replace working in the background. You will be notified once the process is complete.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription(__('Select what you would like to find and replace in your series category names.'))
                    ->modalSubmitActionLabel(__('Replace now')),
                Action::make('find-replace-reset')
                    ->label(__('Undo Find & Replace'))
                    ->schema(fn () => FindReplaceService::getHeaderResetSchema())
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new CategoryFindAndReplaceReset(
                                user_id: auth()->id(),
                                playlist_id: $data['playlist'] ?? null,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Find & Replace reset started'))
                            ->body(__('Find & Replace reset working in the background. You will be notified once the process is complete.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription(__('Reset category names back to their original imported values. This will undo any find & replace changes for the selected playlist.'))
                    ->modalSubmitActionLabel(__('Reset now')),
            ])->button()->label(__('Actions')),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected function getFooterWidgets(): array
    {
        return [
            DynamicGroupsWidget::class,
        ];
    }

    /**
     * Pass the currently-active playlist tab into the registered footer
     * widgets so the Dynamic Groups table can follow the active playlist
     * (matches `setupTabs()` which keys tabs by `$playlist->id`).
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'activePlaylistId' => $this->activeTab,
        ];
    }

    public function getTabs(): array
    {
        return self::setupTabs();
    }

    public static function setupTabs($relationId = null): array
    {
        $where = [
            ['user_id', auth()->id()],
        ];

        // Fetch all the playlists for the current user, these will be our grouping tabs
        $playlists = Playlist::where($where)
            ->orderBy('name')
            ->get();

        // Return tabs
        return $playlists->mapWithKeys(fn ($playlist) => [
            $playlist->id => Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('playlist_id', $playlist->id))
                ->badge($playlist->categories()->count()),
        ])->toArray();
    }
}
