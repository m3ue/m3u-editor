<?php

namespace App\Filament\Clusters\Settings\Pages\Concerns;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;

abstract class BaseSettingsPage extends SettingsPage
{
    protected static string $settings = GeneralSettings::class;

    protected static ?string $cluster = SettingsCluster::class;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('Settings saved'))
            ->body(__('Your preferences have been saved successfully.'));
    }
}
