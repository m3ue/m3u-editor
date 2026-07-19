<?php

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['cache.default' => 'array']);
});

test('new playlist defaults streams to 0', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    expect($playlist->streams)->toBe(0);
});

test('xtream api returns provider max connections when proxy is disabled and streams is 0', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => false,
        'streams' => 0,
    ]);

    $status = [
        'user_info' => [
            'max_connections' => 5,
            'active_cons' => 2,
            'exp_date' => null,
        ],
    ];

    $playlist->update(['xtream_status' => $status]);
    Cache::put("p:{$playlist->id}:xtream_status", $status, 60);

    $response = $this->get(route('xtream.api.player', [
        'uuid' => $playlist->uuid,
        'action' => 'get_user_info',
        'username' => $user->name,
        'password' => $playlist->uuid,
    ]));

    $response->assertOk();
    $response->assertJsonPath('user_info.max_connections', '5');
});

test('hdhr device info returns tuner count matching provider max connections when proxy is disabled and streams is 0', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => false,
        'streams' => 0,
    ]);

    $status = [
        'user_info' => [
            'max_connections' => 7,
            'active_cons' => 0,
        ],
    ];

    $playlist->update(['xtream_status' => $status]);
    Cache::put("p:{$playlist->id}:xtream_status", $status, 60);

    $response = $this->get("/{$playlist->uuid}/hdhr/discover.json", [
        'PHP_AUTH_USER' => $user->name,
        'PHP_AUTH_PW' => $playlist->uuid,
    ]);

    $response->assertOk();
    $response->assertJsonPath('TunerCount', 7);
});
