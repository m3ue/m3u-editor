<?php

/**
 * The movie and episode image columns fall back to a placeholder when a row has
 * no artwork. The fallback must be an image the app actually ships (or the
 * user's configured placeholder), not a path missing from public/.
 */

use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Filament\Tables\NetworkEpisodesTable;
use App\Filament\Tables\NetworkMoviesTable;
use App\Settings\GeneralSettings;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;

function placeholderImageColumn(Table $table): ImageColumn
{
    return collect($table->getColumns())->first(fn ($column) => $column instanceof ImageColumn);
}

dataset('placeholder image tables', [
    'network movies' => [fn (Table $table) => NetworkMoviesTable::configure($table), '/vod-series-poster-placeholder.png'],
    'network episodes' => [fn (Table $table) => NetworkEpisodesTable::configure($table), '/episode-placeholder.png'],
    'series episodes' => [fn (Table $table) => (new EpisodesRelationManager)->table($table), '/episode-placeholder.png'],
]);

it('falls back to a packaged placeholder image', function (Closure $configure, string $packagedPath) {
    $column = placeholderImageColumn($configure(Table::make(new EpisodesRelationManager)));

    expect($column->getDefaultImageUrl())->toBe(url($packagedPath))
        ->and(public_path(ltrim($packagedPath, '/')))->toBeFile();
})->with('placeholder image tables');

it('falls back to the configured placeholder image when one is set', function (Closure $configure) {
    $settings = app(GeneralSettings::class);
    $settings->episode_placeholder_url = 'https://cdn.example.test/episode.png';
    $settings->vod_series_poster_placeholder_url = 'https://cdn.example.test/poster.png';
    $settings->save();

    $column = placeholderImageColumn($configure(Table::make(new EpisodesRelationManager)));

    expect($column->getDefaultImageUrl())->toStartWith('https://cdn.example.test/');
})->with('placeholder image tables');
