<?php

use App\Livewire\EpgViewer;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($this->user);
});

it('enables the record button for a Playlist with DVR enabled (regression)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => $playlist->id,
    ]);

    Livewire::test(EpgViewer::class, ['record' => $playlist])
        ->assertSet('dvrEnabled', true);
});

it('does not enable the record button for a Playlist without DVR enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    Livewire::test(EpgViewer::class, ['record' => $playlist])
        ->assertSet('dvrEnabled', false);
});

it('enables the record button for a CustomPlaylist with its own DVR setting enabled (#1370)', function () {
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => $custom->id,
    ]);

    Livewire::test(EpgViewer::class, ['record' => $custom])
        ->assertSet('dvrEnabled', true);
});

it('does not enable the record button for a CustomPlaylist without a DVR setting', function () {
    $custom = CustomPlaylist::factory()->for($this->user)->create();

    Livewire::test(EpgViewer::class, ['record' => $custom])
        ->assertSet('dvrEnabled', false);
});

it('enables the record button for a MergedPlaylist with its own DVR setting enabled (#1375)', function () {
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'merged_playlist_id' => $merged->id,
    ]);

    Livewire::test(EpgViewer::class, ['record' => $merged])
        ->assertSet('dvrEnabled', true);
});

it('does not enable the record button for a MergedPlaylist without a DVR setting', function () {
    $merged = MergedPlaylist::factory()->for($this->user)->create();

    Livewire::test(EpgViewer::class, ['record' => $merged])
        ->assertSet('dvrEnabled', false);
});
