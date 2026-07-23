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
