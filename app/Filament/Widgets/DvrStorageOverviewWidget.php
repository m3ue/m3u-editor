<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
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
        $rows = DvrSetting::with('user')
            ->withCount('recordings')
            ->withSum(['recordings as used_bytes' => fn ($query) => $query->whereNotNull('file_size_bytes')], 'file_size_bytes')
            ->get()
            ->map(function (DvrSetting $setting) {
                $usedBytes = (int) $setting->used_bytes;
                $quotaBytes = $setting->global_disk_quota_gb > 0
                    ? $setting->global_disk_quota_gb * 1024 ** 3
                    : null;

                return [
                    'user' => $setting->user,
                    'recording_count' => $setting->recordings_count,
                    'used_bytes' => $usedBytes,
                    'used_formatted' => DvrRecordingResource::formatFileSize($usedBytes),
                    'quota_bytes' => $quotaBytes,
                    'quota_formatted' => $quotaBytes ? DvrRecordingResource::formatFileSize($quotaBytes) : __('Unlimited'),
                    'percent' => $quotaBytes ? min(100, round($usedBytes / $quotaBytes * 100, 1)) : null,
                ];
            });

        return ['rows' => $rows];
    }
}
