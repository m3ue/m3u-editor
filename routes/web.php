<?php

use App\Http\Controllers\EpgFileController;
use App\Http\Controllers\EpgGenerateController;
use App\Http\Controllers\PlaylistGenerateController;
use Illuminate\Support\Facades\Route;

// Generate M3U playlist from the playlist configuration
Route::get('/{uuid}/playlist.m3u', PlaylistGenerateController::class)
    ->name('playlist.generate');

Route::get('/{uuid}/hdhr/device.xml', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhr'])
    ->name('playlist.hdhr');
Route::get('/{uuid}/hdhr/discover.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrDiscover'])
    ->name('playlist.hdhr.discover');
Route::get('/{uuid}/hdhr/lineup.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrLineup'])
    ->name('playlist.hdhr.lineup');
Route::get('/{uuid}/hdhr/lineup_status.json', [\App\Http\Controllers\PlaylistGenerateController::class, 'hdhrLineupStatus'])
    ->name('playlist.hdhr.lineup_status');

// Generate EPG playlist from the playlist configuration
Route::get('/{uuid}/epg.xml', EpgGenerateController::class)
    ->name('epg.generate');

// Serve the EPG file
Route::get('epgs/{uuid}/epg.xml', EpgFileController::class)
    ->name('epg.file');

// If local env, show PHP info screen
Route::get('/phpinfo', function () {
    if (app()->environment('local')) {
        phpinfo();
    } else {
        abort(404);
    }
});

// Stream an IPTV channel
Route::get('/stream/{id}', \App\Http\Controllers\ChannelStreamController::class)->name('stream');
Route::get('/stream/hls/{id}', [\App\Http\Controllers\ChannelStreamController::class, 'hls'])->name('stream.hls');
