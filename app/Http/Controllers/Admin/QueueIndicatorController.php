<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueIndicatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QueueIndicatorController extends Controller
{
    public function __invoke(Request $request, QueueIndicatorService $queueIndicatorService): JsonResponse
    {
        abort_unless($request->user(), 403);

        $cacheKey = 'queue-indicator-snapshot:user:'.$request->user()->getKey();

        return response()->json(Cache::remember(
            $cacheKey,
            now()->addSeconds(5),
            fn (): array => $queueIndicatorService->getSnapshot(10),
        ));
    }
}
