<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getProxyUrlForChannel(string $id, bool $preview = false)
 * @method static string getProxyUrlForEpisode(string $id, bool $preview = false)
 * @method static string getBaseUrl()
 */
class ProxyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'proxy';
    }
}
