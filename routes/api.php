<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API routes
Route::group(['middleware' => ['auth:sanctum']], function () {

    // Get the authenticated user
    Route::get('/user', function (Request $request) {
        return $request->user()->only('name');
    });

    // ...

});
