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

// Stream an IPTV channel (MPEGTS/MP4)
Route::get('/stream/{encodedId}/{format?}/{playlist?}', \App\Http\Controllers\ChannelStreamController::class)
    ->name('stream');


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
