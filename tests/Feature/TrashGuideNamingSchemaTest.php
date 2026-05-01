<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\StreamFileSetting;
use Illuminate\Support\Facades\Schema;

test('trash guide naming columns exist on the relevant tables', function () {
    foreach (['movie_format', 'episode_format', 'version_detection_pattern', 'group_versions', 'use_stream_stats'] as $column) {
        expect(Schema::hasColumn('stream_file_settings', $column))->toBeTrue();
    }

    foreach (['edition', 'year'] as $column) {
        expect(Schema::hasColumn('channels', $column))->toBeTrue();
    }

    foreach (['title', 'stream_stats'] as $column) {
        expect(Schema::hasColumn('episodes', $column))->toBeTrue();
    }
});

test('stream file settings model exposes fillable attributes and casts', function () {
    $model = new StreamFileSetting;

    expect($model->getFillable())->toContain(
        'movie_format',
        'episode_format',
        'version_detection_pattern',
        'group_versions',
        'use_stream_stats',
    );

    expect($model->getCasts())->toMatchArray([
        'group_versions' => 'boolean',
        'use_stream_stats' => 'boolean',
    ]);
});

test('channel and episode models cast the new attributes', function () {
    $channel = new Channel;
    $episode = new Episode;

    expect($channel->getCasts())->toMatchArray([
        'year' => 'integer',
    ]);

    expect($episode->getCasts())->toMatchArray([
        'stream_stats' => 'array',
    ]);
});
