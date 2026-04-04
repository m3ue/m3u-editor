<?php

use App\Http\Controllers\AssetPreviewController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\EpgController;
use App\Http\Controllers\EpgFileController;
use App\Http\Controllers\EpgGenerateController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LogoProxyController;
use App\Http\Controllers\LogoRepositoryController;
use App\Http\Controllers\MediaServerProxyController;
use App\Http\Controllers\NetworkEpgController;
use App\Http\Controllers\NetworkHlsController;
use App\Http\Controllers\NetworkPlaylistController;
use App\Http\Controllers\NetworkStreamController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\PlaylistGenerateController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\SchedulesDirectImageProxyController;
use App\Http\Controllers\ShortURLController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WatchProgressController;
use App\Http\Controllers\WebhookTestController;
use App\Http\Controllers\XtreamApiController;
use App\Http\Controllers\XtreamStreamController;
use App\Services\ExternalIpService;
use Illuminate\Support\Facades\Route;

// OIDC SSO authentication
Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])->name('auth.oidc.redirect');
Route::get('/auth/oidc/callback', [OidcController::class, 'callback'])->name('auth.oidc.callback');

// In-app watch progress tracking (admin panel + guest panel)
Route::get('/api/watch-progress', [WatchProgressController::class, 'fetch'])->name('watch-progress.fetch');
Route::post('/api/watch-progress', [WatchProgressController::class, 'update'])->name('watch-progress.update');

// External IP refresh route for admin panel
Route::post('/admin/refresh-external-ip', function (ExternalIpService $ipService) {
    $ipService->clearCache();
    $ip = $ipService->getExternalIp();

    return response()->json(['success' => true, 'external_ip' => $ip]);
})->middleware(['auth']);

/*
 * Short URL forwarding route
 * This allows short URLs to forward to a target URL while preserving any additional path segments (e.g. for device.xml forwarding)
 */
Route::get('/s/{shortUrlKey}/{path?}', ShortURLController::class)
    ->where('shortUrlKey', '[A-Za-z0-9\-]+')
    ->where('path', '.*')
    ->name('shorturl.forward');

/*
 * In-app player route
 */
Route::get('/player/popout', [PlayerController::class, 'popout'])
    ->name('player.popout');

/*
 * DEBUG routes
 */

// Test webhook endpoint
Route::post('/webhook/test', WebhookTestController::class)
    ->name('webhook.test.post');
Route::get('/webhook/test', WebhookTestController::class)
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
 * Logo proxy route - cache and serve remote logos
 */
Route::get('/logo-proxy/{encodedUrl}/{filename?}', [LogoProxyController::class, 'serveLogo'])
    ->where('encodedUrl', '[A-Za-z0-9\-_=]+')
    ->where('filename', '.*')
    ->name('logo.proxy');

/**
 * Asset routes
 */
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

/*
 * Playlist/EPG output routes
 */

// Generate M3U playlist from the playlist configuration
Route::get('/{uuid}/playlist.m3u', PlaylistGenerateController::class)
    ->name('playlist.generate');

// Auth-aware HDHR routes (path-based auth to support clients that ignore query string auth)
Route::get('/{uuid}/hdhr/{username}/{password}/device.xml', [PlaylistGenerateController::class, 'hdhr'])
    ->name('playlist.hdhr.auth_device');
Route::get('/{uuid}/hdhr/{username}/{password}', [PlaylistGenerateController::class, 'hdhrOverview'])
    ->name('playlist.hdhr.overview.auth');
Route::get('/{uuid}/hdhr/{username}/{password}/discover.json', [PlaylistGenerateController::class, 'hdhrDiscover'])
    ->name('playlist.hdhr.discover.auth');
Route::get('/{uuid}/hdhr/{username}/{password}/lineup.json', [PlaylistGenerateController::class, 'hdhrLineup'])
    ->name('playlist.hdhr.lineup.auth');
Route::get('/{uuid}/hdhr/{username}/{password}/lineup_status.json', [PlaylistGenerateController::class, 'hdhrLineupStatus'])
    ->name('playlist.hdhr.lineup_status.auth');

