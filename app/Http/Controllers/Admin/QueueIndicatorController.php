<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueIndicatorService;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class QueueIndicatorController extends Controller
{
    public function __invoke(Request $request, QueueIndicatorService $queueIndicatorService): JsonResponse
    {
        abort_unless($request->user()?->isAdmin() && $this->queueManagerEnabled(), 403);

        $cacheKey = 'queue-indicator-snapshot:user:'.$request->user()->getKey();

        return response()->json(Cache::remember(
            $cacheKey,
            now()->addSeconds(5),
            fn (): array => $queueIndicatorService->getSnapshot(10),
        ));
    }

    private function queueManagerEnabled(): bool
    {
        try {
            return (bool) app(GeneralSettings::class)->show_queue_manager;
        } catch (Throwable) {
            return false;
        }
    }
}
