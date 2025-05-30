<?php

return [
    'url_override' => env('PROXY_URL_OVERRIDE', null),
    'proxy_format' => env('PROXY_FORMAT', 'mpts'), // 'mpts' or 'hls'
    'ffmpeg_path' => env('PROXY_FFMPEG_PATH', null),
    'ffmpeg_additional_args' => env('PROXY_FFMPEG_ADDITIONAL_ARGS', ''),
    'ffmpeg_codec_video' => env('PROXY_FFMPEG_CODEC_VIDEO', null),
    'ffmpeg_codec_audio' => env('PROXY_FFMPEG_CODEC_AUDIO', null),
    'ffmpeg_codec_subtitles' => env('PROXY_FFMPEG_CODEC_SUBTITLES', null),
    'ffmpeg_speed_threshold' => env('PROXY_FFMPEG_LOW_SPEED_THRESHOLD', 0.9), // Anything below this speed will be considered slow (used for channel failover logic)
];