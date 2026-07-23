<?php

use App\Http\Controllers\Api\DispatcharrController;
use App\Http\Controllers\Api\EpgApiController;
use App\Http\Controllers\Api\M3uProxyApiController;
use App\Http\Controllers\Api\TvApiController;
use Illuminate\Support\Facades\Route;

/*
 * EPG API routes
 */

/*
 * EPG API routes (authenticated - used by the in-app EPG viewer)
 */

Route::middleware(['throttle:60,1'])->prefix('epg')->group(function () {
    Route::get('{uuid}/data', [EpgApiController::class, 'getData'])
        ->name('api.epg.data');
    Route::get('playlist/{uuid}/data', [EpgApiController::class, 'getDataForPlaylist'])
        ->name('api.epg.playlist.data');
    Route::get('playlist/{uuid}/groups', [EpgApiController::class, 'getGroupsForPlaylist'])
        ->name('api.epg.playlist.groups');
});

/*
 * m3u-proxy API routes
 */
Route::prefix('m3u-proxy')->group(function () {
    // Failover resolver - called by m3u-proxy to validate failover URLs
    Route::post('failover-resolver', [M3uProxyApiController::class, 'resolveFailoverUrl'])
        ->name('m3u-proxy.failover-resolver');

    // Player stream stop - called via sendBeacon when in-app player is closed
    Route::post('player-stream/stop', [M3uProxyApiController::class, 'stopPlayerStream'])
        ->name('m3u-proxy.player-stream.stop');

    // Proxy webhook endpoint - called by m3u-proxy to notify of events
    // Relies on `m3u-proxy:register-webhook` to register this endpoint with the proxy
    Route::post('webhooks', [M3uProxyApiController::class, 'handleWebhook'])
        ->name('m3u-proxy.webhook');

    // Network broadcast callback - called by proxy when broadcast FFmpeg process exits
    Route::post('broadcast/callback', [M3uProxyApiController::class, 'handleBroadcastCallback'])
        ->name('m3u-proxy.broadcast.callback');
});

/*
 * Dispatcharr-compatible API routes (used by emby-xtream plugin)
 */
Route::prefix('accounts')->group(function () {
    Route::post('token', [DispatcharrController::class, 'login'])
        ->name('dispatcharr.token');
    Route::post('token/refresh', [DispatcharrController::class, 'refresh'])
        ->name('dispatcharr.token.refresh');
});

Route::prefix('channels')->middleware('dispatcharr.auth')->group(function () {
    Route::get('profiles', [DispatcharrController::class, 'profiles'])
        ->name('dispatcharr.profiles');
    Route::get('channels', [DispatcharrController::class, 'channels'])
        ->name('dispatcharr.channels');
});

Route::prefix('vod')->middleware('dispatcharr.auth')->group(function () {
    Route::get('movies/{streamId}', [DispatcharrController::class, 'vodMovieDetail'])
        ->name('dispatcharr.vod.movie.detail')
        ->whereNumber('streamId');
    Route::get('movies/{streamId}/providers', [DispatcharrController::class, 'vodMovieProviders'])
        ->name('dispatcharr.vod.movie.providers')
        ->whereNumber('streamId');
});

/*
 * TV app API routes (authenticated via Xtream credentials in URL path — no Sanctum)
 */
Route::prefix('tv/{username}/{password}')->middleware('throttle:60,1')->group(function () {
    Route::get('notifications', [TvApiController::class, 'notifications'])
        ->name('tv.notifications');
    Route::post('notifications/{id}/read', [TvApiController::class, 'markRead'])
        ->name('tv.notifications.read');
    Route::post('broadcasting/auth', [TvApiController::class, 'broadcastingAuth'])
        ->name('tv.broadcasting.auth');
    Route::post('push/subscribe', [TvApiController::class, 'registerPushToken'])
        ->name('tv.push.subscribe');
});
