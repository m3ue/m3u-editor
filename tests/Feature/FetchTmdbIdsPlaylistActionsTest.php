<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessVodChannels;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $settings);
});

it('dispatches playlist-scoped FetchTmdbIds from the EditPlaylist header actions', function () {
    $this->playlist->update(['xtream' => true]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->callAction('fetch_tmdb_vod', ['overwrite_existing' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('TMDB metadata fetch started')
        ->callAction('fetch_tmdb_series', ['overwrite_existing' => false])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) {
        return $job->vodPlaylistId === $this->playlist->id
            && $job->seriesPlaylistId === null
            && $job->overwriteExisting === true
            && $job->user?->is($this->user);
    });
    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) {
        return $job->seriesPlaylistId === $this->playlist->id
            && $job->vodPlaylistId === null
            && $job->overwriteExisting === false
            && $job->user?->is($this->user);
    });
});

it('hides the playlist TMDB actions for non-Xtream playlists', function () {
    $this->playlist->update(['xtream' => false]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->assertActionHidden('fetch_tmdb_vod')
        ->assertActionHidden('fetch_tmdb_series');
});

it('passes the overwrite toggle through the playlist provider metadata actions', function (bool $overwrite) {
    $this->playlist->update(['xtream' => true]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->callAction('process_vod', ['overwrite_existing' => $overwrite])
        ->assertHasNoActionErrors();

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->callAction('process_series', ['overwrite_existing' => $overwrite])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(ProcessVodChannels::class, fn (ProcessVodChannels $job) => $job->playlist?->is($this->playlist)
        && $job->force === $overwrite);
    Bus::assertDispatched(ProcessM3uImportSeries::class, fn (ProcessM3uImportSeries $job) => $job->playlist->is($this->playlist)
        && $job->force === true
        && $job->overwriteExisting === $overwrite);
})->with([true, false]);
