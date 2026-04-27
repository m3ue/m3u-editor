<?php

use App\Filament\Pages\BrowseShows;
use App\Models\DvrSetting;
use App\Models\EpgProgramme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create();
    $this->actingAs($this->user);
});

it('marks show as is_new when any airing has is_new=true', function () {
    // S15E19: SD is_new=false, not previously_shown, not premiere → heuristic makes it new
    // S15E20: SD is_new=true → new
    EpgProgramme::factory()->create([
        'title' => 'Real Housewives of Beverly Hills',
        'subtitle' => 'Reunion Part 1',
        'season' => 15,
        'episode' => 19,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'previously_shown' => false,
    ]);

    EpgProgramme::factory()->create([
        'title' => 'Real Housewives of Beverly Hills',
        'subtitle' => 'Reunion Part 2',
        'season' => 15,
        'episode' => 20,
        'start_time' => now()->addDays(1)->addHours(2),
        'end_time' => now()->addDays(1)->addHours(3),
        'is_new' => true,
    ]);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Real Housewives')
        ->call('search')
        ->assertSet('groupedShows', fn (array $shows) => count($shows) === 1
            && $shows[0]['flags']['is_new'] === true);
});

it('passes is_new and premiere flags correctly to each airing in the slide-out', function () {
    EpgProgramme::factory()->create([
        'title' => 'Real Housewives of Beverly Hills',
        'subtitle' => 'Reunion Part 1',
        'season' => 15,
        'episode' => 19,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'previously_shown' => false,
    ]);

    EpgProgramme::factory()->create([
        'title' => 'Real Housewives of Beverly Hills',
        'subtitle' => 'Reunion Part 2',
        'season' => 15,
        'episode' => 20,
        'start_time' => now()->addDays(1)->addHours(2),
        'end_time' => now()->addDays(1)->addHours(3),
        'is_new' => true,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Real Housewives')
        ->call('search');

    $shows = $component->get('groupedShows');
    $airings = $shows[0]['airings'];

    $part1 = collect($airings)->first(fn ($a) => $a['episode'] === 19);
    $part2 = collect($airings)->first(fn ($a) => $a['episode'] === 20);

    // With heuristic: not previously_shown && not premiere → true
    expect($part1['is_new'])->toBeTrue()
        && expect($part2['is_new'])->toBeTrue();
});

it('does not mark as new if previously_shown is true', function () {
    EpgProgramme::factory()->create([
        'title' => 'Rerun Episode',
        'season' => 1,
        'episode' => 1,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'previously_shown' => true,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Rerun')
        ->call('search');

    $shows = $component->get('groupedShows');
    $airing = $shows[0]['airings'][0];

    expect($airing['is_new'])->toBeFalse();
});