<?php

use App\Filament\GuestPanel\Pages\GuestActorFilmography;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

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
            ->push(['name' => 'Guest Actor', 'profile_path' => '/guest.jpg', 'biography' => 'A famous guest actor.'], 200)
            ->push(['cast' => [
                [
                    'id' => 100,
                    'media_type' => 'movie',
                    'title' => 'Inception',
                    'character' => 'Cobb',
                    'release_date' => '2010-07-15',
                    'poster_path' => '/inception.jpg',
                ],
            ]], 200),
    ]);

    $this->owner = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->owner->id]);
});

function setGuestSession(string $uuid): void
{
    $request = Request::create('/');
    $request->attributes->set('playlist_uuid', $uuid);
    app()->instance('request', $request);

    session([
        base64_encode($uuid).'_guest_auth_username' => 'guest',
        base64_encode($uuid).'_guest_auth_password' => 'secret',
    ]);
}

it('hides the page when no session auth', function () {
    $request = Request::create('/');
    $request->attributes->set('playlist_uuid', $this->playlist->uuid);
    app()->instance('request', $request);
    session()->forget([
        base64_encode($this->playlist->uuid).'_guest_auth_username',
        base64_encode($this->playlist->uuid).'_guest_auth_password',
    ]);

    expect(GuestActorFilmography::canAccess())->toBeFalse();
});

it('hides the page when no uuid in request', function () {
    $request = Request::create('/');
    app()->instance('request', $request);

    expect(GuestActorFilmography::canAccess())->toBeFalse();
});

it('allows access when authenticated with uuid', function () {
    setGuestSession($this->playlist->uuid);

    expect(GuestActorFilmography::canAccess())->toBeTrue();
});

it('populates person and filmography on mount', function () {
    setGuestSession($this->playlist->uuid);

    Cache::flush();

    $page = new GuestActorFilmography;
    $page->personId = 456;
    $page->name = 'Guest Actor';
    $page->mount();

    expect($page->person['name'])->toBe('Guest Actor');
    expect($page->filmography)->toHaveCount(1)
        ->and($page->filmography[0]['title'])->toBe('Inception');
});

it('returns empty data when personId is invalid', function () {
    setGuestSession($this->playlist->uuid);

    $page = new GuestActorFilmography;
    $page->personId = 0;
    $page->mount();

    expect($page->person)->toBeNull()
        ->and($page->filmography)->toBe([]);
});

it('uses the title from the person name when available', function () {
    setGuestSession($this->playlist->uuid);

    $page = new GuestActorFilmography;
    $page->personId = 456;
    $page->name = 'Guest Actor';
    $page->mount();

    expect((string) $page->getTitle())->toBe('Guest Actor');
});
