<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows the VOD merge key only when VOD auto-merge is on', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_merge_channels_enabled' => true,
        'auto_merge_config' => ['merge_key' => 'stream_id'],
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldHidden('auto_merge_config.merge_key')
        ->assertFormFieldVisible('auto_merge_config.preferred_playlist_id')
        ->fillForm(['auto_merge_config.merge_vod' => true])
        ->assertFormFieldVisible('auto_merge_config.merge_key');
});

it('shows merge sources for series-only auto-merge but not the channel behavior options', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_merge_channels_enabled' => false,
        'auto_merge_config' => ['merge_series' => true],
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldVisible('auto_merge_config.preferred_playlist_id')
        ->assertFormFieldHidden('auto_merge_config.merge_key')
        ->assertFormFieldHidden('auto_merge_config.new_channels_only');
});

it('saves each content type toggle independently', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'user_agent' => 'VLC/3.0.0',
        'auto_merge_channels_enabled' => false,
        'auto_merge_config' => [],
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'auto_merge_channels_enabled' => false,
            'auto_merge_config.merge_vod' => true,
            'auto_merge_config.merge_key' => 'tmdb_id',
            'auto_merge_config.merge_series' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $playlist->refresh();

    expect($playlist->auto_merge_channels_enabled)->toBeFalse()
        ->and($playlist->auto_merge_config['merge_vod'])->toBeTrue()
        ->and($playlist->auto_merge_config['merge_key'])->toBe('tmdb_id')
        ->and($playlist->auto_merge_config['merge_series'])->toBeTrue();
});
