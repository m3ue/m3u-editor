<?php

namespace App\Filament\Widgets;

use App\Enums\SyncRunStatus;
use App\Filament\Pages\RequestContent;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\SyncRun;
use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Deliberately NOT registered in AdminPanelProvider yet. The alert panel is
 * kept for a future round once it can be dismissed; right now it is always on
 * screen and gets in the way. Add it to the panel's widgets() array to enable.
 */
class NeedsAttentionWidget extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.needs-attention-widget';

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    protected function getViewData(): array
    {
        $userId = auth()->id();

        $items = Cache::remember("dashboard_attention_{$userId}", 60, function () use ($userId) {
            $rows = [];

            $failedSyncs = SyncRun::query()
                ->where('user_id', $userId)
                ->where('status', SyncRunStatus::Failed->value)
                ->where('created_at', '>=', now()->subDay())
                ->count();
            if ($failedSyncs > 0) {
                $rows[] = [
                    'label' => trans_choice(':count playlist sync failed in the last 24h|:count playlist syncs failed in the last 24h', $failedSyncs, ['count' => $failedSyncs]),
                    'icon' => 'heroicon-m-arrow-path',
                    'color' => 'danger',
                    'url' => PlaylistResource::getUrl(),
                ];
            }

            $failedRecordings = DvrRecording::query()
                ->where('user_id', $userId)
                ->failed()
                ->where('updated_at', '>=', now()->subDay())
                ->count();
            if ($failedRecordings > 0) {
                $rows[] = [
                    'label' => trans_choice(':count DVR recording failed in the last 24h|:count DVR recordings failed in the last 24h', $failedRecordings, ['count' => $failedRecordings]),
                    'icon' => 'heroicon-m-video-camera-slash',
                    'color' => 'danger',
                    'url' => DvrRecordingResource::getUrl(),
                ];
            }

            $stalePlaylists = Playlist::query()
                ->where('user_id', $userId)
                ->where('auto_sync', true)
                ->where(function ($query) {
                    $query->whereNull('synced')
                        ->orWhere('synced', '<', now()->subDays(2));
                })
                ->count();
            if ($stalePlaylists > 0) {
                $rows[] = [
                    'label' => trans_choice(':count auto-sync playlist has not synced in 48h+|:count auto-sync playlists have not synced in 48h+', $stalePlaylists, ['count' => $stalePlaylists]),
                    'icon' => 'heroicon-m-clock',
                    'color' => 'warning',
                    'url' => PlaylistResource::getUrl(),
                ];
            }

            $pendingRequests = MediaRequest::query()->pending()->count();
            if ($pendingRequests > 0) {
                $rows[] = [
                    'label' => trans_choice(':count media request awaiting review|:count media requests awaiting review', $pendingRequests, ['count' => $pendingRequests]),
                    'icon' => 'heroicon-m-inbox-arrow-down',
                    'color' => 'warning',
                    'url' => RequestContent::getUrl(),
                ];
            }

            $pendingPlugins = Plugin::query()->where('trust_state', 'pending_review')->count();
            if ($pendingPlugins > 0) {
                $rows[] = [
                    'label' => trans_choice(':count plugin pending trust review|:count plugins pending trust review', $pendingPlugins, ['count' => $pendingPlugins]),
                    'icon' => 'heroicon-m-shield-exclamation',
                    'color' => 'warning',
                    'url' => PluginInstallReviewResource::getUrl(),
                ];
            }

            $overQuota = DvrSetting::query()
                ->where('global_disk_quota_gb', '>', 0)
                ->withSum(['recordings as used_bytes' => fn ($query) => $query->whereNotNull('file_size_bytes')], 'file_size_bytes')
                ->get()
                ->filter(fn (DvrSetting $setting) => (int) $setting->used_bytes >= $setting->global_disk_quota_gb * 1024 ** 3 * 0.9)
                ->count();
            if ($overQuota > 0) {
                $rows[] = [
                    'label' => trans_choice(':count user is over 90% of their DVR storage quota|:count users are over 90% of their DVR storage quota', $overQuota, ['count' => $overQuota]),
                    'icon' => 'heroicon-m-circle-stack',
                    'color' => 'danger',
                    'url' => DvrRecordingResource::getUrl(),
                ];
            }

            return $rows;
        });

        $updateAvailable = false;
        try {
            $updateAvailable = VersionServiceProvider::updateAvailable();
        } catch (\Throwable) {
            // Ignore version check failures on the dashboard.
        }

        if ($updateAvailable) {
            $items[] = [
                'label' => __('A newer version of m3u editor is available'),
                'icon' => 'heroicon-m-arrow-up-circle',
                'color' => 'info',
                'url' => 'https://github.com/'.config('dev.repo').'/releases',
                'external' => true,
            ];
        }

        return ['items' => $items];
    }
}