// Legacy/non-auth HDHR routes (keep for backwards compatibility and query-var auth)
Route::get('/{uuid}/hdhr/device.xml', [PlaylistGenerateController::class, 'hdhr'])
    ->name('playlist.hdhr');
Route::get('/{uuid}/hdhr', [PlaylistGenerateController::class, 'hdhrOverview'])
    ->name('playlist.hdhr.overview');
Route::get('/{uuid}/hdhr/discover.json', [PlaylistGenerateController::class, 'hdhrDiscover'])
    ->name('playlist.hdhr.discover');
Route::get('/{uuid}/hdhr/lineup.json', [PlaylistGenerateController::class, 'hdhrLineup'])
    ->name('playlist.hdhr.lineup');
Route::get('/{uuid}/hdhr/lineup_status.json', [PlaylistGenerateController::class, 'hdhrLineupStatus'])
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
Route::get('/network/{network}/live.m3u8', [NetworkHlsController::class, 'playlist'])
    ->name('network.hls.playlist');
Route::get('/network/{network}/{segment}.ts', [NetworkHlsController::class, 'segment'])
    ->name('network.hls.segment')
    ->where('segment', 'live[0-9]+');

/*
 * API routes
 */

// API routes (for authenticated users only)
Route::group(['middleware' => ['auth:sanctum']], function () {
    // Get the authenticated user
    Route::group(['prefix' => 'user'], function () {
        Route::get('playlists', [UserController::class, 'playlists'])
            ->name('api.user.playlists');
        Route::get('epgs', [UserController::class, 'epgs'])
            ->name('api.user.epgs');
    });

    // Channel API routes
    Route::get('channel/get', [ChannelController::class, 'index'])
        ->name('api.channels.index');
    Route::get('channel/{id}', [ChannelController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('api.channels.show');
    Route::patch('channel/{id}', [ChannelController::class, 'update'])
        ->name('api.channels.update');
    Route::post('channel/toggle', [ChannelController::class, 'toggle'])
        ->name('api.channels.toggle');
    Route::post('channel/bulk-update', [ChannelController::class, 'bulkUpdate'])
        ->name('api.channels.bulk-update');
    Route::get('channel/{id}/health', [ChannelController::class, 'healthcheck'])
        ->name('api.channels.healthcheck');
    Route::get('channel/playlist/{uuid}/health/{search}', [ChannelController::class, 'healthcheckByPlaylist'])
        ->name('api.channels.healthcheck.search');
    Route::get('channel/{id}/availability', [ChannelController::class, 'checkAvailability'])
        ->where('id', '[0-9]+')
        ->name('api.channels.availability');
    Route::post('channel/check-availability', [ChannelController::class, 'batchCheckAvailability'])
        ->name('api.channels.batch-availability');
    Route::post('channel/{id}/stability-test', [ChannelController::class, 'stabilityTest'])
        ->where('id', '[0-9]+')
        ->name('api.channels.stability-test');

    // Channel failover API routes
    Route::post('channel/{id}/failovers', [ChannelController::class, 'setFailovers'])
        ->where('id', '[0-9]+')
        ->name('api.channels.failovers.set');
    Route::delete('channel/{id}/failovers', [ChannelController::class, 'clearFailovers'])
        ->where('id', '[0-9]+')
        ->name('api.channels.failovers.clear');
    Route::post('channel/bulk-set-failovers', [ChannelController::class, 'bulkSetFailovers'])
        ->name('api.channels.failovers.bulk-set');

    // Group API routes
    Route::group(['prefix' => 'group'], function () {
        Route::post('/', [GroupController::class, 'store'])
            ->name('api.groups.store');
        Route::patch('{id}', [GroupController::class, 'update'])
            ->where('id', '[0-9]+')
            ->name('api.groups.update');
        Route::post('{id}/move-channels', [GroupController::class, 'moveChannels'])
            ->where('id', '[0-9]+')
            ->name('api.groups.move-channels');
        Route::delete('{id}', [GroupController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->name('api.groups.destroy');
        Route::get('get', [GroupController::class, 'index'])
            ->name('api.groups.index');
        Route::get('{id}', [GroupController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('api.groups.show');
    });

    // Playlist API routes (authenticated)
    Route::get('playlist/{uuid}/stats', [PlaylistController::class, 'stats'])
        ->name('api.playlist.stats');
    Route::post('playlist/{uuid}/merge-channels', [PlaylistController::class, 'mergeChannels'])
        ->name('api.playlist.merge-channels');

    // Proxy API routes
    Route::get('proxy/status', [ProxyController::class, 'status'])
        ->name('api.proxy.status');
    Route::get('proxy/streams/active', [ProxyController::class, 'streams'])
        ->name('api.proxy.streams');
});

// Playlist API routes (public with UUID auth - rate limited to prevent DoS/queue flooding)
Route::middleware(['throttle:5,1'])->prefix('playlist')->group(function () {
    Route::get('{uuid}/sync', [PlaylistController::class, 'refreshPlaylist'])
        ->name('api.playlist.sync');
});

// EPG API routes (rate limited to prevent DoS/queue flooding)
Route::middleware(['throttle:5,1'])->prefix('epg')->group(function () {
    Route::get('{uuid}/sync', [EpgController::class, 'refreshEpg'])
        ->name('api.epg.sync');
});

/*
 * Xtream API endpoints at root
 */

// Main Xtream API endpoint at /player_api.php and /get.php
Route::match(['get', 'post'], '/player_api.php', [XtreamApiController::class, 'handle'])->name('xtream.api.player');
Route::match(['get', 'post'], '/get.php', [XtreamApiController::class, 'handle'])->name('xtream.api.get');
Route::get('/xmltv.php', [XtreamApiController::class, 'epg'])->name('xtream.api.epg');

// Stream endpoints
Route::get('/live/{username}/{password}/{streamId}.{format?}', [XtreamStreamController::class, 'handleLive'])
    ->name('xtream.stream.live.root');
Route::get('/movie/{username}/{password}/{streamId}.{format?}', [XtreamStreamController::class, 'handleVod'])
    ->name('xtream.stream.vod.root');
Route::get('/series/{username}/{password}/{streamId}.{format?}', [XtreamStreamController::class, 'handleSeries'])
    ->name('xtream.stream.series.root');

// Timeshift endpoints
Route::get('/timeshift/{username}/{password}/{duration}/{date}/{streamId}.{format?}', [XtreamStreamController::class, 'handleTimeshift'])
    ->name('xtream.stream.timeshift.root');

// (Fallback) direct stream access (without /live/ or /movie/ prefix)
Route::get('/{username}/{password}/{streamId}.{format?}', [XtreamStreamController::class, 'handleDirect'])
    ->name('xtream.stream.direct');

// Add this route for the image proxy
Route::get('/schedules-direct/{epg}/image/{imageHash}', [
    SchedulesDirectImageProxyController::class,
    'proxyImage',
])->name('schedules-direct.image.proxy');

/*
 * Media Server (Emby/Jellyfin) proxy routes
 * These hide the API key from external clients
 */
Route::get('/media-server/{integrationId}/image/{itemId}/{imageType?}', [
    MediaServerProxyController::class,
    'proxyImage',
])->name('media-server.image.proxy');

Route::get('/media-server/{integrationId}/stream/{itemId}.{container}', [
    MediaServerProxyController::class,
    'proxyStream',
])->name('media-server.stream.proxy');

/*
 * Local Media streaming routes
 * Streams local video files mounted to the container
 */
Route::get('/local-media/{integration}/stream/{item}', [
    MediaServerProxyController::class,
    'streamLocalMedia',
])->name('local-media.stream');

/*
 * WebDAV Media streaming routes
 * Proxies video files from a WebDAV server
 */
Route::get('/webdav-media/{integration}/stream/{item}', [
    MediaServerProxyController::class,
    'streamWebDavMedia',
])->name('webdav-media.stream');
