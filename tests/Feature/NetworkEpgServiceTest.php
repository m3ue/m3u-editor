<?php

use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\Series;
use App\Models\User;
use App\Services\NetworkEpgService;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('includes icon tag when programme has image', function () {
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Network',
        'logo' => 'https://example.com/network-logo.png',
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Movie',
        'description' => 'A test movie description',
        'image' => 'https://example.com/movie-poster.jpg',
        'start_time' => Carbon::now(),
        'end_time' => Carbon::now()->addHours(2),
        'duration_seconds' => 7200,
        'contentable_type' => 'App\Models\Channel',
        'contentable_id' => 1,
    ]);

    $service = app(NetworkEpgService::class);
    $xml = $service->generateXmltvForNetwork($network);

    expect($xml)->toContain('<icon src="https://example.com/movie-poster.jpg"/>');
    expect($xml)->toContain('<icon src="https://example.com/network-logo.png"/>');
});

it('falls back to contentable image when programme image is empty', function () {
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Fallback Test Network',
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'cover' => 'https://example.com/series-cover.jpg',
    ]);

    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'cover' => 'https://example.com/episode-cover.jpg',
    ]);

    // Create programme WITHOUT image - should fallback to episode cover
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Episode',
        'description' => 'A test episode',
        'image' => null, // No image stored
        'start_time' => Carbon::now(),
        'end_time' => Carbon::now()->addMinutes(45),
        'duration_seconds' => 2700,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
    ]);

    $service = app(NetworkEpgService::class);
    $xml = $service->generateXmltvForNetwork($network);

    // Should fallback to episode cover
    expect($xml)->toContain('<icon src="https://example.com/episode-cover.jpg"/>');
});

it('falls back to series cover when episode has no cover', function () {
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Series Fallback Network',
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'cover' => 'https://example.com/series-cover.jpg',
    ]);

    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'cover' => null, // No episode cover
        'info' => [], // No info images either
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Episode Without Cover',
        'image' => null,
        'start_time' => Carbon::now(),
        'end_time' => Carbon::now()->addMinutes(30),
        'duration_seconds' => 1800,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
    ]);

    $service = app(NetworkEpgService::class);
    $xml = $service->generateXmltvForNetwork($network);

    // Should fallback to series cover
    expect($xml)->toContain('<icon src="https://example.com/series-cover.jpg"/>');
});

it('includes network logo as channel icon', function () {
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Logo Test Network',
        'logo' => 'https://example.com/channel-icon.png',
    ]);

    $service = app(NetworkEpgService::class);
    $xml = $service->generateXmltvForNetwork($network);

    expect($xml)->toContain('<channel id=');
    expect($xml)->toContain('<icon src="https://example.com/channel-icon.png"/>');
});
