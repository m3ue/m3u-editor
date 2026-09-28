<?php

namespace App\Filament\Resources\PlaylistViewers\Pages;

use App\Filament\Resources\PlaylistViewers\PlaylistViewerResource;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\WatchProgressLinker;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

class ListPlaylistViewers extends ListRecords
{
    protected static string $resource = PlaylistViewerResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Playlist viewers are used for in app viewing and M3U TV access. Viewers are created automatically via username used to access playlist or start playback.');
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('previewWatchProgressRelink')
                ->label(__('Preview Relink'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->schema($this->watchProgressScopeSchema())
                ->modalHeading(__('Preview Relink'))
                ->modalWidth('md')
                ->modalSubmitActionLabel(__('Preview'))
                ->action(function (array $data, WatchProgressLinker $linker): void {
                    $preview = $linker->preview($this->resolvePlaylistScope($data));
                    $counts = collect($preview['items'])->countBy('action');

                    Notification::make()
                        ->title(__('Relink preview'))
                        ->body(__(':checked entries checked: :backfilled would be backfilled, :relinked would be relinked, :deleted would be removed as unrecoverable.', [
                            'checked' => $preview['checked'],
                            'backfilled' => $counts->get('backfill', 0),
                            'relinked' => $counts->get('relink', 0),
                            'deleted' => $counts->get('delete', 0),
                        ]))
                        ->info()
                        ->persistent()
                        ->send();
                }),
            Action::make('relinkWatchProgress')
                ->label(__('Relink Watch Progress'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->schema($this->watchProgressScopeSchema())
                ->requiresConfirmation()
                ->modalHeading(__('Relink Watch Progress'))
                ->modalDescription(__('Scans every viewer\'s Continue Watching entries. Entries that no longer resolve are relinked via that TMDB ID where possible, or removed if the content is genuinely gone.'))
                ->modalSubmitActionLabel(__('Run now'))
                ->action(function (array $data, WatchProgressLinker $linker): void {
                    $stats = $linker->pruneOrphaned($this->resolvePlaylistScope($data));

                    Notification::make()
                        ->title(__('Watch progress relinked'))
                        ->body(__(':checked entries checked: :backfilled backfilled, :relinked relinked, :deleted removed as unrecoverable.', [
                            'checked' => $stats['checked'],
                            'backfilled' => $stats['backfilled'],
                            'relinked' => $stats['relinked'],
                            'deleted' => $stats['deleted'],
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Shared form schema for both actions: a playlist scope picker spanning
     * all four playlist types a PlaylistViewer's `viewerable` can be (see
     * WatchProgressLinker's docblock), defaulting to every playlist.
     *
     * @return array<int, Component>
     */
    private function watchProgressScopeSchema(): array
    {
        return [
            Select::make('playlist_scope')
                ->label(__('Scope'))
                ->options($this->playlistScopeOptions())
                ->searchable()
                ->nullable()
                ->placeholder(__('All playlists'))
                ->helperText(__('Leave empty to apply to all playlists.')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function playlistScopeOptions(): array
    {
        $userId = auth()->id();
        $options = [];

        Playlist::where('user_id', $userId)->get()
            ->each(function (Playlist $playlist) use (&$options) {
                $options[Playlist::class.'|'.$playlist->id] = $playlist->name.' ('.__('Playlist').')';
            });

        CustomPlaylist::where('user_id', $userId)->get()
            ->each(function (CustomPlaylist $playlist) use (&$options) {
                $options[CustomPlaylist::class.'|'.$playlist->id] = $playlist->name.' ('.__('Custom Playlist').')';
            });

        MergedPlaylist::where('user_id', $userId)->get()
            ->each(function (MergedPlaylist $playlist) use (&$options) {
                $options[MergedPlaylist::class.'|'.$playlist->id] = $playlist->name.' ('.__('Merged Playlist').')';
            });

        PlaylistAlias::where('user_id', $userId)->get()
            ->each(function (PlaylistAlias $alias) use (&$options) {
                $options[PlaylistAlias::class.'|'.$alias->id] = $alias->name.' ('.__('Playlist Alias').')';
            });

        return $options;
    }

    private function resolvePlaylistScope(array $data): Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null
    {
        if (empty($data['playlist_scope'])) {
            return null;
        }

        [$modelClass, $id] = explode('|', $data['playlist_scope'], 2);

        return $modelClass::find($id);
    }
}
