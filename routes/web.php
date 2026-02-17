<?php

use App\Http\Controllers\AssetPreviewController;
use App\Http\Controllers\EpgFileController;
use App\Http\Controllers\EpgGenerateController;
use App\Http\Controllers\LogoProxyController;
use App\Http\Controllers\LogoRepositoryController;
use App\Http\Controllers\NetworkEpgController;
use App\Http\Controllers\NetworkPlaylistController;
use App\Http\Controllers\NetworkStreamController;
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

Route::get('/assets/{asset}/preview', AssetPreviewController::class)
    ->middleware(['auth'])
    ->name('assets.preview');

Route::get('/logo-repository', [LogoRepositoryController::class, 'index'])
    ->name('logo.repository');
Route::get('/logo-repository/index.json', [LogoRepositoryController::class, 'index'])
    ->name('logo.repository.index');
Route::get('/logo-repository/logos/{filename}', [LogoRepositoryController::class, 'show'])
    ->where('filename', '.*')
    ->name('logo.repository.file');

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
Route::get('/logo-proxy/{encodedUrl}/{filename?}', [LogoProxyController::class, 'serveLogo'])
    ->where('encodedUrl', '[A-Za-z0-9\-_=]+')
    ->where('filename', '.*')
    ->name('logo.proxy');

/*
 * Playlist/EPG output routes
 */

// Generate M3U playlist from the playlist configuration
Route::get('/{uuid}/playlist.m3u', PlaylistGenerateController::class)
    ->name('playlist.generate');

// Auth-aware HDHR routes (path-based auth to support clients that ignore query string auth)
Route::get('/{uuid}/hdhr/{username}/{password}/device.xml', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhr'])
    ->name('playlist.hdhr.auth_device');
Route::get('/{uuid}/hdhr/{username}/{password}', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrOverview'])
    ->name('playlist.hdhr.overview.auth');
Route::get('/{uuid}/hdhr/{username}/{password}/discover.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrDiscover'])
    ->name('playlist.hdhr.discover.auth');
Route::get('/{uuid}/hdhr/{username}/{password}/lineup.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrLineup'])
    ->name('playlist.hdhr.lineup.auth');
Route::get('/{uuid}/hdhr/{username}/{password}/lineup_status.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrLineupStatus'])
    ->name('playlist.hdhr.lineup_status.auth');

// Legacy/non-auth HDHR routes (keep for backwards compatibility and query-var auth)
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

// Network EPG routes
Route::get('/network/{network}/epg.xml', [NetworkEpgController::class, 'show'])
    ->name('network.epg');
Route::get('/network/{network}/epg.xml.gz', [NetworkEpgController::class, 'compressed'])
    ->name('network.epg.compressed');

// Network stream routes
Route::get('/network/{network}/stream.{container}', [NetworkStreamController::class, 'stream'])
    ->name('network.stream')
    ->where('container', 'ts|mp4|mkv|avi|webm|mov');
Route::get('/network/{network}/now-playing', [NetworkStreamController::class, 'nowPlaying'])
    ->name('network.now-playing');
Route::get('/network/{network}/playlist.m3u', [NetworkPlaylistController::class, 'single'])
    ->name('network.playlist');

// Networks (plural) playlist routes - all user's networks
Route::get('/networks/{user}/playlist.m3u', NetworkPlaylistController::class)
    ->name('networks.playlist');
Route::get('/networks/{user}/epg.xml', [NetworkPlaylistController::class, 'epg'])
    ->name('networks.epg');

// Media Integration Networks playlist - networks for a specific media server integration
Route::get('/media-integration/{integration}/networks/playlist.m3u', [NetworkPlaylistController::class, 'forIntegration'])
    ->name('media-integration.networks.playlist');
Route::get('/media-integration/{integration}/networks/epg.xml', [NetworkPlaylistController::class, 'epgForIntegration'])
    ->name('media-integration.networks.epg');

// Network HLS broadcast routes (for continuous live broadcasting)
Route::get('/network/{network}/live.m3u8', [\App\Http\Controllers\NetworkHlsController::class, 'playlist'])
    ->name('network.hls.playlist');
Route::get('/network/{network}/{segment}.ts', [\App\Http\Controllers\NetworkHlsController::class, 'segment'])
    ->name('network.hls.segment')
    ->where('segment', 'live[0-9]+');

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
 * Proxy routes (redirects to m3u-proxy API)
 */

