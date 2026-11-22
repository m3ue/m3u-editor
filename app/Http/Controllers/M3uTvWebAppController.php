<?php

namespace App\Http\Controllers;

use App\Services\M3uTvWebAppService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the M3U TV web app at /m3u-tv/ from the installed release (see
 * M3uTvWebAppService), or from a build copied into public/m3u-tv by hand.
 */
class M3uTvWebAppController extends Controller
{
    /** MIME types the browser is strict about (module scripts, streaming Wasm). */
    private const CONTENT_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'wasm' => 'application/wasm',
        'json' => 'application/json; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'otf' => 'font/otf',
        'ttf' => 'font/ttf',
        'woff2' => 'font/woff2',
        'md' => 'text/markdown; charset=utf-8',
    ];

    public function __invoke(Request $request, M3uTvWebAppService $webApp, ?string $path = null): Response
    {
        $root = $webApp->servingRoot();
        if ($root === null) {
            return response(__('The M3U TV web app is not installed. An admin can install it from Settings > TV App.'), 404)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $relativePath = trim((string) $path, '/');
        $relativePath = $relativePath === '' ? 'index.html' : $relativePath;

        $rootRealPath = realpath($root);
        $file = realpath($root.DIRECTORY_SEPARATOR.$relativePath);
        abort_if(
            $rootRealPath === false || $file === false || ! is_file($file)
                || ! str_starts_with($file, $rootRealPath.DIRECTORY_SEPARATOR),
            404,
        );

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $headers = ['Cache-Control' => $this->cacheControl(basename($file))];
        if (isset(self::CONTENT_TYPES[$extension])) {
            $headers['Content-Type'] = self::CONTENT_TYPES[$extension];
        }
        if ($extension === 'html') {
            // Cross-origin isolation lets Flutter's skwasm renderer run
            // multi-threaded on secure contexts (HTTPS or localhost);
            // `credentialless` keeps cross-origin artwork and streams loading.
            $headers['Cross-Origin-Opener-Policy'] = 'same-origin';
            $headers['Cross-Origin-Embedder-Policy'] = 'credentialless';
        }

        $response = new BinaryFileResponse($file, 200, $headers);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Content-hashed files (main.dart.<hash>.wasm and friends) never change,
     * so browsers may keep them; everything else, including index.html and
     * flutter_bootstrap.js, is revalidated so an update is picked up.
     */
    private function cacheControl(string $fileName): string
    {
        return preg_match('/\.[0-9a-f]{8,}\.[a-z0-9]+$/i', $fileName) === 1
            ? 'public, max-age=31536000, immutable'
            : 'no-cache';
    }
}
