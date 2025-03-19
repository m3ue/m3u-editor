<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API routes
Route::group(['middleware' => ['auth:sanctum']], function () {

    // API v1
    Route::group(['prefix' => 'v1'], function () {

        // Get the authenticated user
        Route::group(['prefix' => 'user'], function () {
            Route::get('whoami', [\App\Http\Controllers\UserController::class, 'user'])
                ->name('api.user.whoami');
        });

        // Sync endpoints
        Route::group(['prefix' => 'playlist'], function () {
            Route::post('{playlist}/sync', [\App\Http\Controllers\PlaylistController::class, 'refreshPlaylist'])
                ->name('api.playlist.sync');
        });

        Route::group(['prefix' => 'epg'], function () {
            Route::post('{epg}/sync', [\App\Http\Controllers\EpgController::class, 'refreshEpg'])
                ->name('api.epg.sync');
        });

        // ...

    });

    // ...
});
