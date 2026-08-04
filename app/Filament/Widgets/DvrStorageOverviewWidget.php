<?php

namespace App\Filament\Widgets;

use App\Models\DvrSetting;
use Filament\Widgets\Widget;

class DvrStorageOverviewWidget extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dvr-storage-overview-widget';

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    protected function getViewData(): array
    {
        $rows = DvrSetting::with('user')->get()->map(function (DvrSetting $setting) {
            $usedBytes = $setting->storage_used_bytes;
            $quotaBytes = $setting->global_disk_quota_gb > 0
                ? $setting->global_disk_quota_gb * 1024 ** 3
                : null;

            return [
                'user' => $setting->user,
                'recording_count' => $setting->recordings()->count(),
                'used_bytes' => $usedBytes,
                'used_formatted' => self::formatFileSize($usedBytes),
                'quota_bytes' => $quotaBytes,
                'quota_formatted' => $quotaBytes ? self::formatFileSize($quotaBytes) : __('Unlimited'),
                'percent' => $quotaBytes ? min(100, round($usedBytes / $quotaBytes * 100, 1)) : null,
            ];
        });

        return ['rows' => $rows];
    }

    private static function formatFileSize(?int $sizeInBytes): string
    {
        if (! $sizeInBytes) {
            return '—';
        }

        if ($sizeInBytes >= 1024 * 1024 * 1024) {
            return number_format($sizeInBytes / 1024 / 1024 / 1024, 1).' GB';
        }

        return number_format($sizeInBytes / 1024 / 1024, 1).' MB';
    }
}
