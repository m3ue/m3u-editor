<?php

namespace App\Filament\Widgets;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;

class UpdateNoticeWidget extends Widget
{
    protected static string $view = 'filament.widgets.update-notice-widget';

    public static ?int $sort = -5;

    public array $versionData = [];

    public function mount(): void
    {
        $version = VersionServiceProvider::getVersion();
        $latestVersion = VersionServiceProvider::getRemoteVersion();
        $updateAvailable = VersionServiceProvider::updateAvailable();
        $this->versionData = [
            'version' => $version,
            'repo' => config('dev.repo'),
            'latestVersion' => $latestVersion,
            'updateAvailable' => $updateAvailable,
            'branch' => GitInfo::getBranch() ?? null,
            'commit' => GitInfo::getCommit() ?? null,
        ];
    }
}
