<?php

return [
    'url_override' => env('PROXY_URL_OVERRIDE', null),
    'proxy_format' => env('PROXY_FORMAT', 'mpts'), // 'mpts' or 'hls'
    'ffmpeg_path' => env('PROXY_FFMPEG_PATH', null),
    'ffmpeg_additional_args' => env('PROXY_FFMPEG_ADDITIONAL_ARGS', ''),
    'ffmpeg_codec_video' => env('PROXY_FFMPEG_CODEC_VIDEO', null),
    'ffmpeg_codec_audio' => env('PROXY_FFMPEG_CODEC_AUDIO', null),
    'ffmpeg_codec_subtitles' => env('PROXY_FFMPEG_CODEC_SUBTITLES', null),
    
    /*
    |--------------------------------------------------------------------------
    | Shared Streaming Configuration (xTeVe-like proxy functionality)
    |--------------------------------------------------------------------------
    |
    | These settings control the shared streaming functionality that allows
    | multiple clients to share the same upstream stream, reducing server load.
    |
    */
    
    'shared_streaming' => [
        // Enable shared streaming functionality
        'enabled' => env('SHARED_STREAMING_ENABLED', true),
        
        // Maximum concurrent shared streams
        'max_concurrent_streams' => env('SHARED_MAX_CONCURRENT_STREAMS', 50),
        
        // Buffer configuration
        'buffer' => [
            // Default buffer size per stream (in segments for HLS, bytes for TS)
            'default_size' => env('SHARED_BUFFER_SIZE', 30),
            
            // Maximum buffer size per stream
            'max_size' => env('SHARED_BUFFER_MAX_SIZE', 100),
            
            // Buffer cleanup interval (seconds)
            'cleanup_interval' => env('SHARED_BUFFER_CLEANUP_INTERVAL', 300),
            
            // Maximum age of buffer segments (seconds)
            'max_age' => env('SHARED_BUFFER_MAX_AGE', 3600),
            
            // Number of segments to keep in buffer
            'segments' => env('SHARED_BUFFER_SEGMENTS', 10),
            
            // Segment retention time (seconds)
            'segment_retention' => env('SHARED_BUFFER_SEGMENT_RETENTION', 300),
        ],
        
        // Stream monitoring
        'monitoring' => [
            // Health check interval (seconds)
            'health_check_interval' => env('SHARED_HEALTH_CHECK_INTERVAL', 60),
            
            // Stream timeout (seconds) - streams without clients
            'stream_timeout' => env('SHARED_STREAM_TIMEOUT', 300),
            
            // Maximum allowed unhealthy duration (seconds)
            'max_unhealthy_duration' => env('SHARED_MAX_UNHEALTHY_DURATION', 600),
            
            // Client timeout (seconds) - for monitoring client activity
            'client_timeout' => env('SHARED_CLIENT_TIMEOUT', 30),
            
            // Bandwidth monitoring threshold (kbps)
            'bandwidth_threshold' => env('SHARED_BANDWIDTH_THRESHOLD', 50000),
            
            // Log status interval (seconds) - how often to log stream status
            'log_status_interval' => env('SHARED_LOG_STATUS_INTERVAL', 300),
        ],
        
        // Stream cleanup configuration
        'cleanup' => [
            // Grace period for clientless streams (seconds) before stopping them
            'clientless_grace_period' => env('SHARED_CLIENTLESS_GRACE_PERIOD', 15),
        ],
        
        // Client management
        'clients' => [
            // Maximum clients per stream
            'max_per_stream' => env('SHARED_MAX_CLIENTS_PER_STREAM', 100),
            
            // Client timeout (seconds) - inactive clients
            'timeout' => env('SHARED_CLIENT_TIMEOUT', 60),
            
            // Client heartbeat interval (seconds)
            'heartbeat_interval' => env('SHARED_CLIENT_HEARTBEAT_INTERVAL', 30),
        ],
        
        // Redis configuration for shared streaming
        'redis' => [
            // Redis key prefix for shared streaming data
            'prefix' => env('SHARED_REDIS_PREFIX', 'shared_stream:'),
            
            // Default TTL for Redis keys (seconds)
            'default_ttl' => env('SHARED_REDIS_TTL', 86400),
        ],
        
        // Storage paths
        'storage' => [
            // Base directory for shared stream buffers
            'buffer_path' => env('SHARED_BUFFER_PATH', 'shared_streams'),
            
            // Temporary files directory
            'temp_path' => env('SHARED_TEMP_PATH', 'shared_streams/temp'),
            
            // Maximum total disk usage for all buffers (bytes)
            'max_total_disk_usage' => env('SHARED_MAX_DISK_USAGE', 2147483648), // 2GB
        ],
    ],
];