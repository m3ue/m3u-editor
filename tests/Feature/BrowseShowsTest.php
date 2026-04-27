<?php

use App\Enums\DvrRuleType;
use App\Filament\Pages\BrowseShows;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgProgramme;
use App\Models\User;
use App\Services\ShowMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create();
    $this->actingAs($this->user);
});

it('renders the browse shows page', function () {
    Livewire::test(BrowseShows::class)
        ->assertSuccessful();
});

it('pre-selects the dvr setting when the user has only one', function () {
    $component = Livewire::test(BrowseShows::class);

    expect($component->get('dvr_setting_id'))->toBe($this->setting->id);
});

it('returns grouped shows after calling search', function () {
    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('keyword', 'The Wire')
        ->call('search')
        ->assertSet('searched', true)
        ->assertSet('groupedShows', fn (array $shows) => count($shows) === 1
            && $shows[0]['title'] === 'The Wire');
});

it('filters search results by keyword', function () {
    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);
    EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('keyword', 'Wire')
        ->call('search')
        ->assertSet('groupedShows', fn (array $shows) => count($shows) === 1);
});

it('returns empty grouped shows when no programmes match', function () {
    Livewire::test(BrowseShows::class)
        ->set('keyword', 'Nonexistent Show XYZ')
        ->call('search')
        ->assertSet('searched', true)
        ->assertSet('groupedShows', []);
});

it('groups multiple airings of the same title into one card', function () {
    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);
    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(5),
        'end_time' => now()->addHours(6),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('keyword', 'The Wire')
        ->call('search')
        ->assertSet('groupedShows', fn (array $shows) => count($shows) === 1
            && count($shows[0]['airings']) === 2);
});

it('grouped show card contains expected keys', function () {
    EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    $component = Livewire::test(BrowseShows::class)
        ->set('keyword', 'Breaking Bad')
        ->call('search');

    $show = $component->get('groupedShows')[0];

    expect($show)->toHaveKeys([
        'title', 'next_air_date', 'next_air_date_human', 'flags',
        'epg_icon', 'poster_url', 'has_series_rule', 'has_once_rule',
        'airing_count', 'category', 'description', 'airings',
    ]);
});

it('sets postersLoaded to true after loadPosters', function () {
    $this->mock(ShowMetadataService::class)
        ->shouldReceive('resolvePosters')
        ->andReturn([])
        ->shouldReceive('resolveEpisodeIsNew')
        ->andReturn([]);

    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('keyword', 'The Wire')
        ->call('search')
        ->assertSet('postersLoaded', false)
        ->call('loadPosters')
        ->assertSet('postersLoaded', true);
});

