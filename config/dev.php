<?php

return [
    'author' => 'Shaun Parkison',
    'version' => '0.3.5.1',
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
    'ffmpeg' => [
        'debug' => env('FFMPEG_DEBUG', false),
        'file' => env('FFMPEG_DEBUG_FILE', 'ffmpeg.log'),
    ]
];
