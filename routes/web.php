<?php

use App\Http\Controllers\EpgFileController;
use App\Http\Controllers\EpgGenerateController;
use App\Http\Controllers\PlaylistGenerateController;
use Illuminate\Support\Facades\Route;

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
    ->name('webhook.test.get');
Route::get('/webhook/test', \App\Http\Controllers\WebhookTestController::class)
    ->name('webhook.test.post');

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

// Stream an IPTV channel
Route::get('/stream/{id}/{format?}', \App\Http\Controllers\ChannelStreamController::class)->name('stream');

// DISABLED: Stream an IPTV channel with HLS
// @TODO: More testing and cleanup needed... process is not killed properly
// // Stream an IPTV channel with HLS
// // 1. Kick off HLS generation and redirect to the playlist
// Route::get('stream/{id}/hls', [\App\Http\Controllers\ChannelStreamController::class, 'startHls'])
//     ->name('stream.hls.start');

// // 2. Serve playlist
// Route::get('stream/{id}/hls/stream.m3u8', [\App\Http\Controllers\ChannelStreamController::class, 'servePlaylist'])
//     ->name('stream.hls.playlist');

// // 3. Serve segments (catch-all for any .ts file)
// Route::get('stream/{id}/hls/{segment}', [\App\Http\Controllers\ChannelStreamController::class, 'serveSegment'])
//     ->where('segment', 'segment_[0-9]{3}\.ts')
//     ->name('stream.hls.segment');


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
