<?php

namespace App\Filament\Clusters\PlaylistAliases;

use App\Filament\Resources\Bouquets\BouquetResource;
use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

class PlaylistAliasesCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $slug = 'aliases';

    protected static ?int $navigationSort = 5;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /**
     * Explicit order: the aliases list is the primary tab, bouquets second.
     * The default (discovery order) would put Bouquets first alphabetically.
     *
     * @return array<class-string>
     */
    public static function getClusteredComponents(): array
    {
        return [
            PlaylistAliasResource::class,
            BouquetResource::class,
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Playlist Aliases');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Playlist Aliases');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }
}
