<?php

namespace App\Filament\Widgets;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

class UpdateNoticeWidget extends Widget
{
    protected string $view = 'filament.widgets.update-notice-widget';

    public static ?int $sort = -5;

    public array $versionData = [];

    public array $releases = [];

    public bool $updateAvailable = false;

    public string $currentVersion = '';

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

        // Try to read stored releases first (flat file). If none, fetch.
        $releases = VersionServiceProvider::getStoredReleases();
        if (empty($releases)) {
            $releases = VersionServiceProvider::fetchReleases(10, refresh: false);
        }

        // Normalize results to an array of useful fields
        $this->releases = array_map(function ($r) {
            return [
                'tag' => $r['tag_name'] ?? ($r['name'] ?? null),
                'name' => $r['name'] ?? $r['tag_name'] ?? '',
                'url' => $r['html_url'] ?? null,
                'body' => $r['body'] ?? '',
                'published_at' => $r['published_at'] ?? null,
            ];
        }, $releases ?: []);

        // Mark current version on each item for view convenience
        $current = VersionServiceProvider::getVersion();
        $normalizedCurrent = ltrim((string) $current, 'v');
        foreach ($this->releases as &$r) {
            $r['is_current'] = ($r['tag'] !== null) && (ltrim($r['tag'], 'v') === $normalizedCurrent);
        }

        // Set update info as public properties (no emit on server side)
        $this->currentVersion = $current;
        $this->updateAvailable = VersionServiceProvider::updateAvailable();
    }

    public function formatMarkdown(string $text): string
    {
        // $text = nl2br(e($text));

        return Str::markdown($text);
    }
}
