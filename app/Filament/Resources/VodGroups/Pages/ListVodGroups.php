<?php

namespace App\Filament\Resources\VodGroups\Pages;

use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Filament\Resources\VodGroups\Widgets\DynamicGroupsWidget;
use App\Jobs\GroupFindAndReplace;
use App\Jobs\GroupFindAndReplaceReset;
use App\Models\Playlist;
use App\Services\FindReplaceService;
use App\Services\MergedGroupService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListVodGroups extends ListRecords
{
    protected static string $resource = VodGroupResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Manage VOD groups.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    $data['custom'] = true;
                    $data['type'] = 'vod';

                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Group created'))
                        ->body(__('You can now assign channels to this group from the Channels section.')),
                )
                ->successRedirectUrl(fn (Model $record): string => VodGroupResource::getUrl('edit', ['record' => $record]))
                ->slideOver(),
            MergedGroupService::createMergedGroupAction('vod'),
            ActionGroup::make([
                Action::make('find-replace')
                    ->label(__('Find & Replace'))
                    ->schema(fn () => FindReplaceService::getHeaderActionSchema('vod_groups'))
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new GroupFindAndReplace(
                                user_id: auth()->id(),
                                use_regex: $data['use_regex'] ?? true,
                                find_replace: $data['find_replace'] ?? '',
                                replace_with: $data['replace_with'] ?? '',
                                playlist_id: $data['playlist'] ?? null,
                                group_type: 'vod',
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
                    ->modalDescription(__('Select what you would like to find and replace in your VOD group names.'))
                    ->modalSubmitActionLabel(__('Replace now')),
                Action::make('find-replace-reset')
                    ->label(__('Undo Find & Replace'))
                    ->schema(fn () => FindReplaceService::getHeaderResetSchema())
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new GroupFindAndReplaceReset(
                                user_id: auth()->id(),
                                playlist_id: $data['playlist'] ?? null,
                                group_type: 'vod',
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
                    ->modalDescription(__('Reset VOD group names back to their original imported values. This will undo any find & replace changes for the selected playlist.'))
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
            ->where('user_id', auth()->id())
            ->where('type', 'vod');
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
                ->modifyQueryUsing(fn ($query) => $query->where([
                    ['playlist_id', $playlist->id],
                    ['type', 'vod'],
                ]))
                ->badge($playlist->vodGroups()->count()),
        ])->toArray();
    }
}
