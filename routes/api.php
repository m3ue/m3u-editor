<?php

use Illuminate\Support\Facades\Route;

/*
 * Proxy routes
 */

// Stream an IPTV url
Route::group(['prefix' => 'stream'], function () {
    // Stream an IPTV episode (HLS)
    Route::get('e/{encodedId}/playlist.m3u8', [\App\Http\Controllers\HlsStreamController::class, 'serveEpisodePlaylist'])
        ->name('stream.hls.episode');

    // Serve espisode segments (catch-all for any .ts file)
    Route::get('e/{episodeId}/{segment}', [\App\Http\Controllers\HlsStreamController::class, 'serveEpisodeSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.episode.segment');

    // Stream an IPTV channel (HLS)
    Route::get('{encodedId}/playlist.m3u8',[\App\Http\Controllers\HlsStreamController::class, 'serveChannelPlaylist'])
        ->name('stream.hls.playlist');

    // Serve channel segments (catch-all for any .ts file)
    Route::get('{channelId}/{segment}', [\App\Http\Controllers\HlsStreamController::class, 'serveChannelSegment'])
        ->where('segment', 'segment_[0-9]{3}\.ts')
        ->name('stream.hls.segment');
    
    // Test stream endpoint (TS only)
    Route::get('test/{timeout}.ts', [\App\Http\Controllers\StreamTestController::class, 'testStream'])
        ->where('timeout', '[0-9]+')
        ->name('stream.test');
});

// Shared streaming API routes (xTeVe-like proxy functionality)
Route::group(['prefix' => 'shared'], function () {
    // HLS segments for shared streams
    Route::get('hls/{type}/{encodedId}/{segment}', [\App\Http\Controllers\SharedStreamController::class, 'serveHLSSegment'])
        ->where('segment', '.*\.ts')
        ->name('shared.stream.hls.segment');
    
    // Stream statistics and management
    Route::get('stats', [\App\Http\Controllers\SharedStreamController::class, 'getStreamStats'])
        ->name('shared.stream.stats');
    
    Route::delete('stream/{streamKey}', [\App\Http\Controllers\SharedStreamController::class, 'stopStream'])
        ->name('shared.stream.stop');
    
    // Test streaming
    Route::post('test', [\App\Http\Controllers\SharedStreamController::class, 'testStream'])
        ->name('shared.stream.test');
});

// Enhanced API routes for dashboard and monitoring
Route::group(['prefix' => 'monitor'], function () {
    // Core streaming statistics
    Route::get('stats', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getStats'])
        ->name('api.monitor.stats');
    
    // Real-time metrics for dashboard widgets
    Route::get('realtime', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getRealTimeMetrics'])
        ->name('api.monitor.realtime');
    
    // Dashboard data (comprehensive analytics)
    Route::get('dashboard', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getDashboardData'])
        ->name('api.monitor.dashboard');
    
    // Performance history
    Route::get('performance', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getPerformanceHistory'])
        ->name('api.monitor.performance');
    
    // System alerts
    Route::get('alerts', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getAlerts'])
        ->name('api.monitor.alerts');
    
    // System health check
    Route::get('health', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getHealth'])
        ->name('api.monitor.health');
    
    // Stream management
    Route::get('streams', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'getActiveStreams'])
        ->name('api.monitor.streams');
    
    Route::post('streams/test', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'testStream'])
        ->name('api.monitor.test');
    
    Route::delete('streams/{streamId}', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'stopStream'])
        ->name('api.monitor.stop');
    
    Route::post('cleanup', [\App\Http\Controllers\Api\SharedStreamApiController::class, 'cleanup'])
        ->name('api.monitor.cleanup');
});

/*
 * EPG API routes
 */
Route::group(['prefix' => 'epg'], function () {
    Route::get('{uuid}/data', [\App\Http\Controllers\Api\EpgApiController::class, 'getData'])
        ->name('api.epg.data');
});
