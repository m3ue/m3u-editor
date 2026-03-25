<?php

namespace App\Filament\Pages;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class ReleaseLogs extends Page
{
    protected static ?string $navigationLabel = 'Release Logs';

    protected static ?string $title = 'Release Logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canViewReleaseLogs();
    }

    public function getView(): string
    {
        return 'filament.pages.release-logs';
    }

    public string $filter = 'all';

    public array $allReleases = [];

    public string $currentVersion = '';

    public string $currentBranch = '';

    public function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    try {
                        VersionServiceProvider::fetchReleases(perBranchLimit: 15, refresh: true);
                        $this->loadReleases();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Failed to refresh release logs')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })->after(function () {
                    Notification::make()
                        ->title('Release logs refreshed')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function mount(): void
    {
        $this->currentVersion = VersionServiceProvider::getVersion();
        $this->currentBranch = GitInfo::getBranch() ?? 'master';
        $this->filter = $this->currentBranch; // Default filter to current branch
        $this->loadReleases();
    }

    protected function loadReleases(): void
    {
        $stored = VersionServiceProvider::getStoredReleases();
        $releases = ! empty($stored) ? $stored : VersionServiceProvider::fetchReleases(perBranchLimit: 15);

        $normalizedCurrent = ltrim((string) $this->currentVersion, 'v');

        $this->allReleases = array_map(function ($r) use ($normalizedCurrent) {
            $tag = $r['tag_name'] ?? ($r['name'] ?? null);
            $normalizedTag = ltrim((string) $tag, 'v');

            if (str_ends_with($normalizedTag, '-dev')) {
                $type = 'dev';
            } elseif (str_ends_with($normalizedTag, '-exp')) {
                $type = 'experimental';
            } else {
                $type = 'master';
            }

            return [
                'tag' => $tag,
                'name' => $r['name'] ?? $r['tag_name'] ?? '',
                'url' => $r['html_url'] ?? null,
                'body' => $r['body'] ?? '',
                'published_at' => $r['published_at'] ?? null,
                'prerelease' => $r['prerelease'] ?? false,
                'type' => $type,
                'is_current' => $tag !== null && $normalizedTag === $normalizedCurrent,
            ];
        }, $releases ?: []);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function getFilteredReleases(): array
    {
        if ($this->filter === 'all') {
            return $this->allReleases;
        }

        return array_values(array_filter($this->allReleases, fn ($r) => $r['type'] === $this->filter));
    }

    public function formatMarkdown(string $text): string
    {
        return Str::markdown($text);
    }

    public function getCounts(): array
    {
        $counts = ['all' => \count($this->allReleases), 'master' => 0, 'dev' => 0, 'experimental' => 0];
        foreach ($this->allReleases as $r) {
            $counts[$r['type']] = ($counts[$r['type']] ?? 0) + 1;
        }

        return $counts;
    }

    public function getViewData(): array
    {
        return [
            'releases' => $this->getFilteredReleases(),
            'filter' => $this->filter,
            'counts' => $this->getCounts(),
            'currentVersion' => $this->currentVersion,
            'currentBranch' => $this->currentBranch,
        ];
    }
}
