<?php

use Illuminate\Support\Facades\Route;

/*
 * EPG API routes
 */

Route::group(['prefix' => 'epg'], function () {
    Route::get('{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getData'])
        ->name('api.epg.data');
    Route::get('playlist/{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getDataForPlaylist'])
        ->name('api.epg.playlist.data');
});

/*
 * m3u-proxy API routes
 */
Route::middleware(['proxy.throttle'])->prefix('m3u-proxy')->group(function () {
    // Failover resolver - called by m3u-proxy to validate failover URLs
    Route::post('failover-resolver', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'resolveFailoverUrl'])
        ->name('m3u-proxy.failover-resolver')
        ->withoutMiddleware('proxy.throttle');

    // Player preview routes
    Route::get('channel/{id}/player/{uuid?}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channelPlayer'])
        ->name('m3u-proxy.channel.player');
    Route::get('episode/{id}/player/{uuid?}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episodePlayer'])
        ->name('m3u-proxy.episode.player');

    // Main proxy routes
    Route::post('webhooks', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'handleWebhook'])
        ->name('m3u-proxy.webhook');

    // Network broadcast callback - called by proxy when broadcast FFmpeg process exits
    Route::post('broadcast/callback', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'handleBroadcastCallback'])
        ->name('m3u-proxy.broadcast.callback')
        ->withoutMiddleware('proxy.throttle');

    // Content access routes
    Route::get('channel/{id}/{uuid?}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'channel'])
        ->name('m3u-proxy.channel');
    Route::get('episode/{id}/{uuid?}', [\App\Http\Controllers\Api\M3uProxyApiController::class, 'episode'])
        ->name('m3u-proxy.episode');
});
