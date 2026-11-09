<?php

namespace App\Filament\Resources\StreamWatchSessions\Pages;

use App\Filament\Actions\TestProxyWebhooksAction;
use App\Filament\Resources\StreamWatchSessions\StreamWatchSessionResource;
use App\Models\StreamWatchSession;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListStreamWatchSessions extends ListRecords
{
    protected static string $resource = StreamWatchSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [TestProxyWebhooksAction::make()];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Live channels, movies, and episodes streamed through the proxy, and who watched them.');
    }

    public function getTabs(): array
    {
        $count = fn (?string $type): int => StreamWatchSession::query()
            ->where('user_id', Auth::id())
            ->when($type, fn (Builder $query) => $query->where('content_type', $type))
            ->count();

        return [
            'all' => Tab::make(__('All'))
                ->icon('heroicon-s-queue-list')
                ->badge(fn (): int => $count(null)),
            'live' => Tab::make(__('Live TV'))
                ->icon('heroicon-s-tv')
                ->badge(fn (): int => $count('live'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('content_type', 'live')),
            'vod' => Tab::make(__('VOD'))
                ->icon('heroicon-s-film')
                ->badge(fn (): int => $count('vod'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('content_type', 'vod')),
            'episode' => Tab::make(__('Series'))
                ->icon('heroicon-s-play')
                ->badge(fn (): int => $count('episode'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('content_type', 'episode')),
        ];
    }
}
