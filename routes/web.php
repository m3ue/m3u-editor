<?php

use App\Http\Controllers\EpgFileController;
use App\Http\Controllers\EpgGenerateController;
use App\Http\Controllers\LogoProxyController;
use App\Http\Controllers\PlaylistGenerateController;
use App\Http\Controllers\XtreamApiController;
use App\Services\ExternalIpService;
use AshAllenDesign\ShortURL\Controllers\ShortURLController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// External IP refresh route for admin panel
Route::post('/admin/refresh-external-ip', function (ExternalIpService $ipService) {
    $ipService->clearCache();
    $ip = $ipService->getExternalIp();
    return response()->json(['success' => true, 'external_ip' => $ip]);
})->middleware(['auth']);

// Handle short URLs with optional path forwarding (e.g. /s/{key}/device.xml)
Route::get('/s/{shortUrlKey}/{path?}', function (Request $request, string $shortUrlKey, ?string $path = null) {
    $response = app()->call(ShortURLController::class, [
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
})->where('path', '.*');

/*
 * Logo proxy route - cache and serve remote logos
 */
Route::get('/logo-proxy/{encodedUrl}', [LogoProxyController::class, 'serveLogo'])
    ->where('encodedUrl', '[A-Za-z0-9\-_=]+')
    ->name('logo.proxy');

/*
 * Playlist/EPG output routes
 */

// Generate M3U playlist from the playlist configuration
Route::get('/{uuid}/playlist.m3u', PlaylistGenerateController::class)
    ->name('playlist.generate');

Route::get('/{uuid}/hdhr/device.xml', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhr'])
    ->name('playlist.hdhr');
Route::get('/{uuid}/hdhr', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrOverview'])
    ->name('playlist.hdhr.overview');
Route::get('/{uuid}/hdhr/discover.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrDiscover'])
    ->name('playlist.hdhr.discover');
Route::get('/{uuid}/hdhr/lineup.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrLineup'])
    ->name('playlist.hdhr.lineup');
Route::get('/{uuid}/hdhr/lineup_status.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrLineupStatus'])
    ->name('playlist.hdhr.lineup_status');

// Generate EPG playlist from the playlist configuration
Route::get('/{uuid}/epg.xml', EpgGenerateController::class)
    ->name('epg.generate');
Route::get('/{uuid}/epg.xml.gz', [EpgGenerateController::class, 'compressed'])
    ->name('epg.generate.compressed');

// Serve the EPG file
Route::get('epgs/{uuid}/epg.xml', EpgFileController::class)
    ->name('epg.file');

/*
 * DEBUG routes
 */

// Test webhook endpoint
Route::post('/webhook/test', \App\Http\Controllers\WebhookTestController::class)
    ->name('webhook.test.post');
Route::get('/webhook/test', \App\Http\Controllers\WebhookTestController::class)
    ->name('webhook.test.get');

// If local env, show PHP info screen
Route::get('/phpinfo', function () {
    if (app()->environment('local')) {
        phpinfo();
    } else {
        abort(404);
    }
});

/*
 * Proxy routes
 */

// More specific routes first to avoid conflicts

// HLS route with specific path structure
Route::get('/shared/stream/{streamKey}/hls', [\App\Http\Controllers\SharedStreamController::class, 'serveHLS'])
    ->name('shared.stream.hls')
    ->where('streamKey', '[a-f0-9]{32}'); // Match 32-character MD5 hashes

// Episode route with /e/ prefix
Route::get('/shared/stream/e/{encodedId}.{format?}', [\App\Http\Controllers\SharedStreamController::class, 'streamEpisode'])
    ->name('shared.stream.episode');

// Direct stream key access (32-character MD5 hash without extension)
Route::get('/shared/stream/{streamKey}', [\App\Http\Controllers\SharedStreamController::class, 'serveSharedStream'])
    ->name('shared.stream.direct')
    ->where('streamKey', '[a-f0-9]{32}'); // Match 32-character MD5 hashes

// Channel route (catch-all for encoded IDs with optional format)
Route::get('/shared/stream/{encodedId}.{format?}', [\App\Http\Controllers\SharedStreamController::class, 'streamChannel'])
    ->name('shared.stream.channel');

/*
 * API routes
 */

// API routes (for authenticated users only)
Route::group(['middleware' => ['auth:sanctum']], function () {
    // Get the authenticated user
    Route::group(['prefix' => 'user'], function () {
        Route::get('playlists', [\App\Http\Controllers\UserController::class, 'playlists'])
            ->name('api.user.playlists');
        Route::get('epgs', [\App\Http\Controllers\UserController::class, 'epgs'])
            ->name('api.user.epgs');
    });
});

// Playlist API routes
Route::group(['prefix' => 'playlist'], function () {
    Route::get('{uuid}/sync', [\App\Http\Controllers\PlaylistController::class, 'refreshPlaylist'])
        ->name('api.playlist.sync');
});

// EPG API routes
Route::group(['prefix' => 'epg'], function () {
    Route::get('{uuid}/sync', [\App\Http\Controllers\EpgController::class, 'refreshEpg'])
        ->name('api.epg.sync');
});

/*
 * Xtream API endpoints at root
 */
// Main Xtream API endpoint at /player_api.php and /get.php
Route::get('/player_api.php', [XtreamApiController::class, 'handle'])->name('xtream.api.player');
Route::get('/get.php', [XtreamApiController::class, 'handle'])->name('xtream.api.get');
Route::get('/xmltv.php', [XtreamApiController::class, 'epg'])->name('xtream.api.epg');

// Stream endpoints
Route::get('/live/{username}/{password}/{streamId}.{format?}', [App\Http\Controllers\XtreamStreamController::class, 'handleLive'])
    ->name('xtream.stream.live.root');
Route::get('/movie/{username}/{password}/{streamId}.{format?}', [App\Http\Controllers\XtreamStreamController::class, 'handleVod'])
    ->name('xtream.stream.vod.root');
Route::get('/series/{username}/{password}/{streamId}.{format?}', [App\Http\Controllers\XtreamStreamController::class, 'handleSeries'])
    ->name('xtream.stream.series.root');

// Timeshift endpoints
Route::get('/timeshift/{username}/{password}/{duration}/{date}/{streamId}.{format}', [App\Http\Controllers\XtreamStreamController::class, 'handleTimeshift'])
    ->name('xtream.stream.timeshift.root');

// Add this route for the image proxy
Route::get('/schedules-direct/{epg}/image/{imageHash}', [
    \App\Http\Controllers\SchedulesDirectImageProxyController::class,
    'proxyImage',
])->name('schedules-direct.image.proxy');
