<?php

use App\Models\Channel;
use App\Services\LogoRepositoryService;
use App\Settings\GeneralSettings;
use Tests\TestCase;

beforeEach(function () {
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_repository_enabled = true;
    app()->instance(GeneralSettings::class, $mockSettings);
});

it('returns a logo repository index payload', function () {
    /** @var TestCase $this */
    Channel::factory()->create([
        'enabled' => true,
        'is_vod' => false,
        'name' => 'Discovery Channel',
        'name_custom' => null,
        'logo' => 'https://example.com/discovery.png',
    ]);

    app(LogoRepositoryService::class)->clearCache();

    $response = $this->get('/logo-repository/index.json');

    $response->assertOk();
    $response->assertJsonPath('count', 1);
    $response->assertJsonPath('logos.0.filename', 'discovery-channel.png');
    $response->assertJsonPath('logos.0.logo', 'https://example.com/discovery.png');
});

it('resolves repository logo filenames to logo urls', function () {
    /** @var TestCase $this */
    Channel::factory()->create([
        'enabled' => true,
        'is_vod' => false,
        'name' => 'BBC News',
        'name_custom' => null,
        'logo' => 'https://example.com/bbc-news.png',
    ]);

    app(LogoRepositoryService::class)->clearCache();

    $response = $this->get('/logo-repository/logos/bbc-news.png');

    $response->assertRedirect('https://example.com/bbc-news.png');
});

it('resolves a canonical filename to its own logo even when an earlier entry has a matching alias', function () {
    /** @var TestCase $this */
    // A's custom name shadows its original name, so "weather" becomes an alias of A's
    // "news.png" entry. B's own canonical filename is "weather.png" and must still resolve
    // to B's logo, not be shadowed by A's alias.
    Channel::factory()->create([
        'enabled' => true,
        'is_vod' => false,
        'name' => 'Weather',
        'name_custom' => 'News',
        'logo' => 'https://example.com/a.png',
    ]);
    Channel::factory()->create([
        'enabled' => true,
        'is_vod' => false,
        'name' => 'Weather',
        'name_custom' => null,
        'logo' => 'https://example.com/b.png',
    ]);

    app(LogoRepositoryService::class)->clearCache();

    $this->get('/logo-repository/logos/news.png')->assertRedirect('https://example.com/a.png');
    $this->get('/logo-repository/logos/weather.png')->assertRedirect('https://example.com/b.png');
});

it('includes a channel whose name is the literal string "0" in the index', function () {
    /** @var TestCase $this */
    Channel::factory()->create([
        'enabled' => true,
        'is_vod' => false,
        'name' => '0',
        'name_custom' => null,
        'logo' => 'https://example.com/zero.png',
    ]);

    app(LogoRepositoryService::class)->clearCache();

    $response = $this->get('/logo-repository/index.json');

    $response->assertOk();
    $response->assertJsonPath('count', 1);

    $this->get('/logo-repository/logos/0.png')->assertRedirect('https://example.com/zero.png');
});

it('returns 404 when logo repository is disabled', function () {
    /** @var TestCase $this */
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_repository_enabled = false;
    app()->instance(GeneralSettings::class, $mockSettings);

    $this->get('/logo-repository/index.json')->assertNotFound();
    $this->get('/logo-repository/logos/anything.png')->assertNotFound();
});
