<?php

namespace App\Http\Controllers;

use App\Services\QueueIndicatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QueueIndicatorController extends Controller
{
    public function __invoke(Request $request, QueueIndicatorService $service): JsonResponse
    {
        abort_unless($request->user(), 403);

        return response()->json(
            Cache::remember(
                'queue-indicator-snapshot',
                now()->addSeconds(5),
                fn (): array => $service->getSnapshot(10),
            )
        );
    }
}
