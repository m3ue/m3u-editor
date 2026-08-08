<?php

use App\Filament\Resources\PlaylistAuths\Pages\ListPlaylistAuths;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);

    $this->user = User::factory()->create();

    $playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()
        ->for($this->user)
        ->for($playlist)
        ->enabled()
        ->create();

    $this->actingAs($this->user);
});

it('shows recording count for users with dvr recordings', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'Recording Guest',
        'dvr_enabled' => true,
    ]);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->count(3)
        ->create([
            'playlist_auth_id' => $auth->id,
        ]);

    Livewire::test(ListPlaylistAuths::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnStateSet('dvr_recordings_count', 3, $auth);
});

it('shows storage used with quota when quota is set', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'Quota Guest',
        'dvr_enabled' => true,
        'dvr_storage_quota_gb' => 10,
    ]);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'playlist_auth_id' => $auth->id,
            'file_size_bytes' => 1073741824, // 1.0 GB
        ]);

    Livewire::test(ListPlaylistAuths::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('storage_used_bytes', '1.0 GB / 10 GB', $auth);
});

it('shows unlimited storage when no quota is set', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'Unlimited Guest',
        'dvr_enabled' => true,
        'dvr_storage_quota_gb' => null,
    ]);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->create([
            'playlist_auth_id' => $auth->id,
            'file_size_bytes' => 524288000, // 500 MB
        ]);

    Livewire::test(ListPlaylistAuths::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('storage_used_bytes', '500.0 MB / ∞', $auth);
});

it('shows N/A for dvr storage when dvr is disabled', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'No DVR Guest',
        'dvr_enabled' => false,
    ]);

    Livewire::test(ListPlaylistAuths::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('storage_used_bytes', 'N/A', $auth);
});

it('shows N/A for used storage when dvr is enabled but no recordings have usage yet', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'name' => 'No Usage Guest',
        'dvr_enabled' => true,
        'dvr_storage_quota_gb' => 10,
    ]);

    Livewire::test(ListPlaylistAuths::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('storage_used_bytes', 'N/A / 10 GB', $auth);
});

it('renders the recording count as a badge', function () {
    Livewire::test(ListPlaylistAuths::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnExists('dvr_recordings_count', fn ($column) => $column->isBadge());
});
