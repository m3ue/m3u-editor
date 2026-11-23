<?php

return [
    // Master switch for the shared per-channel rolling timeshift buffer.
    // Off by default so a fresh/upgraded install is completely unaffected until
    // an operator opts in. Clients also need their own timeshift setting on.
    'enabled' => env('TIMESHIFT_ENABLED', false),

    // Retained window length. With EPG the served playlist is additionally
    // trimmed to the current programme's start (still capped here).
    // Without EPG this is a plain rolling window.
    'window_seconds' => (int) env('TIMESHIFT_WINDOW_SECONDS', 10800),
    'max_window_seconds' => (int) env('TIMESHIFT_MAX_WINDOW_SECONDS', 10800),

    // Short segments so the first .ts lands quickly (~1s) for switch-on-rewind.
    'segment_seconds' => (int) env('TIMESHIFT_SEGMENT_SECONDS', 2),

    // Concurrent rolling buffers (one per channel). 0 = unlimited.
    'max_concurrent' => (int) env('TIMESHIFT_MAX_CONCURRENT', 20),

    // REAP QUICKLY ONCE NOBODY IS WATCHING.
    //
    // Each timeshift buffer holds an upstream/provider stream open, so
    // buffers that outlive the viewer exhaust the connection/tuner pool.
    // A long idle here is therefore actively harmful.
    //
    // The buffer is kept alive WHILE watched by the client's 2s
    // `timeshift_heartbeat` (which also reports window_seconds) plus normal
    // playlist fetches - not by a generous timeout. So: no viewer => no
    // touches => reap here. Viewer paused => heartbeat keeps touching => never
    // reaped. 10s tolerates ~5 missed heartbeats (network hiccup) while still
    // freeing the stream within seconds of the player closing.
    'idle_stop_seconds' => (int) env('TIMESHIFT_IDLE_STOP_SECONDS', 10),

    // Serve real HLS for .m3u8 timeshift requests even while the buffer is
    // still warming (the playlist controller waits briefly for the first
    // segments). Required for Apple AVPlayer and web hls.js, which cannot play
    // the raw MPEG-TS plain-live fallback. .ts requests keep the instant raw
    // path regardless of this flag.
    'hls_first_cold' => filter_var(env('TIMESHIFT_HLS_FIRST_COLD', true), FILTER_VALIDATE_BOOLEAN),

    // Safety wait when serving the timeshift playlist (normally instant —
    // resolvePlaybackUrl only redirects here once segments exist). Must stay
    // well under the player's HTTP read timeout: blocking the playlist request
    // to wait for a bigger window makes ExoPlayer abort with EOF/empty body.
    'startup_wait_seconds' => (int) env('TIMESHIFT_STARTUP_WAIT_SECONDS', 2),
    'startup_poll_ms' => (int) env('TIMESHIFT_STARTUP_POLL_MS', 100),
    'startup_min_segments' => (int) env('TIMESHIFT_STARTUP_MIN_SEGMENTS', 1),

    // Remux-only (stream copy) by default; set true to normalize live TS.
    'transcode' => filter_var(env('TIMESHIFT_TRANSCODE', false), FILTER_VALIDATE_BOOLEAN),
    // Only used when 'transcode' is true. ffmpeg scale value, e.g. '-2:480';
    // video_bitrate in kbps (the proxy appends 'k').
    'video_resolution' => env('TIMESHIFT_VIDEO_RESOLUTION'),
    'video_bitrate' => env('TIMESHIFT_VIDEO_BITRATE'),
];
