<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// Stream an IPTV channel (HLS)
Route::group(['prefix' => 'stream'], function () {
    Route::get('{encodedId}/{playlist?}/playlist.m3u8', \App\Http\Controllers\ChannelHlsStreamController::class)
        ->where('id', '[A-Za-z0-9]+')
        ->name('stream.hls.playlist');

    // Serve segments (catch-all for any .ts file)
    Route::get('{channelId}/{segment}', [\App\Http\Controllers\ChannelHlsStreamController::class, 'serveSegment'])
        ->where('segment', '/^segment_\d{3}\.ts$/')
        ->name('stream.hls.segment');
});
