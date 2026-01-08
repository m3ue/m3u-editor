<?php

return [
    /*
     * M3U Proxy Service Configuration
     */

    // If M3U_PROXY_ENABLED=false/null, uses external proxy service
    // If M3U_PROXY_ENABLED=true, uses embedded proxy via nginx reverse proxy
    'embedded_proxy_enabled' => env('M3U_PROXY_ENABLED', true), // true = embedded service, false/null = external service
    'external_proxy_enabled' => ! env('M3U_PROXY_ENABLED', false), // opposite of above for convenience

    'm3u_proxy_host' => env('M3U_PROXY_HOST', 'localhost'), // Host for proxy (embedded and external)
    'm3u_proxy_port' => env('M3U_PROXY_PORT', 8085), // Port for proxy (embedded and external)
    'm3u_proxy_token' => env('M3U_PROXY_TOKEN'), // API token for authenticating with the proxy service
    'm3u_proxy_public_url' => env('M3U_PROXY_PUBLIC_URL'), // Public URL for the proxy (auto-set in start-container)
    'resolver_url' => env('M3U_PROXY_FAILOVER_RESOLVER_URL', null), // Base URL for the editor that the proxy can use to resolve URLs if needed (for smart failover with capacity checks)

    // Logo Proxy Configuration
    'url_override' => env('PROXY_URL_OVERRIDE', null),
    'url_override_include_logos' => env('PROXY_URL_OVERRIDE_INCLUDE_LOGOS', default: null),
];
