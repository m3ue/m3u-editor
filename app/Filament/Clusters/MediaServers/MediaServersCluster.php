<?php

namespace App\Filament\Clusters\MediaServers;

use App\Filament\Pages\RequestContent;
use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

class MediaServersCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = null;

    /**
     * The media servers resource's URL before it joined this cluster, so
     * bookmarks and forward-auth rules for `/media-server-integrations*`
     * (see the SSO docs) still cover every page here.
     */
    protected static ?string $slug = 'media-server-integrations';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /**
     * Without this the cluster root renders an empty page for users who
     * can't open any tab, instead of the 403 its pages give.
     */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }

    /**
     * Media servers first (the default tab), then Sonarr & Radarr and the
     * Request Content page that searches them.
     *
     * @return array<class-string>
     */
    public static function getClusteredComponents(): array
    {
        return [
            MediaServerIntegrationResource::class,
            ArrIntegrationResource::class,
            RequestContent::class,
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Media Servers');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Media Servers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }
}
