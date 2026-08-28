<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Services\M3uProxyService;
use Filament\Widgets\Widget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ActiveStreamsWidget extends Widget
{
    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.active-streams-widget';

    public static function canView(): bool
    {
        return auth()->check()
            && config('proxy.proxy_integration_enabled', true)
            && auth()->user()->canUseProxy();
    }

    protected function getViewData(): array
    {
        $userId = auth()->id();

        return Cache::remember("dashboard_active_streams_{$userId}", 20, function () {
            try {
                $result = app(M3uProxyService::class)->fetchActiveStreams();
            } catch (\Throwable) {
                return ['connected' => false, 'streams' => [], 'total' => 0, 'clients' => 0];
            }

            if (! ($result['success'] ?? false)) {
                return ['connected' => false, 'streams' => [], 'total' => 0, 'clients' => 0];
            }

            $streams = collect($result['streams'] ?? [])
                ->map(fn ($stream) => [
                    'name' => Arr::get($stream, 'metadata.channel_name')
                        ?? Arr::get($stream, 'metadata.title')
                        ?? Arr::get($stream, 'channel_name')
                        ?? Arr::get($stream, 'stream_id', __('Unknown stream')),
                    'clients' => (int) (Arr::get($stream, 'client_count') ?? Arr::get($stream, 'clients') ?? 0),
                    'status' => Arr::get($stream, 'status', 'active'),
                ])
                ->sortByDesc('clients')
                ->values();

            return [
                'connected' => true,
                'streams' => $streams->take(6)->all(),
                'total' => $streams->count(),
                'clients' => $streams->sum('clients'),
            ];
        });
    }

    public function getMonitorUrl(): string
    {
        return M3uProxyStreamMonitor::getUrl();
    }
}
