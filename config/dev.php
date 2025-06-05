<?php

return [
    'author' => 'Shaun Parkison',
    'version' => '0.6.6',
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
    'max_channels' => env('MAX_CHANNELS', 50000), // Maximum number of channels allowed for m3u import
];
