<?php

namespace App\Facades;

use App\Services\GitInfoService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array getGitInfo()
 * @method static string|null getBranch()
 * @method static string|null getCommit()
 * @method static string|null getTag()
 * @method static string|null getBuildDate()
 * @method static bool isOnBranch(string $branch)
 * @method static bool isProduction()
 *
 * @see \App\Services\GitInfoService
 */
class GitInfo extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return GitInfoService::class;
    }
}
