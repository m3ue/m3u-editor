<?php

namespace App\Filament\Pages;

use App\Livewire\StreamStatsChart;
use App\Models\Channel;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Redis;

class Stats extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Stats';
    protected static ?string $title           = 'Stats';
    //protected ?string        $subheading      = 'Start streaming a channel to see the stats.';
    protected ?string        $subheading      = 'Stats are currently disabled until more testing can be done.';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int    $navigationSort  = 99;
    protected static string  $view            = 'filament.pages.stats';
    protected static bool    $shouldRegisterNavigation = false; // hide for now, needs more testing...

    public static ?string $pollingInterval = '5s';

    protected function getHeaderWidgets(): array
    {
        // Fetch all currently streaming channel IDs
        $activeIds = Redis::smembers('mpts:active_ids');
        if (empty($activeIds)) {
            return [];
        }

        // Decode the channel IDs and IPs
        $clients = [];
        foreach ($activeIds as $clientKey) {
            $keys = explode('::', $clientKey);
            if (count($keys) < 2) {
                continue;
            }
            $channelId = $keys[1];
            $channel = Channel::find($channelId);
            $clients[] = [
                'channelId' => $channelId,
                'title'     => $channel?->title ?? 'Unknown',
                'ip'        => $keys[0],
            ];
        }

        // Dynamically spawn one StreamStatsChart per streaming channel/client
        $widgets = [];
        foreach ($clients as $client) {
            $widgets[] = StreamStatsChart::make([
                'streamId'          => $client['channelId'],
                'title'             => "{$client['title']} (MPTS)",
                'subheading'        => $client['ip'],
                'columnSpan'        => 4,
                'pollingInterval'   => '1s',
            ]);
        }
        return $widgets;
    }
}