it('populates poster_url on grouped shows after loadPosters', function () {
    $this->mock(ShowMetadataService::class)
        ->shouldReceive('resolvePosters')
        ->once()
        ->andReturn(['The Wire' => 'https://example.com/poster.jpg'])
        ->shouldReceive('resolveEpisodeIsNew')
        ->andReturn([]);

    EpgProgramme::factory()->create([
        'title' => 'The Wire',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('keyword', 'The Wire')
        ->call('search')
        ->call('loadPosters')
        ->assertSet('groupedShows', fn (array $shows) => $shows[0]['poster_url'] === 'https://example.com/poster.jpg');
});

it('sets postersLoaded true immediately when groupedShows is empty', function () {
    Livewire::test(BrowseShows::class)
        ->call('loadPosters')
        ->assertSet('postersLoaded', true);
});

it('openShowDetail sets selectedShowTitle', function () {
    Livewire::test(BrowseShows::class)
        ->call('openShowDetail', 'Breaking Bad')
        ->assertSet('selectedShowTitle', 'Breaking Bad');
});

it('closeShowDetail clears selectedShowTitle', function () {
    Livewire::test(BrowseShows::class)
        ->call('openShowDetail', 'Breaking Bad')
        ->call('closeShowDetail')
        ->assertSet('selectedShowTitle', '');
});

it('quickRecordNextAiring records the first upcoming airing for a title', function () {
    $programme = EpgProgramme::factory()->create([
        'title' => 'Breaking Bad',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('keyword', 'Breaking Bad')
        ->call('search')
        ->call('quickRecordNextAiring', 'Breaking Bad');

    expect(DvrRecordingRule::where('user_id', $this->user->id)
        ->where('type', DvrRuleType::Once)
        ->where('programme_id', $programme->id)
        ->exists())->toBeTrue();
});

it('warns when quickRecordNextAiring is called for a title with no airings', function () {
    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->call('quickRecordNextAiring', 'Nonexistent Show');

    expect(DvrRecordingRule::where('user_id', $this->user->id)->count())->toBe(0);
});

it('creates a once rule from a programme', function () {
    $programme = EpgProgramme::factory()->create([
        'title' => 'Special Event',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(3),
    ]);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->call('recordOnce', $programme->id);

    expect(DvrRecordingRule::where('user_id', $this->user->id)
        ->where('type', DvrRuleType::Once)
        ->where('programme_id', $programme->id)
        ->exists())->toBeTrue();
});

it('warns with a notification when a duplicate once rule exists', function () {
    $programme = EpgProgramme::factory()->create(['title' => 'Special Event']);

    DvrRecordingRule::factory()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'type' => DvrRuleType::Once,
        'programme_id' => $programme->id,
    ]);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->call('recordOnce', $programme->id);

    expect(DvrRecordingRule::where('user_id', $this->user->id)
        ->where('type', DvrRuleType::Once)
        ->where('programme_id', $programme->id)
        ->count())->toBe(1);
});

it('warns when recordOnce is called without a dvr setting selected', function () {
    $programme = EpgProgramme::factory()->create(['title' => 'Special Event']);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', null)
        ->call('recordOnce', $programme->id);

    expect(DvrRecordingRule::where('user_id', $this->user->id)->count())->toBe(0);
});

it('creates a series rule with defaults from a show title', function () {
    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->call('recordSeriesDefaults', 'Breaking Bad');

    expect(DvrRecordingRule::where('user_id', $this->user->id)
        ->where('type', DvrRuleType::Series)
        ->where('series_title', 'Breaking Bad')
        ->exists())->toBeTrue();
});

it('warns with a notification when a duplicate series rule already exists', function () {
    DvrRecordingRule::factory()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'type' => DvrRuleType::Series,
        'series_title' => 'Breaking Bad',
    ]);

    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->call('recordSeriesDefaults', 'Breaking Bad');

    expect(DvrRecordingRule::where('user_id', $this->user->id)
        ->where('type', DvrRuleType::Series)
        ->where('series_title', 'Breaking Bad')
        ->count())->toBe(1);
});

it('warns when recordSeriesDefaults is called without a dvr setting selected', function () {
    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', null)
        ->call('recordSeriesDefaults', 'Breaking Bad');

    expect(DvrRecordingRule::where('user_id', $this->user->id)->count())->toBe(0);
});

it('creates a series rule with custom options', function () {
    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', $this->setting->id)
        ->set('seriesNewOnly', true)
        ->set('seriesPriority', 75)
        ->set('seriesStartEarly', 120)
        ->set('seriesEndLate', 300)
        ->call('recordSeriesWithOptions', 'Breaking Bad');

    $rule = DvrRecordingRule::where('user_id', $this->user->id)
        ->where('type', DvrRuleType::Series)
        ->where('series_title', 'Breaking Bad')
        ->first();

    expect($rule)->not->toBeNull()
        ->and($rule->new_only)->toBeTrue()
        ->and($rule->priority)->toBe(75)
        ->and($rule->start_early_seconds)->toBe(120)
        ->and($rule->end_late_seconds)->toBe(300);
});

it('warns when recordSeriesWithOptions is called without a dvr setting selected', function () {
    Livewire::test(BrowseShows::class)
        ->set('dvr_setting_id', null)
        ->call('recordSeriesWithOptions', 'Breaking Bad');

    expect(DvrRecordingRule::where('user_id', $this->user->id)->count())->toBe(0);
});
