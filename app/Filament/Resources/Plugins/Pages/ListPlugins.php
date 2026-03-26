<?php

namespace App\Filament\Resources\Plugins\Pages;

use App\Filament\Actions\PluginInstallActions;
use App\Filament\Resources\Plugins\PluginResource;
use App\Plugins\PluginManager;
use Filament\Resources\Pages\ListRecords;

class ListPlugins extends ListRecords
{
    protected static string $resource = PluginResource::class;

    public function mount(): void
    {
        parent::mount();

        app(PluginManager::class)->recoverStaleRuns();
    }

    protected function getHeaderActions(): array
    {
        return [
            PluginInstallActions::discover(),
            PluginInstallActions::pluginInstallsLink(),
        ];
    }
}
