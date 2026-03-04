<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ShortURLController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(
        Request $request,
        string $shortUrlKey,
        ?string $path = null
    ) {
        $response = app()->call(\AshAllenDesign\ShortURL\Controllers\ShortURLController::class, [
            'request' => $request,
            'shortURLKey' => $shortUrlKey,
        ]);

        if (! $response instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        if ($path) {
            $parsed = parse_url($response->getTargetUrl());

            $base = ($parsed['scheme'] ?? '').'://'.($parsed['host'] ?? '');
            if (isset($parsed['port'])) {
                $base .= ':'.$parsed['port'];
            }
            $base .= $parsed['path'] ?? '';
            $base = rtrim($base, '/').'/'.ltrim($path, '/');

            if (! empty($parsed['query'])) {
                $base .= '?'.$parsed['query'];
            }

            return redirect($base, $response->getStatusCode());
        }

        return $response;
    }
}
