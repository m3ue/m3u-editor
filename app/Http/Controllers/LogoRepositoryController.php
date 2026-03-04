<?php

namespace App\Http\Controllers;

use App\Services\LogoRepositoryService;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class LogoRepositoryController extends Controller
{
    public function index(LogoRepositoryService $service): JsonResponse
    {
        abort_unless($this->isEnabled(), 404);

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
        abort_unless($this->isEnabled(), 404);

        $entry = $service->findByFilename($filename);

        abort_unless($entry !== null, 404);

        return redirect()->away((string) $entry['logo']);
    }

    protected function isEnabled(): bool
    {
        try {
            $settings = app(GeneralSettings::class);

            return (bool) ($settings->logo_repository_enabled ?? true);
        } catch (\Exception $e) {
            return true;
        }
    }
}
