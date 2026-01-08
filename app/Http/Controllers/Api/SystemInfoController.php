<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GitInfoService;
use Illuminate\Http\JsonResponse;

class SystemInfoController extends Controller
{
    public function __construct(private GitInfoService $gitInfoService) {}

    /**
     * Get comprehensive system information including git details.
     */
    public function getSystemInfo(): JsonResponse
    {
        $gitInfo = $this->gitInfoService->getGitInfo();

        return response()->json([
            'git' => [
                'source' => $gitInfo['source'],
                'branch' => $gitInfo['branch'],
                'commit' => $gitInfo['commit'],
                'commit_short' => $gitInfo['commit'] ? substr($gitInfo['commit'], 0, 8) : null,
                'tag' => $gitInfo['tag'],
                'build_date' => $gitInfo['build_date'],
                'is_production' => $this->gitInfoService->isProduction(),
            ],
            'app' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
                'environment' => config('app.env'),
                'debug' => config('app.debug'),
                'url' => config('app.url'),
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'server_time' => now()->toISOString(),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
            ],
        ]);
    }
}
