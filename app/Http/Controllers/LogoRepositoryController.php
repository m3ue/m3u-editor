<?php

namespace App\Http\Controllers;

use App\Services\LogoRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class LogoRepositoryController extends Controller
{
    public function index(LogoRepositoryService $service): JsonResponse
    {
        $logos = $service->getIndex();

        return response()->json([
            'name' => config('app.name').' Logo Repository',
            'base_url' => route('logo.repository'),
            'count' => count($logos),
            'logos' => $logos,
        ]);
    }

    public function show(string $filename, LogoRepositoryService $service): RedirectResponse
    {
        $entry = $service->findByFilename($filename);

        abort_unless($entry !== null, 404);

        return redirect()->away((string) $entry['logo']);
    }
}
