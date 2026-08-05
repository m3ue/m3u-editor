<?php

return [
    /*
     * M3U Proxy Service Configuration
     */

    // Post Processing
    // Allow webhook post-processing to target private/reserved IP addresses.
    // Disabled by default (SSRF protection). Enable for self-hosted setups where
    // local services (e.g., Jellyfin, Emby) are addressed by a private IP.
    'allow_private_webhook_urls' => env('ALLOW_PRIVATE_WEBHOOK_URLS', false),

    // Master switch to completely disable the proxy integration for all users (including admins).
    // When false, no user can access proxy features, proxy tabs/nav are hidden,
    // and webhook registration is prevented from running.
    'proxy_integration_enabled' => env('M3U_PROXY_INTEGRATION_ENABLED', true),

    // If M3U_PROXY_ENABLED=false/null, uses external proxy service
    // If M3U_PROXY_ENABLED=true, uses embedded proxy via nginx reverse proxy
    'embedded_proxy_enabled' => env('M3U_PROXY_ENABLED', true), // true = embedded service, false/null = external service
    'external_proxy_enabled' => ! env('M3U_PROXY_ENABLED', false), // opposite of above for convenience

    'm3u_proxy_host' => env('M3U_PROXY_HOST', 'localhost'), // Host for proxy (embedded and external)
    'm3u_proxy_port' => env('M3U_PROXY_PORT', 8085), // Port for proxy (embedded and external)
    'm3u_proxy_token' => env('M3U_PROXY_TOKEN'), // API token for authenticating with the proxy service
    'm3u_proxy_public_url' => env('M3U_PROXY_PUBLIC_URL'), // Public URL for the proxy (auto-set in start-container)
    'm3u_resolver_url' => env('M3U_PROXY_FAILOVER_RESOLVER_URL', null), // Base URL for the editor that the proxy can use to resolve URLs if needed (for smart failover with capacity checks)

    // Callback routes the proxy calls back into the editor (failover-resolver, webhooks,
    // broadcast/callback, dvr/callback) reject every request when M3U_PROXY_TOKEN is unset,
    // since an unset token previously meant "accept all". Only set this for a trusted,
    // single-machine/local-network deployment where the proxy container isn't reachable
    // from outside — it re-enables the old accept-all behavior for those routes.
    'allow_unauthenticated_callbacks' => env('M3U_PROXY_ALLOW_UNAUTHENTICATED_CALLBACKS', false),

    // Logo Proxy Configuration
    'url_override' => env('PROXY_URL_OVERRIDE', null),
    'url_override_include_logos' => env('PROXY_URL_OVERRIDE_INCLUDE_LOGOS', default: null),

    // Media Server (Plex/Emby/Jellyfin/local/WebDAV) proxy URLs are signed but
    // non-expiring — they're stored on the Channel record and must stay valid
    // indefinitely (see MediaServerProxyController::generate*Url()). This version
    // is stamped into every generated URL and re-checked on every request; bumping
    // it (per-instance, via env) invalidates every previously generated URL at
    // once, without needing per-link expiry. The default is only bumped in code if
    // a future issue requires forcing invalidation for installs that never set
    // this explicitly.
    'media_server_url_version' => (int) env('MEDIA_SERVER_PROXY_URL_VERSION', 1),

    // On-demand network broadcasts keep running for this many seconds after
    // the last viewer request. A small overlap is added to absorb short
    // disconnects/reconnects from players.
    'broadcast_on_demand_disconnect_seconds' => (int) env('BROADCAST_ON_DEMAND_DISCONNECT_SECONDS', 120),
    'broadcast_on_demand_overlap_seconds' => (int) env('BROADCAST_ON_DEMAND_OVERLAP_SECONDS', 30),

    // When an on-demand network is cold-started by the first playlist request,
    // wait briefly for FFmpeg to generate live.m3u8 before returning 404/503.
    'broadcast_on_demand_startup_wait_seconds' => (int) env('BROADCAST_ON_DEMAND_STARTUP_WAIT_SECONDS', 8),
    'broadcast_on_demand_startup_poll_ms' => (int) env('BROADCAST_ON_DEMAND_STARTUP_POLL_MS', 400),
    'broadcast_on_demand_startup_min_segments' => (int) env('BROADCAST_ON_DEMAND_STARTUP_MIN_SEGMENTS', 3),

    // Grace period after a new on-demand start where idle-stop is skipped.
    // This allows initial player buffering and first segment pulls to occur.
    'broadcast_on_demand_startup_grace_seconds' => (int) env('BROADCAST_ON_DEMAND_STARTUP_GRACE_SECONDS', 30),
];
