<?php

use App\Filament\Pages\BrowseShows;
use App\Models\DvrSetting;
use App\Models\EpgProgramme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create();
    $this->actingAs($this->user);
});

it('marks show as is_new when any airing has is_new=true', function () {
    Http::fake(); // no TVMaze enrichment needed for this assertion
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
        ->assertViewHas('groupedShows', fn (array $shows) => count($shows) === 1
            && $shows[0]['flags']['is_new'] === true);
});

it('does not mark season premiere (E01) as new when TVMaze has no data', function () {
    Http::fake(); // TVMaze returns empty → no enrichment → is_new stays false
    // E01 without SD is_new flag and no TVMaze confirmation → not new
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

    $shows = cache()->get($component->get('showsCacheKey'), []);
    $airing = $shows[0]['airings'][0];

    expect($airing['is_new'])->toBeFalse();
});

it('marks season premiere (E01) as new when TVMaze reports a recent airdate', function () {
    Http::fake([
        'https://api.tvmaze.com/*' => Http::response([
            'id' => 321,
            'name' => 'The Great British Bake Off',
            '_embedded' => [
                'episodes' => [
                    ['season' => 10, 'number' => 1, 'airdate' => now()->subDays(7)->format('Y-m-d'), 'name' => 'Cake Week'],
                ],
            ],
        ], 200),
    ]);

    EpgProgramme::factory()->create([
        'title' => 'The Great British Bake Off',
        'subtitle' => 'Cake Week',
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

    $shows = cache()->get($component->get('showsCacheKey'), []);
    $airing = $shows[0]['airings'][0];

    expect($airing['is_new'])->toBeTrue();
});

it('does not mark regular episode (non-E01) as new without SD flag', function () {
    Http::fake(); // TVMaze returns empty → no enrichment → is_new stays false
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

    $shows = cache()->get($component->get('showsCacheKey'), []);
    $airing = $shows[0]['airings'][0];

    expect($airing['is_new'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// TVMaze air-date based is_new
// ---------------------------------------------------------------------------

it('marks airing as is_new when TVMaze reports a recent airdate', function () {
    Http::fake([
        'https://api.tvmaze.com/*' => Http::response([
            'id' => 123,
            'name' => 'Breaking Bad',
            '_embedded' => [
                'episodes' => [
                    ['season' => 5, 'number' => 16, 'airdate' => now()->subDays(10)->format('Y-m-d'), 'name' => 'Felina'],
                ],
            ],
        ], 200),
    ]);

    EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'subtitle' => 'Felina',
        'season' => 5,
        'episode' => 16,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'premiere' => false,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Breaking Bad')
        ->call('search');

    $shows = cache()->get($component->get('showsCacheKey'), []);

    expect($shows[0]['airings'][0]['is_new'])->toBeTrue();
    expect($shows[0]['flags']['is_new'])->toBeTrue();
});

it('does not mark airing as is_new when TVMaze reports an old airdate', function () {
    Http::fake([
        'https://api.tvmaze.com/*' => Http::response([
            'id' => 456,
            'name' => 'Breaking Bad',
            '_embedded' => [
                'episodes' => [
                    ['season' => 5, 'number' => 16, 'airdate' => '2013-09-29', 'name' => 'Felina'],
                ],
            ],
        ], 200),
    ]);

    EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'subtitle' => 'Felina',
        'season' => 5,
        'episode' => 16,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'premiere' => false,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Breaking Bad')
        ->call('search');

    $shows = cache()->get($component->get('showsCacheKey'), []);

    expect($shows[0]['airings'][0]['is_new'])->toBeFalse();
});

it('marks description-embedded episode as is_new when TVMaze reports a recent airdate', function () {
    Http::fake([
        'https://api.tvmaze.com/*' => Http::response([
            'id' => 789,
            'name' => 'Real Housewives of Beverly Hills',
            '_embedded' => [
                'episodes' => [
                    ['season' => 15, 'number' => 19, 'airdate' => now()->subDays(10)->format('Y-m-d'), 'name' => 'Reunion Part 1'],
                ],
            ],
        ], 200),
    ]);

    // season/episode are null in DB; embedded in description instead
    EpgProgramme::factory()->create([
        'title' => 'Real Housewives of Beverly Hills',
        'subtitle' => null,
        'season' => null,
        'episode' => null,
        'description' => "S15 E19 Reunion Part 1\nA dramatic reunion episode.",
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
        'premiere' => false,
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Real Housewives')
        ->call('search');

    $shows = cache()->get($component->get('showsCacheKey'), []);

    expect($shows[0]['airings'][0]['is_new'])->toBeTrue();
    expect($shows[0]['flags']['is_new'])->toBeTrue();
});

it('makes only one TVMaze request per show title when multiple episodes are present', function () {
    Http::fake([
        'https://api.tvmaze.com/*' => Http::response([
            'id' => 999,
            'name' => 'Some Show',
            '_embedded' => [
                'episodes' => [
                    ['season' => 1, 'number' => 1, 'airdate' => now()->subDays(5)->format('Y-m-d')],
                    ['season' => 1, 'number' => 2, 'airdate' => now()->subDays(12)->format('Y-m-d')],
                ],
            ],
        ], 200),
    ]);

    EpgProgramme::factory()->create([
        'title' => 'Some Show',
        'season' => 1,
        'episode' => 1,
        'start_time' => now()->addDays(1),
        'end_time' => now()->addDays(1)->addHour(),
        'is_new' => false,
    ]);

    EpgProgramme::factory()->create([
        'title' => 'Some Show',
        'season' => 1,
        'episode' => 2,
        'start_time' => now()->addDays(2),
        'end_time' => now()->addDays(2)->addHour(),
        'is_new' => false,
    ]);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Some Show')
        ->call('search');

    Http::assertSentCount(1);
});
