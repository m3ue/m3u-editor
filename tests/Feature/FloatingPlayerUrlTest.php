<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('generates a floating player URL with playlist UUID for VOD channels', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $attrs = $channel->getFloatingPlayerAttributes();

    expect($attrs['url'])
        ->toContain('/movie/testuser/')
        ->toContain($this->playlist->uuid)
        ->not->toContain('//')
        ->toContain('?proxy=true&player=true');
});

it('generates a floating player URL with playlist UUID for live channels', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/123',
    ]);

    $attrs = $channel->getFloatingPlayerAttributes();

    expect($attrs['url'])
        ->toContain('/live/testuser/')
        ->toContain($this->playlist->uuid)
        ->not->toContain('//')
        ->toContain('?proxy=true&player=true');
});

it('generates a valid URL when playlist is loaded with restricted select columns', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    // Simulate the Filament table eager-load with restricted select that includes uuid
    $loaded = Channel::query()
        ->with(['playlist' => fn ($q) => $q->select('id', 'name', 'uuid', 'auto_sort')])
        ->find($channel->id);

    $attrs = $loaded->getFloatingPlayerAttributes();

    expect($attrs['url'])
        ->toContain('/movie/testuser/')
        ->toContain($this->playlist->uuid)
        ->not->toContain('//')
        ->toContain('?proxy=true&player=true');
});

it('generates a floating player URL with playlist UUID for episodes', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $season = Season::factory()->for($series)->create();
    $episode = Episode::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for($series)
        ->for($season)
        ->create([
            'url' => 'http://provider.test/episode/456.mkv',
            'container_extension' => 'mkv',
        ]);

    $attrs = $episode->getFloatingPlayerAttributes();

    expect($attrs['url'])
        ->toContain('/series/testuser/')
        ->toContain($this->playlist->uuid)
        ->not->toContain('//')
        ->toContain('?proxy=true&player=true');
});
