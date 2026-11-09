<?php

/**
 * Issue #1753: a saved playlist Find & Replace rule whose Find value is the valid
 * string "0" was accepted and persisted, but the saved-rule runner silently skipped
 * it - empty($rule['find_replace']) treats the nonempty string "0" as empty.
 */

use App\Jobs\RunPlaylistFindReplaceRules;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

it('runs a saved rule whose Find value is the literal string "0" (literal mode)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'find_replace_rules' => [[
            'enabled' => true,
            'target' => 'channels',
            'column' => 'title',
            'use_regex' => false,
            'find_replace' => '0',
            'replace_with' => 'X',
        ]],
    ]);
    $channel = Channel::factory()->for($playlist)->for($user)->create([
        'title' => 'Zone0 Tail',
        'title_custom' => null,
        'enabled' => true,
        'is_vod' => false,
    ]);

    (new RunPlaylistFindReplaceRules($playlist))->handle();

    expect($channel->fresh()->title_custom)->toBe('ZoneX Tail');
});

it('runs a saved rule whose Find value is the literal string "0" (regex mode)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'find_replace_rules' => [[
            'enabled' => true,
            'target' => 'channels',
            'column' => 'title',
            'use_regex' => true,
            'find_replace' => '0',
            'replace_with' => 'X',
        ]],
    ]);
    $channel = Channel::factory()->for($playlist)->for($user)->create([
        'title' => 'Zone0 Tail',
        'title_custom' => null,
        'enabled' => true,
        'is_vod' => false,
    ]);

    (new RunPlaylistFindReplaceRules($playlist))->handle();

    expect($channel->fresh()->title_custom)->toBe('ZoneX Tail');
});

it('still skips a rule with a genuinely empty Find value', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'find_replace_rules' => [[
            'enabled' => true,
            'target' => 'channels',
            'column' => 'title',
            'use_regex' => false,
            'find_replace' => '',
            'replace_with' => 'X',
        ]],
    ]);
    $channel = Channel::factory()->for($playlist)->for($user)->create([
        'title' => 'Zone0 Tail',
        'title_custom' => null,
        'enabled' => true,
        'is_vod' => false,
    ]);

    (new RunPlaylistFindReplaceRules($playlist))->handle();

    expect($channel->fresh()->title_custom)->toBeNull();
});
