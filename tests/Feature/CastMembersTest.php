<?php

use App\Models\Channel;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    app()->instance(GeneralSettings::class, $settings);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);

    Cache::flush();
    Http::preventStrayRequests();
});

it('returns tv cast for series with tmdb_id', function () {
    Http::fake([
        'api.themoviedb.org/3/tv/*/credits*' => Http::response([
            'cast' => [
                [
                    'id' => 1,
                    'name' => 'Bryan Cranston',
                    'character' => 'Walter White',
                    'profile_path' => '/bryan.jpg',
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $series = Series::factory()->for($user)->create(['tmdb_id' => 1399]);

    $cast = $series->castMembers();

    expect($cast)->toHaveCount(1)
        ->and($cast[0])->toMatchArray([
            'id' => 1,
            'actor' => 'Bryan Cranston',
            'character' => 'Walter White',
        ]);
});

it('returns empty cast for series without tmdb_id', function () {
    $user = User::factory()->create();
    $series = Series::factory()->for($user)->create(['tmdb_id' => null]);

    Http::assertNothingSent();

    expect($series->castMembers())->toBe([]);
});

it('returns movie cast for vod channels', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/*/credits*' => Http::response([
            'cast' => [
                [
                    'id' => 31,
                    'name' => 'Tom Hanks',
                    'character' => 'Forrest Gump',
                    'profile_path' => '/tom.jpg',
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $vod = Channel::factory()->for($user)->create([
        'is_vod' => true,
        'tmdb_id' => 13,
    ]);

    $cast = $vod->castMembers();

    expect($cast)->toHaveCount(1)
        ->and($cast[0])->toMatchArray([
            'id' => 31,
            'actor' => 'Tom Hanks',
            'character' => 'Forrest Gump',
        ]);
});

it('returns empty cast for non-vod channels', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->for($user)->create([
        'is_vod' => false,
        'tmdb_id' => 99,
    ]);

    Http::assertNothingSent();

    expect($channel->castMembers())->toBe([]);
});

it('returns empty cast for vod channels without tmdb_id', function () {
    $user = User::factory()->create();
    $vod = Channel::factory()->for($user)->create([
        'is_vod' => true,
        'tmdb_id' => null,
    ]);

    Http::assertNothingSent();

    expect($vod->castMembers())->toBe([]);
});
