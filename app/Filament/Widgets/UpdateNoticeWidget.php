<?php

namespace App\Filament\Widgets;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;

class UpdateNoticeWidget extends Widget
{
    protected string $view = 'filament.widgets.update-notice-widget';

    public array $versionData = [];

    public bool $updateAvailable = false;

    public function mount(): void
    {
        $this->versionData = [
            'version' => VersionServiceProvider::getVersion(),
            'repo' => config('dev.repo'),
            'latestVersion' => VersionServiceProvider::getRemoteVersion(),
            'updateAvailable' => VersionServiceProvider::updateAvailable(),
            'branch' => GitInfo::getBranch() ?? null,
            'commit' => GitInfo::getCommit() ?? null,
        ];

        $this->updateAvailable = $this->versionData['updateAvailable'];
    }
}
