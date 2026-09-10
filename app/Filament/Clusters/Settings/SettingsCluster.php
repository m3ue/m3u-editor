<?php

namespace App\Filament\Clusters\Settings;

use App\Filament\Clusters\Settings\Pages\ManageAlertSettings;
use App\Filament\Clusters\Settings\Pages\ManageApiSettings;
use App\Filament\Clusters\Settings\Pages\ManageAssetSettings;
use App\Filament\Clusters\Settings\Pages\ManageBackupSettings;
use App\Filament\Clusters\Settings\Pages\ManageCopilotSettings;
use App\Filament\Clusters\Settings\Pages\ManageGeneralSettings;
use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Filament\Clusters\Settings\Pages\ManageNavigationSettings;
use App\Filament\Clusters\Settings\Pages\ManageProxySettings;
use App\Filament\Clusters\Settings\Pages\ManageSmtpSettings;
use App\Filament\Clusters\Settings\Pages\ManageSyncSettings;
use App\Filament\Clusters\Settings\Pages\ManageTvAppSettings;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $slug = 'settings';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    /**
     * Explicit order: General is the landing page, the rest follow the order the
     * tabs used before this cluster replaced them.
     *
     * @return array<class-string>
     */
    public static function getClusteredComponents(): array
    {
        return [
            ManageGeneralSettings::class,
            ManageNavigationSettings::class,
            ManageProxySettings::class,
            ManageTvAppSettings::class,
            ManageSyncSettings::class,
            ManageAssetSettings::class,
            ManageBackupSettings::class,
            ManageSmtpSettings::class,
            ManageApiSettings::class,
            ManageIntegrationSettings::class,
            ManageCopilotSettings::class,
            ManageAlertSettings::class,
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }
}
