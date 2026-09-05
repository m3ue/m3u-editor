<?php

use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the VOD view page with a clearlogo, Filament play/trailer buttons and the rich cast section', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'title' => 'Clearlogo Movie',
        'info' => [
            'plot' => 'A movie used to exercise the detail view.',
            'backdrop_path' => 'https://example.com/backdrop.jpg',
            'youtube_trailer' => 'abc123',
            'clearlogo' => 'https://example.com/logo.png',
            'cast_list' => [
                ['id' => null, 'name' => 'Jane Doe', 'character' => 'Hero', 'photo' => 'https://example.com/jane.jpg'],
                ['id' => 5, 'name' => 'John Roe', 'character' => 'Villain', 'photo' => null],
            ],
        ],
    ]);

    Livewire::test(ViewVod::class, ['record' => $channel->getRouteKey()])
        ->assertOk()
        ->assertSee('https://example.com/logo.png')
        ->assertSee('Play Movie')
        ->assertSee('Watch Trailer')
        ->assertSee('Jane Doe')
        ->assertSee('Hero')
        ->assertSee('John Roe')
        ->assertDontSee('inline-flex items-center gap-2 rounded-lg px-6 py-3')
        // The Play button calls a server method, not an inline $dispatch()
        // expression that Livewire's parser chokes on when the JSON payload
        // contains parentheses or quotes (e.g. "Movie (2009)").
        ->assertSee('wire:click="playFloatingStream"', escape: false)
        ->call('playFloatingStream')
        ->assertDispatched('openFloatingStream');
});

it('renders the Series view page with a clearlogo, Filament trailer button and the rich cast section', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'name' => 'Clearlogo Show',
        'backdrop_path' => json_encode(['https://example.com/backdrop.jpg']),
        'youtube_trailer' => 'xyz789',
        'metadata' => [
            'clearlogo' => 'https://example.com/show-logo.png',
            'cast_list' => [
                ['id' => null, 'name' => 'Ada Actor', 'character' => 'Lead', 'photo' => 'https://example.com/ada.jpg'],
            ],
        ],
    ]);

    Livewire::test(ViewSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertSee('https://example.com/show-logo.png')
        ->assertSee('Watch Trailer')
        ->assertSee('Ada Actor')
        ->assertSee('Lead')
        ->assertDontSee('bg-red-600 px-4 py-2');
});
