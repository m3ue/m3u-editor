<?php

return [
    'author' => 'Shaun Parkison',
    'version' => '0.7.11',
    'dev_version' => '0.8.0-dev',
    'experimental_version' => '0.8.0-exp',
    'repo' => 'sparkison/m3u-editor',
    'docs_url' => 'https://sparkison.github.io/m3u-editor-docs',
    'donate' => 'https://buymeacoffee.com/shparkison',
    'discord_url' => 'https://discord.gg/rS3abJ5dz7',
    'paypal' => 'https://www.paypal.com/donate/?hosted_button_id=ULJRPVWJNBSSG',
    'kofi' => 'https://ko-fi.com/sparkison',
    'admin_emails' => [
        // Default admin email
        'admin@test.com'
    ],
    'tvgid' => [
        'regex' => env('TVGID_REGEX', '/[^a-zA-Z0-9_\-\.]/'),
    ],
    'disable_sync_logs' => env('DISABLE_SYNC_LOGS', false), // Disable sync logs for performance
    'max_channels' => env('MAX_CHANNELS', 50000), // Maximum number of channels allowed for m3u import
    'invalidate_import' => env('INVALIDATE_IMPORT', null), // Invalidate import if number of "new" channels is less than the current count (minus `INVALIDATE_IMPORT_THRESHOLD`)
    'invalidate_import_threshold' => env('INVALIDATE_IMPORT_THRESHOLD', null), // Threshold for invalidating import
    'default_epg_days' => env('DEFAULT_EPG_DAYS', 7), // Default number of days to fetch for EPG generation
    'show_wan_details' => env('SHOW_WAN_DETAILS', false), // Show WAN details in admin panel
    'crypto_addresses' => [
        [
            'name' => 'Bitcoin',
            'symbol' => 'BTC',
            'address' => '',
            'icon' => '/images/crypto-icons/bitcoin.svg',
        ],
        [
            'name' => 'Ethereum',
            'symbol' => 'ETH',
            'address' => '',
            'icon' => '/images/crypto-icons/ethereum.svg',
        ],
        [
            'name' => 'Solana',
            'symbol' => 'SOL',
            'address' => '',
            'icon' => '/images/crypto-icons/solana.svg',
        ],
        [
            'name' => 'Tether',
            'symbol' => 'USDT',
            'address' => '',
            'icon' => '/images/crypto-icons/tether.svg',
        ],
        [
            'name' => 'Litecoin',
            'symbol' => 'LTC',
            'address' => '',
            'icon' => '/images/crypto-icons/litecoin.svg',
        ],
        [
            'name' => 'Ripple',
            'symbol' => 'XRP',
            'address' => '',
            'icon' => '/images/crypto-icons/ripple.svg',
        ]
    ]
];
