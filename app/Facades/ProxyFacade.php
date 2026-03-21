<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getBaseUrl()
 */
class ProxyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'proxy';
    }
}
