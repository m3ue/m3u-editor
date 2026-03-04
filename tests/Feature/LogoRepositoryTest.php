<?php

use App\Models\Channel;
use App\Services\LogoRepositoryService;
use App\Settings\GeneralSettings;

beforeEach(function () {
    $mockSettings = \Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_repository_enabled = true;
    app()->instance(GeneralSettings::class, $mockSettings);
});

it('returns a logo repository index payload', function () {
    /** @var \Tests\TestCase $this */
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
    /** @var \Tests\TestCase $this */
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

it('returns 404 when logo repository is disabled', function () {
    /** @var \Tests\TestCase $this */
    $mockSettings = \Mockery::mock(GeneralSettings::class);
    $mockSettings->logo_repository_enabled = false;
    app()->instance(GeneralSettings::class, $mockSettings);

    $this->get('/logo-repository/index.json')->assertNotFound();
    $this->get('/logo-repository/logos/anything.png')->assertNotFound();
});
