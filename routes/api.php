<?php

use App\Http\Controllers\Api\DispatcharrController;
use App\Http\Controllers\Api\EpgApiController;
use App\Http\Controllers\Api\M3uProxyApiController;
use Illuminate\Support\Facades\Route;

/*
 * EPG API routes
 */

Route::group(['prefix' => 'epg'], function () {
    Route::get('{uuid}/data', [EpgApiController::class, 'getData'])
        ->name('api.epg.data');
    Route::get('playlist/{uuid}/data', [EpgApiController::class, 'getDataForPlaylist'])
        ->name('api.epg.playlist.data');
});

/*
 * m3u-proxy API routes
 */
Route::middleware(['proxy.throttle'])->prefix('m3u-proxy')->group(function () {
    // Failover resolver - called by m3u-proxy to validate failover URLs
    Route::post('failover-resolver', [M3uProxyApiController::class, 'resolveFailoverUrl'])
        ->name('m3u-proxy.failover-resolver')
        ->withoutMiddleware('proxy.throttle');

    // Player stream stop - called via sendBeacon when in-app player is closed
    Route::post('player-stream/stop', [M3uProxyApiController::class, 'stopPlayerStream'])
        ->name('m3u-proxy.player-stream.stop')
        ->withoutMiddleware('proxy.throttle');

    // Main proxy routes
    Route::post('webhooks', [M3uProxyApiController::class, 'handleWebhook'])
        ->name('m3u-proxy.webhook');

    // Network broadcast callback - called by proxy when broadcast FFmpeg process exits
    Route::post('broadcast/callback', [M3uProxyApiController::class, 'handleBroadcastCallback'])
        ->name('m3u-proxy.broadcast.callback')
        ->withoutMiddleware('proxy.throttle');
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
