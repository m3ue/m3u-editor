<?php

namespace App\Filament\Pages;

use App\Filament\Actions\PluginInstallActions;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Filament\Resources\Plugins\PluginResource;
use App\Models\Plugin;
use App\Models\PluginInstallReview;
use App\Models\PluginRun;
use App\Plugins\PluginManager;
use App\Plugins\PluginUpdateChecker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PluginsDashboard extends Page
{
    public static function getNavigationLabel(): string
    {
        return __('Overview');
    }

    public function getTitle(): string
    {
        return __('Plugins');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Plugins');
    }

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected string $view = 'filament.pages.plugins-dashboard';

    /**
     * Keep dashboard access aligned with the installed plugins resource.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseTools();
    }

    /**
     * Refresh any stale plugin runs before rendering the dashboard state.
     */
    public function mount(): void
    {
        app(PluginManager::class)->recoverStaleRuns();
    }

    /**
     * Explain what the dashboard controls at a glance.
     */
    public function getSubheading(): ?string
    {
        return __('Manage installed plugins, review new installs, and check security status from one place.');
    }

    /**
     * Expose admin-only staging actions in the page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        if (! $this->canManagePlugins()) {
            return [];
        }

        return [
            PluginInstallActions::discover(),
            Action::make('check_all_updates')
                ->label(__('Check for Updates'))
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading(__('Check Plugin Updates'))
                ->modalDescription(__('This will query GitHub for the latest release of every plugin that has a repository configured. Continue?'))
                ->action(function (): void {
                    $checker = app(PluginUpdateChecker::class);
                    $results = $checker->checkAll();

                    $updatesFound = collect($results)->filter(fn (array $r): bool => $r['update_available'] ?? false)->count();
                    $errors = collect($results)->filter(fn (array $r): bool => isset($r['error']))->count();

                    $message = trans_choice(':count update available.|:count updates available.', $updatesFound, ['count' => $updatesFound]);
                    if ($errors > 0) {
                        $message .= ' '.trans_choice(':count plugin had errors.', $errors, ['count' => $errors]);
                    }

                    Notification::make()
                        ->title(__('Plugin Update Check Complete'))
                        ->body($message)
                        ->success()
                        ->send();
                }),
            ...PluginInstallActions::staging(),
        ];
    }

    /**
     * Provide the dashboard view with counts, links, and latest records.
     */
    public function getViewData(): array
    {
        return [
            'summaryCards' => $this->summaryCards(),
            'attentionPlugins' => $this->attentionPlugins(),
            'recentInstallReviews' => $this->canManagePlugins() ? $this->recentInstallReviews() : collect(),
            'recentRuns' => $this->recentRuns(),
            'canManagePlugins' => $this->canManagePlugins(),
            'extensionsUrl' => PluginResource::getUrl(),
            'pluginInstallsUrl' => $this->canManagePlugins() ? PluginInstallReviewResource::getUrl() : null,
            'updatesAvailableCount' => Plugin::query()
                ->where('installation_status', 'installed')
                ->whereNotNull('latest_version')
                ->get()
                ->filter(fn (Plugin $p): bool => $p->hasUpdateAvailable())
                ->count(),
        ];
    }

    /**
     * Map plugin and review counts into dashboard summary cards.
     *
     * @return array<int, array<string, string|int>>
     */
    private function summaryCards(): array
    {
        return [
            [
                'label' => __('Installed Plugins'),
                'value' => Plugin::query()
                    ->where('installation_status', 'installed')
                    ->count(),
                'description' => __('Plugins that are currently installed and available to operate.'),
                'icon' => 'heroicon-s-puzzle-piece',
                'color' => 'blue',
            ],
            [
                'label' => __('Trusted Plugins'),
                'value' => Plugin::query()
                    ->where('installation_status', 'installed')
                    ->where('available', true)
                    ->where('validation_status', 'valid')
                    ->where('trust_state', 'trusted')
                    ->where('integrity_status', 'verified')
                    ->count(),
                'description' => __('Installed plugins that passed validation, are trusted, and haven\'t been modified.'),
                'icon' => 'heroicon-s-shield-check',
                'color' => 'green',
            ],
            [
                'label' => __('Pending Plugin Installs'),
                'value' => PluginInstallReview::query()
                    ->whereIn('status', ['staged', 'scanned', 'review_ready'])
                    ->count(),
                'description' => __('Uploads waiting for security scan or admin approval.'),
                'icon' => 'heroicon-s-arrow-down-tray',
                'color' => 'amber',
            ],
            [
                'label' => __('Plugins Needing Attention'),
                'value' => $this->attentionPluginQuery()->count(),
                'description' => __('Plugins that are blocked, modified, invalid, or not fully installed.'),
                'icon' => 'heroicon-s-exclamation-triangle',
                'color' => 'red',
            ],
            [
                'label' => __('Updates Available'),
                'value' => Plugin::query()
                    ->where('installation_status', 'installed')
                    ->whereNotNull('latest_version')
                    ->get()
                    ->filter(fn (Plugin $p): bool => $p->hasUpdateAvailable())
                    ->count(),
                'description' => __('Installed plugins with a newer version available on GitHub.'),
                'icon' => 'heroicon-s-arrow-up-circle',
                'color' => 'info',
            ],
        ];
    }

    /**
     * Return the plugins that need operator attention first.
     *
     * @return Collection<int, Plugin>
     */
    private function attentionPlugins(): Collection
    {
        return $this->attentionPluginQuery()
            ->orderByRaw("
                CASE
                    WHEN trust_state = 'blocked' THEN 0
                    WHEN integrity_status = 'changed' THEN 1
                    WHEN validation_status <> 'valid' THEN 2
                    WHEN trust_state = 'pending_review' THEN 3
                    WHEN installation_status <> 'installed' THEN 4
                    WHEN available = false THEN 5
                    ELSE 6
                END
            ")
            ->orderBy('name')
            ->limit(6)
            ->get();
    }

    /**
     * Return the latest plugin installs for the admin review queue.
     *
     * @return Collection<int, PluginInstallReview>
     */
    private function recentInstallReviews(): Collection
    {
        return PluginInstallReview::query()
            ->latest()
            ->limit(6)
            ->get();
    }

    /**
     * Return the latest plugin runs across all plugins.
     *
     * @return Collection<int, PluginRun>
     */
    private function recentRuns(): Collection
    {
        return PluginRun::query()
            ->with('plugin:id,name,plugin_id')
            ->latest()
            ->limit(10)
            ->get(['id', 'extension_plugin_id', 'trigger', 'action', 'hook', 'status', 'created_at', 'finished_at']);
    }

    /**
     * Reuse the same attention query for cards and list rendering.
     */
    private function attentionPluginQuery(): Builder
    {
        return Plugin::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('trust_state', 'pending_review')
                    ->orWhere('trust_state', 'blocked')
                    ->orWhereIn('integrity_status', ['changed', 'missing'])
                    ->orWhere('validation_status', '<>', 'valid')
                    ->orWhere('installation_status', '<>', 'installed')
                    ->orWhere('available', false);
            });
    }

    /**
     * Determine whether the current user can stage or approve plugin installs.
     */
    public function canManagePlugins(): bool
    {
        return auth()->user()?->canManagePlugins() ?? false;
    }

    /**
     * Provide a stable badge color for plugin install states in the view.
     */
    public function installStatusColor(string $status): string
    {
        return match ($status) {
            'installed' => 'success',
            'review_ready', 'scanned' => 'info',
            'rejected', 'discarded' => 'danger',
            default => 'warning',
        };
    }

    /**
     * Provide a stable badge color for plugin trust posture in the view.
     */
    public function pluginHealthColor(Plugin $plugin): string
    {
        if ($plugin->trust_state === 'blocked' || $plugin->integrity_status === 'changed' || $plugin->validation_status !== 'valid') {
            return 'danger';
        }

        if ($plugin->trust_state === 'pending_review' || $plugin->installation_status !== 'installed' || ! $plugin->available) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * Summarize the primary posture operators should care about first.
     */
    public function pluginHealthLabel(Plugin $plugin): string
    {
        if ($plugin->trust_state === 'blocked') {
            return __('Blocked');
        }

        if ($plugin->integrity_status === 'changed') {
            return __('Files Changed');
        }

        if ($plugin->validation_status !== 'valid') {
            return __('Validation Failed');
        }

        if ($plugin->trust_state === 'pending_review') {
            return __('Pending Review');
        }

        if ($plugin->installation_status !== 'installed') {
            return __('Uninstalled');
        }

        if (! $plugin->available) {
            return __('Missing Files');
        }

        return __('Healthy');
    }
}
