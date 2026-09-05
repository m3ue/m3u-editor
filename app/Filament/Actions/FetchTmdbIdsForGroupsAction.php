<?php

namespace App\Filament\Actions;

use App\Jobs\FetchTmdbIds;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class FetchTmdbIdsForGroupsAction
{
    /**
     * Record / header action for a single VOD group ('vod') or series category ('series').
     */
    public static function make(string $type): Action
    {
        self::assertValidType($type);

        $isVod = $type === 'vod';

        return self::configure(Action::make('fetch_tmdb_ids'), $isVod)
            ->action(function (Model $record, array $data, Action $action) use ($isVod): void {
                self::guardApiKey($action);

                self::dispatch($isVod, [$record->id], (bool) ($data['overwrite_existing'] ?? false));
            });
    }

    /**
     * Toolbar bulk action for many groups/categories.
     */
    public static function makeBulk(string $type): BulkAction
    {
        self::assertValidType($type);

        $isVod = $type === 'vod';

        $action = self::configure(BulkAction::make('fetch_tmdb_ids'), $isVod)
            ->action(function (Collection $records, array $data, BulkAction $action) use ($isVod): void {
                self::guardApiKey($action);

                self::dispatch($isVod, $records->pluck('id')->all(), (bool) ($data['overwrite_existing'] ?? false));
            })
            ->deselectRecordsAfterCompletion();

        assert($action instanceof BulkAction);

        return $action;
    }

    /**
     * Apply the shared label / icon / schema / modal / notification chain to an action.
     *
     * @param  Action<mixed>  $action
     */
    private static function configure(Action $action, bool $isVod): Action
    {
        return $action
            ->label(__('Fetch TMDB Metadata'))
            ->icon('heroicon-o-magnifying-glass')
            ->modalIcon('heroicon-o-magnifying-glass')
            ->modalDescription($isVod
                ? __('Search TMDB for matching movies and fetch full metadata (plot, artwork, cast, genres) plus TMDB and IMDB IDs for the enabled VOD channels in the selected group(s)? This also enables Trash Guides compatibility for Radarr.')
                : __('Search TMDB for matching TV series and fetch full metadata (plot, artwork, cast, genres, seasons and episodes) plus TMDB, TVDB and IMDB IDs for the enabled series in the selected category(ies)? This also enables Trash Guides compatibility for Sonarr.'))
            ->modalSubmitActionLabel(__('Yes, fetch metadata now'))
            ->schema([
                Toggle::make('overwrite_existing')
                    ->label(__('Overwrite Existing Metadata'))
                    ->helperText($isVod
                        ? __('Overwrite existing TMDB metadata? If disabled, only items missing metadata are fetched.')
                        : __('Overwrite existing TMDB metadata? If disabled, only series missing metadata are fetched.'))
                    ->default(false),
            ])
            ->successNotification(
                Notification::make()
                    ->success()
                    ->title(__('TMDB metadata fetch started'))
                    ->body($isVod
                        ? __('Only enabled VOD channels in the selected group(s) will be processed. You will be notified when it is complete.')
                        : __('Only enabled series in the selected category(ies) will be processed. You will be notified when it is complete.'))
                    ->duration(10000)
            )
            ->requiresConfirmation();
    }

    /**
     * @param  array<int>  $ids
     */
    private static function dispatch(bool $isVod, array $ids, bool $overwriteExisting): void
    {
        $user = auth()->user();

        app('Illuminate\Contracts\Bus\Dispatcher')->dispatch(new FetchTmdbIds(
            vodGroupIds: $isVod ? $ids : null,
            seriesCategoryIds: $isVod ? null : $ids,
            overwriteExisting: $overwriteExisting,
            user: $user,
        ));
    }

    /**
     * Abort the action with a notice when no TMDB API key is configured.
     *
     * Uses $action->halt() rather than a bare return so Filament does not also
     * fire the configured success notification for an action that did nothing.
     */
    private static function guardApiKey(Action $action): void
    {
        $settings = app(GeneralSettings::class);

        if (! empty($settings->tmdb_api_key)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('TMDB API Key Required'))
            ->body(__('Please configure your TMDB API key in Settings > TMDB before using this feature.'))
            ->duration(10000)
            ->send();

        $action->halt();
    }

    private static function assertValidType(string $type): void
    {
        if (! in_array($type, ['vod', 'series'], true)) {
            throw new \InvalidArgumentException("FetchTmdbIdsForGroupsAction type must be 'vod' or 'series', got: {$type}");
        }
    }
}
