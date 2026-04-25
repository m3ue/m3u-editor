<?php

use App\Models\StreamFileSetting;

test('toSyncSettings includes sports schema defaults and type', function () {
    $setting = new StreamFileSetting;
    $setting->type = 'sports';
    $setting->enabled = true;
    $setting->location = '/sports';
    $setting->sports_league_source = 'group';
    $setting->sports_season_source = 'title_year';
    $setting->sports_episode_strategy = 'sequential_per_season';
    $setting->sports_repeat_league_in_filename = true;
    $setting->sports_include_event_title = true;

    $payload = $setting->toSyncSettings();

    expect($payload['type'])->toBe('sports')
        ->and($payload['sports_league_source'])->toBe('group')
        ->and($payload['sports_season_source'])->toBe('title_year')
        ->and($payload['sports_episode_strategy'])->toBe('sequential_per_season')
        ->and($payload['sports_repeat_league_in_filename'])->toBeTrue()
        ->and($payload['sports_include_event_title'])->toBeTrue();
});
