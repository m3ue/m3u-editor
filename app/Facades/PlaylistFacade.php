<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getUrls(\App\Models\Playlist|\App\Models\MergedPlaylist|\App\Models\CustomPlaylist|\App\Models\PlaylistAlias $playlist)
 * @method static boolean mediaFlowProxyEnabled()
 * @method static array{m3u: string, epg: string, xtream: array{server: string, default: array{username: string, password: string}, auths: list<array{name: string, username: string, password: string}>}, authEnabled: bool} getMediaFlowProxyUrls(\App\Models\Playlist|\App\Models\MergedPlaylist|\App\Models\CustomPlaylist|\App\Models\PlaylistAlias $playlist)
 * @method static array getMediaFlowSettings()
 * @method static string getMediaFlowProxyServerUrl()
 * @method static string buildMediaFlowStreamUrl(string $streamUrl)
 * @method static array|bool authenticate($username, $password) // [\App\Models\Playlist|\App\Models\MergedPlaylist|\App\Models\CustomPlaylist|\App\Models\PlaylistAlias|null, string $authMethod, string $username, string $password] or false on failure
 * @method static \App\Models\Playlist|\App\Models\MergedPlaylist|\App\Models\CustomPlaylist|\App\Models\PlaylistAlias|null resolvePlaylistByUuid(string $uuid)
 * @method static int resolveXtreamExpDate($authRecord, string $authMethod, ?string $username, ?string $password)
 */
class PlaylistFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'playlist';
    }
}
