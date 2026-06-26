<?php

use App\Filament\Pages\ActorFilmography;
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
    Http::fake([
        'api.themoviedb.org/3/person/*' => Http::sequence()
            ->push(['name' => 'Test Actor', 'profile_path' => '/abc.jpg', 'biography' => 'A famous test actor.'], 200)
            ->push(['cast' => [
                [
                    'id' => 550,
                    'media_type' => 'movie',
                    'title' => 'Fight Club',
                    'character' => 'Tyler Durden',
                    'release_date' => '1999-10-15',
                    'poster_path' => '/fightclub.jpg',
                ],
                [
                    'id' => 1399,
                    'media_type' => 'tv',
                    'name' => 'Game of Thrones',
                    'character' => 'Tyrion Lannister',
                    'first_air_date' => '2011-04-17',
                    'poster_path' => '/got.jpg',
                ],
            ]], 200),
    ]);
});

it('hides the page when unauthenticated', function () {
    expect(ActorFilmography::canAccess())->toBeFalse();
});

it('allows access when authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(ActorFilmography::canAccess())->toBeTrue();
});

it('populates person and filmography on mount', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::flush();

    $page = new ActorFilmography;
    $page->personId = 123;
    $page->name = 'Test Actor';
    $page->mount();

    expect($page->person)->toBeArray()
        ->and($page->person['name'])->toBe('Test Actor')
        ->and($page->person['photo'])->toContain('abc.jpg');

    expect($page->filmography)->toHaveCount(2)
        ->and($page->filmography[0]['title'])->toBe('Game of Thrones')
        ->and($page->filmography[1]['title'])->toBe('Fight Club');
});

it('returns empty filmography when personId is invalid', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = new ActorFilmography;
    $page->personId = 0;
    $page->mount();

    expect($page->filmography)->toBe([])
        ->and($page->person)->toBeNull();
});

it('uses the title from the person name when available', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = new ActorFilmography;
    $page->personId = 123;
    $page->name = 'Test Actor';
    $page->mount();

    expect((string) $page->getTitle())->toBe('Test Actor');
});

it('falls back to default title when name is empty', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = new ActorFilmography;
    $page->personId = 0;
    $page->name = '';
    $page->mount();

    expect((string) $page->getTitle())->toBe(__('Actor Filmography'));
});

it('caches filmography results', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::flush();

    $page = new ActorFilmography;
    $page->personId = 123;
    $page->mount();
    $firstCallCount = count($page->filmography);

    $page2 = new ActorFilmography;
    $page2->personId = 123;
    $page2->mount();

    expect($page2->filmography)->toHaveCount($firstCallCount);
});

it('looks up the person by name when personId is missing', function () {
    Http::fake([
        'api.themoviedb.org/3/search/person*' => Http::response([
            'results' => [
                ['id' => 7777, 'name' => 'Sam Worthington'],
            ],
        ], 200),
    ]);
    Cache::flush();
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $this->actingAs($user);

    $page = new ActorFilmography;
    $page->personId = 0;
    $page->name = 'Sam Worthington';
    $page->mount();

    expect($page->personId)->toBe(7777)
        ->and($page->person)->toBeArray()
        ->and($page->person['name'])->toBe('Test Actor')
        ->and($page->filmography)->toHaveCount(2);
});