// Deprecated route for episode - redirect to m3u-proxy API
Route::get('/shared/stream/e/{encodedId}.{format?}', function (string $encodedId) {
    $id = base64_decode($encodedId);

    return redirect()->route('m3u-proxy.episode', ['id' => $id]);
})->name('shared.stream.episode');

// Deprecated route for channel - redirect to m3u-proxy API
Route::get('/shared/stream/{encodedId}.{format?}', function (string $encodedId) {
    $id = base64_decode($encodedId);

    return redirect()->route('m3u-proxy.channel', ['id' => $id]);
})->name('shared.stream.channel');

Route::get('/player/popout', [\App\Http\Controllers\PlayerController::class, 'popout'])
    ->name('player.popout');

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

    // Channel API routes
    Route::get('channel/get', [\App\Http\Controllers\ChannelController::class, 'index'])
        ->name('api.channels.index');
    Route::get('channel/{id}', [\App\Http\Controllers\ChannelController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('api.channels.show');
    Route::patch('channel/{id}', [\App\Http\Controllers\ChannelController::class, 'update'])
        ->name('api.channels.update');
    Route::post('channel/toggle', [\App\Http\Controllers\ChannelController::class, 'toggle'])
        ->name('api.channels.toggle');
    Route::post('channel/bulk-update', [\App\Http\Controllers\ChannelController::class, 'bulkUpdate'])
        ->name('api.channels.bulk-update');
    Route::get('channel/{id}/health', [\App\Http\Controllers\ChannelController::class, 'healthcheck'])
        ->name('api.channels.healthcheck');
    Route::get('channel/playlist/{uuid}/health/{search}', [\App\Http\Controllers\ChannelController::class, 'healthcheckByPlaylist'])
        ->name('api.channels.healthcheck.search');
    Route::get('channel/{id}/availability', [\App\Http\Controllers\ChannelController::class, 'checkAvailability'])
        ->where('id', '[0-9]+')
        ->name('api.channels.availability');
    Route::post('channel/check-availability', [\App\Http\Controllers\ChannelController::class, 'batchCheckAvailability'])
        ->name('api.channels.batch-availability');
    Route::post('channel/{id}/stability-test', [\App\Http\Controllers\ChannelController::class, 'stabilityTest'])
        ->where('id', '[0-9]+')
        ->name('api.channels.stability-test');

    // Group API routes
    Route::get('groups/get', [\App\Http\Controllers\GroupController::class, 'index'])
        ->name('api.groups.index');
    Route::get('groups/{id}', [\App\Http\Controllers\GroupController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('api.groups.show');

    // Playlist API routes (authenticated)
    Route::get('playlist/{uuid}/stats', [\App\Http\Controllers\PlaylistController::class, 'stats'])
        ->name('api.playlist.stats');

    // Proxy API routes
    Route::get('proxy/status', [\App\Http\Controllers\ProxyController::class, 'status'])
        ->name('api.proxy.status');
    Route::get('proxy/streams/active', [\App\Http\Controllers\ProxyController::class, 'streams'])
        ->name('api.proxy.streams');
});

// Playlist API routes (public with UUID auth)
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
Route::get('/timeshift/{username}/{password}/{duration}/{date}/{streamId}.{format?}', [App\Http\Controllers\XtreamStreamController::class, 'handleTimeshift'])
    ->name('xtream.stream.timeshift.root');

// (Fallback) direct stream access (without /live/ or /movie/ prefix)
Route::get('/{username}/{password}/{streamId}.{format?}', [App\Http\Controllers\XtreamStreamController::class, 'handleDirect'])
    ->name('xtream.stream.direct');

// Add this route for the image proxy
Route::get('/schedules-direct/{epg}/image/{imageHash}', [
    \App\Http\Controllers\SchedulesDirectImageProxyController::class,
    'proxyImage',
])->name('schedules-direct.image.proxy');

/*
 * Media Server (Emby/Jellyfin) proxy routes
 * These hide the API key from external clients
 */
Route::get('/media-server/{integrationId}/image/{itemId}/{imageType?}', [
    \App\Http\Controllers\MediaServerProxyController::class,
    'proxyImage',
])->name('media-server.image.proxy');

Route::get('/media-server/{integrationId}/stream/{itemId}.{container}', [
    \App\Http\Controllers\MediaServerProxyController::class,
    'proxyStream',
])->name('media-server.stream.proxy');
