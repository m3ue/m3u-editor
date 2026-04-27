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
    // S15E19 (E19, not E01) - not flagged by SD, not E01 → not new
    // S15E20 (E20, not E01) - SD is_new=true → new
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

it('marks season premiere (E01) as new without SD flag', function () {
    // E01 without SD is_new=true → heuristic marks it new
    EpgProgramme::factory()->create([
        'title' => 'The Great British Bake Off',
        'subtitle' => 'Episode 1',
        'season' => 10,
        'episode' => 1,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'premiere' => false,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Great British')
        ->call('search');

    $shows = $component->get('groupedShows');
    $airing = $shows[0]['airings'][0];

    expect($airing['is_new'])->toBeTrue();
});

it('does not mark regular episode (non-E01) as new without SD flag', function () {
    EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'subtitle' => 'Felina',
        'season' => 5,
        'episode' => 16,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Breaking Bad')
        ->call('search');

    $shows = $component->get('groupedShows');
    $airing = $shows[0]['airings'][0];

    expect($airing['is_new'])->toBeFalse();
});