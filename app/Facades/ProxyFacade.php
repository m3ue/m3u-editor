<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getProxyUrlForChannel(string $id, string|null $playlistUuid = null)
 * @method static string getProxyUrlForEpisode(string $id, string|null $playlistUuid = null)
 * @method static string getBaseUrl()
 */
class ProxyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'proxy';
    }
}
