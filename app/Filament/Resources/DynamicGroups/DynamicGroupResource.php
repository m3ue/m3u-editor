<?php

namespace App\Filament\Resources\DynamicGroups;

use App\Filament\Resources\DynamicGroups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\DynamicGroups\RelationManagers\SeriesRelationManager;
use App\Models\DynamicGroup;
use App\Services\TmdbService;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only Filament resource for viewing DynamicGroup membership and sync status.
 *
 * DynamicGroups are per-playlist virtual groups computed by `SyncDynamicGroups`
 * from TMDB list endpoints (Trending / Popular / In Theatres / Coming Soon /
 * Top Genre / By Network / By Streaming Service). The actual rule config lives
 * on `Playlist.dynamic_groups_config` and is edited in the Playlist form's
 * Dynamic Groups (TMDB) section. This resource does NOT create/edit/delete
 * rules - membership and config stay owned by the Playlist form. The resource
 * exists purely to show what's currently sitting in `dynamic_group_items` after
 * a sync, which is otherwise opaque.
 */
class DynamicGroupResource extends Resource
{
    protected static ?string $model = DynamicGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    public static function getNavigationGroup(): ?string
    {
        // Dynamic Groups are a per-playlist concept - nest under the Playlist
        // nav group (matches PlaylistResource.php:122). `DynamicGroup` rows are
        // type-mixed (vod or series), so they don't fit the content-type
        // buckets used by `VodGroupResource` ("VOD Channels"),
        // `GroupResource` ("Live Channels"), `CategoryResource` ("Series").
        return __('Playlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dynamic Groups');
    }

    public static function getModelLabel(): string
    {
        return __('Dynamic Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Dynamic Groups');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Source slugs → human labels. Must stay in sync with the `Select::make('source')`
     * options in `PlaylistResource.php:1958-1974` (the Playlist form's Dynamic
     * Groups (TMDB) section).
     *
     * @return array<string, string>
     */
    public static function sourceLabelFor(string $type): array
    {
        $vod = [
            'trending' => __('Trending'),
            'popular' => __('Popular'),
            'now_playing' => __('In Theatres'),
            'upcoming' => __('Coming Soon'),
            'top_genre' => __('Top Genre'),
            'provider' => __('By Streaming Service'),
        ];
        $series = [
            'trending' => __('Trending'),
            'popular' => __('Popular'),
            'top_genre' => __('Top Genre'),
            'tmdb_network' => __('By TV Network'),
            'provider' => __('By Streaming Service'),
        ];

        return $type === 'series' ? $series : $vod;
    }

    /**
     * Render `tmdb_params` (array on the DynamicGroup row) into a human-readable
     * key=value list. Resolves canonical IDs to names when a name can be sourced
     * cheaply (TV_NETWORKS is a local constant; genre/provider IDs require a TMDB
     * HTTP lookup and are left as the raw ID).
     *
     * Used by the view page's `tmdb_params` TextEntry.
     */
    public static function formatTmdbParams(array $params): string
    {
        if (empty($params)) {
            return '-';
        }

        $lines = [];
        foreach ($params as $key => $value) {
            $resolved = is_array($value)
                ? implode(', ', array_map(fn ($v) => (string) $v, $value))
                : (string) $value;

            if ($key === 'network_id' && is_numeric($value)) {
                $networkName = TmdbService::TV_NETWORKS[(int) $value] ?? null;
                if ($networkName) {
                    $resolved = $networkName.' ('.$value.')';
                }
            }

            $lines[] = $key.' = '.$resolved;
        }

        return implode("\n", $lines);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withCount(['channels', 'series']);

        // Per this repo's convention (see CLAUDE.md "Scope both `getEloquentQuery`
        // and `getGlobalSearchEloquentQuery()`"), admins see every user's rows,
        // non-admins see only their own. Mirror DvrRecordingResource:41-49 exactly
        // because the trait-based HasUserFiltering doesn't compose with this
        // resource's `withCount()` override.
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('dynamic_groups.user_id', auth()->id());
        }

        return $query;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery()
            ->withCount(['channels', 'series']);

        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('dynamic_groups.user_id', auth()->id());
        }

        return $query;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)
                ->schema([
                    Section::make(__('Configuration'))
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('type')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                    'vod' => __('VOD'),
                                    'series' => __('Series'),
                                    default => $state,
                                }),
                            TextEntry::make('source')
                                ->formatStateUsing(function (DynamicGroup $record): string {
                                    return static::sourceLabelFor($record->type)[$record->source] ?? $record->source;
                                }),
                            TextEntry::make('tmdb_params')
                                ->formatStateUsing(function (DynamicGroup $record): string {
                                    return static::formatTmdbParams((array) ($record->tmdb_params ?? []));
                                })
                                ->placeholder('-'),
                            TextEntry::make('sort_order'),
                            TextEntry::make('enabled')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? __('Enabled') : __('Disabled'))
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            TextEntry::make('last_synced_at')
                                ->since()
                                ->placeholder(__('Never')),
                        ]),
                    Section::make(__('Playlist'))
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('playlist.name'),
                            TextEntry::make('playlist.source_type')
                                ->label(__('Source Type')),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                // `withCount(['channels', 'series'])` is set in getEloquentQuery();
                // ensure it's always applied even when consumers override the query.
                return $query->withCount(['channels', 'series']);
            })
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'vod' => __('VOD'),
                        'series' => __('Series'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'vod' => 'info',
                        'series' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('source')
                    ->formatStateUsing(function (DynamicGroup $record): string {
                        return static::sourceLabelFor($record->type)[$record->source] ?? $record->source;
                    })
                    ->badge(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('item_count')
                    ->label(__('Items'))
                    ->state(function (DynamicGroup $record): int {
                        return $record->type === 'vod'
                            ? (int) $record->channels_count
                            : (int) $record->series_count;
                    }),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('playlist_id')
                    ->relationship('playlist', 'name')
                    ->label(__('Playlist')),
                SelectFilter::make('type')
                    ->options([
                        'vod' => __('VOD'),
                        'series' => __('Series'),
                    ]),
                SelectFilter::make('source')
                    ->options(fn () => array_unique(array_merge(
                        static::sourceLabelFor('vod'),
                        static::sourceLabelFor('series'),
                    ), SORT_REGULAR))
                    ->searchable(),
                TernaryFilter::make('enabled'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
            SeriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDynamicGroups::route('/'),
            'view' => Pages\ViewDynamicGroup::route('/{record}'),
        ];
    }
}
