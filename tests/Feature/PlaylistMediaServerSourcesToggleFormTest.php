<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    // Array cache: the toggle's updated event dispatches the ShouldBeUnique
    // rebuild job, whose lock would otherwise persist in Redis across runs.
    config()->set('cache.default', 'array');
    Cache::flush();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('saves prefer_media_server_sources on the playlist edit form', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'prefer_media_server_sources' => false,
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'user_agent' => 'test-agent',
            'prefer_media_server_sources' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors(['prefer_media_server_sources']);

    expect($playlist->refresh()->prefer_media_server_sources)->toBeTrue();
});
