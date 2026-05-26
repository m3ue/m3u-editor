<?php

declare(strict_types=1);

use App\Enums\DvrSeriesMode;
use App\Filament\GuestPanel\Pages\GuestBrowseShows;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function setSeriesDefaultsTestContext(Playlist $playlist, PlaylistAuth $auth): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $auth->username);
    session()->put("{$prefix}guest_auth_password", $auth->password);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()->enabled()->for($this->playlist)->for($this->user)->create();
    $this->guestAuth = PlaylistAuth::factory()
        ->for($this->user)
        ->create(['enabled' => true, 'dvr_enabled' => true]);
    setSeriesDefaultsTestContext($this->playlist, $this->guestAuth);
});

// --- DvrSetting model casts ---

it('casts default_series_mode to DvrSeriesMode::UniqueSe by default from factory', function () {
    $setting = DvrSetting::factory()->for($this->playlist)->for($this->user)->make();

    expect($setting->default_series_mode)->toBeInstanceOf(DvrSeriesMode::class)
        ->and($setting->default_series_mode)->toBe(DvrSeriesMode::UniqueSe);
});

it('default_series_keep_last is null by default', function () {
    $setting = DvrSetting::factory()->for($this->playlist)->for($this->user)->make();

    expect($setting->default_series_keep_last)->toBeNull();
});

it('casts non-null default_series_keep_last to integer', function () {
    $setting = DvrSetting::factory()
        ->for($this->playlist)
        ->for($this->user)
        ->create(['default_series_keep_last' => 5]);

    expect($setting->default_series_keep_last)->toBeInt()
        ->and($setting->default_series_keep_last)->toBe(5);
});

// --- GuestBrowseShows recordSeriesDefaults ---
// Note: Livewire::test() cannot carry request attributes through synthetic requests;
// use direct component instantiation instead (same pattern as GuestBrowseShowsTest).

it('recordSeriesDefaults creates a rule with the playlist default_series_mode', function () {
    $this->dvrSetting->update(['default_series_mode' => DvrSeriesMode::NewFlag]);

    $component = app(GuestBrowseShows::class);
    $component->recordSeriesDefaults('Test Show');

    expect(DvrRecordingRule::first()->series_mode)->toBe(DvrSeriesMode::NewFlag);
});

it('recordSeriesDefaults creates a rule with series_mode = UniqueSe when dvrSetting uses the default', function () {
    $component = app(GuestBrowseShows::class);
    $component->recordSeriesDefaults('Test Show');

    expect(DvrRecordingRule::first()->series_mode)->toBe(DvrSeriesMode::UniqueSe);
});

it('recordSeriesDefaults stamps keep_last from the playlist default_series_keep_last', function () {
    $this->dvrSetting->update(['default_series_keep_last' => 3]);

    $component = app(GuestBrowseShows::class);
    $component->recordSeriesDefaults('Test Show');

    expect(DvrRecordingRule::first()->keep_last)->toBe(3);
});
