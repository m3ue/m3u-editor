<?php

use App\Events\PlaylistCreated;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Jobs\FetchTmdbIds;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PlaylistCreated::class]);
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $mock = Mockery::mock(GeneralSettings::class);
    $mock->shouldReceive('getAttribute')->with('tmdb_api_key')->andReturn('fake-api-key');
    $mock->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $mock);
});

it('dispatches the VOD row Fetch TMDB Metadata action with overwrite off by default', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'title' => 'The Matrix',
    ]);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($channel), ['overwrite_existing' => false])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($channel) {
        return $job->vodChannelIds === [$channel->id]
            && $job->overwriteExisting === false
            && $job->user?->is($this->user);
    });
});

it('dispatches the VOD row Fetch TMDB Metadata action with overwrite on when toggled', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'title' => 'The Matrix',
    ]);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($channel), ['overwrite_existing' => true])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(FetchTmdbIds::class, fn (FetchTmdbIds $job) => $job->overwriteExisting === true);
});

it('dispatches the Series row Fetch TMDB Metadata action honoring the overwrite toggle', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Game of Thrones',
    ]);

    Livewire::test(ListSeries::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($series), ['overwrite_existing' => true])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($series) {
        return $job->seriesIds === [$series->id]
            && $job->overwriteExisting === true
            && $job->user?->is($this->user);
    });
});
