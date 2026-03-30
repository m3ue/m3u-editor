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
