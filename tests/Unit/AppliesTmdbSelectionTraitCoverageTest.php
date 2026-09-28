<?php

use App\Filament\Resources\Categories\RelationManagers\SeriesRelationManager as CategorySeriesRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\SeriesRelationManager as CustomPlaylistSeriesRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\VodRelationManager as CustomPlaylistVodRelationManager;
use App\Filament\Resources\Groups\RelationManagers\VodRelationManager as GroupVodRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\MoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\SeriesRelationManager as MediaServerSeriesRelationManager;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\VodGroups\RelationManagers\VodRelationManager as VodGroupVodRelationManager;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Traits\AppliesTmdbSelection;

/**
 * Every page/relation manager that mounts SeriesResource's or VodResource's
 * `manual_tmdb_search` table action (i.e. calls setupTable() without
 * stripping recordActions back out) must use AppliesTmdbSelection, or
 * clicking a search result throws a Livewire MethodNotFoundException - see
 * app/Traits/AppliesTmdbSelection.php's docblock. This guards against that
 * gap reappearing on a new or existing relation manager (GitHub issue #1523).
 */
it('applies the AppliesTmdbSelection trait to every class that mounts the manual TMDB search action', function (string $class) {
    expect(in_array(AppliesTmdbSelection::class, class_uses_recursive($class), true))->toBeTrue(
        "{$class} renders SeriesResource/VodResource's manual_tmdb_search action but does not use AppliesTmdbSelection."
    );
})->with([
    ListSeries::class,
    ViewSeries::class,
    ListVod::class,
    ViewVod::class,
    CategorySeriesRelationManager::class,
    GroupVodRelationManager::class,
    VodGroupVodRelationManager::class,
    CustomPlaylistSeriesRelationManager::class,
    CustomPlaylistVodRelationManager::class,
    MediaServerSeriesRelationManager::class,
    MoviesRelationManager::class,
]);
