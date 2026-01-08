<?php

namespace App\Filament\Widgets;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $lastSynced = Carbon::parse(Playlist::where('user_id', auth()->id())->max('synced'));
        $relative = $lastSynced ? $lastSynced->diffForHumans() : null;
        $lastSyncedEpg = Carbon::parse(Epg::where('user_id', auth()->id())->max('synced'));
        $epgRelative = $lastSyncedEpg ? $lastSyncedEpg->diffForHumans() : null;

        return [
            Stat::make('Playlists', Playlist::where('user_id', auth()->id())->count())
                ->description($relative ? "Last sync $relative" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Groups', Group::where('user_id', auth()->id())->count()),
            Stat::make('Total Channels', Channel::where('user_id', auth()->id())->count()),
            Stat::make('Enabled Channels', Channel::where(
                [
                    ['user_id', auth()->id()],
                    ['enabled', true],
                ]
            )->count()),
            Stat::make('EPGs', Epg::where('user_id', auth()->id())->count())
                ->description($epgRelative ? "Last sync $epgRelative" : 'No syncs yet')
                ->descriptionIcon('heroicon-m-calendar-days'),
            Stat::make('Total EPG Channels', EpgChannel::where('user_id', auth()->id())->count()),
            Stat::make('EPG Mapped Channels', Channel::where(
                [
                    ['user_id', auth()->id()],
                    ['epg_channel_id', '!=', null],
                ]
            )->count()),
            Stat::make('Series', Series::where('user_id', auth()->id())->count()),
            Stat::make('Episodes', Episode::where('user_id', auth()->id())->count()),
        ];
    }
}
