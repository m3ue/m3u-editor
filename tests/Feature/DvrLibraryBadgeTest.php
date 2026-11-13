<?php

/**
 * Library items that came from a DVR recording are tagged "DVR" in the panel: a
 * Source badge in the VOD, series and episode tables, and on the view pages.
 */

use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();

    $source = Channel::factory()->for($this->playlist)->create(['user_id' => $this->user->id, 'is_vod' => false, 'title' => 'ESPN+ 58']);
    $setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    $this->recording = DvrRecording::factory()->completed()->for($this->user)->for($setting)->create([
        'channel_id' => $source->id,
        'programme_start' => Carbon::parse('2026-11-12 19:00:00'),
    ]);

    $this->dvrMovie = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'Recorded Movie',
        'dvr_recording_id' => $this->recording->id,
    ]);
    $this->movie = Channel::factory()->for($this->playlist)->create(['user_id' => $this->user->id, 'is_vod' => true, 'title' => 'Provider Movie']);
    $this->dvrSeries = Series::factory()->for($this->playlist)->create(['user_id' => $this->user->id, 'import_batch_no' => 'dvr']);
    $this->series = Series::factory()->for($this->playlist)->create(['user_id' => $this->user->id]);
});

it('tags DVR movies and series in the library tables', function () {
    Livewire::test(ListVod::class)
        ->assertTableColumnStateSet('dvr_source', 'DVR', $this->dvrMovie)
        ->assertTableColumnStateNotSet('dvr_source', 'DVR', $this->movie);

    Livewire::test(ListSeries::class)
        ->assertTableColumnStateSet('dvr_source', 'DVR', $this->dvrSeries)
        ->assertTableColumnStateNotSet('dvr_source', 'DVR', $this->series);
});

it('shows a DVR badge with when and where it was recorded on the VOD view page', function (?array $backdrop) {
    $this->dvrMovie->update(['info' => ['backdrop_path' => $backdrop]]);

    Livewire::test(ViewVod::class, ['record' => $this->dvrMovie->getRouteKey()])
        ->assertOk()
        ->assertSee('DVR')
        ->assertSee('on ESPN+ 58.', escape: false);

    Livewire::test(ViewVod::class, ['record' => $this->movie->getRouteKey()])
        ->assertOk()
        ->assertDontSee('on ESPN+ 58.');
})->with([
    'hero backdrop' => [['https://example.com/backdrop.jpg']],
    'no backdrop' => [null],
]);

it('shows a DVR badge on the series view page', function () {
    Livewire::test(ViewSeries::class, ['record' => $this->dvrSeries->getRouteKey()])
        ->assertOk()
        ->assertSee('Added to the library from a DVR recording');

    Livewire::test(ViewSeries::class, ['record' => $this->series->getRouteKey()])
        ->assertOk()
        ->assertDontSee('Added to the library from a DVR recording');
});
