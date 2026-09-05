<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Relay
    |--------------------------------------------------------------------------
    |
    | Community relay for mobile push notifications (m3u-push-relay). Config
    | only, intentionally not exposed in Settings -> only PUSH_RELAY_URL in
    | your own .env can change it. The relay takes no shared secret - it ships
    | in every publicly-distributed copy of this app, so nothing hardcoded
    | here is actually private anyway. The relay relies on rate limiting
    | instead (see m3u-push-relay's README).
    |
    */
    'push_relay' => [
        'url' => env('PUSH_RELAY_URL', 'https://push-relay.sparkison.dev'),
        // Registered device tokens that haven't checked in (via the mobile app's
        // push/subscribe call) within this many days are pruned by model:prune.
        'stale_days' => env('PUSH_RELAY_STALE_DAYS', 60),
        'status_monitor_url' => env('PUSH_RELAY_STATUS_MONITOR_URL', 'https://stats.uptimerobot.com/Zw57JMIDc2'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stremio Public Meta Addons
    |--------------------------------------------------------------------------
    |
    | Fallback metadata sources used when a user's AIOStreams instance has no
    | metadata addon configured and answers /meta requests with a 404
    | ("no addon to handle meta resource"). These are the same public addons
    | Stremio itself falls back to, keyed by the id scheme of the requested
    | item ("tt..." -> Cinemeta, "kitsu:..." -> Kitsu, "tmdb:..." -> TMDB).
    | Blank any entry in your .env to disable that particular fallback.
    |
    */
    'stremio' => [
        'meta_addons' => [
            'cinemeta' => env('STREMIO_CINEMETA_URL', 'https://v3-cinemeta.strem.io'),
            'kitsu' => env('STREMIO_KITSU_URL', 'https://anime-kitsu.strem.fun'),
            'tmdb' => env('STREMIO_TMDB_ADDON_URL', ''),
        ],
    ],

    'oidc' => [
        'enabled' => env('OIDC_ENABLED', false),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'base_url' => env('OIDC_ISSUER_URL'),
        'redirect' => '/auth/oidc/callback',
        'scopes' => implode(' ', array_filter(array_map('trim', explode(',', env('OIDC_SCOPES', 'openid,profile,email'))))),
        'auto_redirect' => env('OIDC_AUTO_REDIRECT', false),
        'auto_create_users' => env('OIDC_AUTO_CREATE_USERS', true),
        'button_label' => env('OIDC_BUTTON_LABEL', 'Login with SSO'),
        'hide_login_form' => env('OIDC_HIDE_LOGIN_FORM', false),
    ],

];
