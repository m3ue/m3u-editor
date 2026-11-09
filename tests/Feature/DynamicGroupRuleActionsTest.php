<?php

use App\Enums\SyncRunPhase;
use App\Filament\Resources\DynamicGroups\Pages\ViewDynamicGroup;
use App\Filament\Resources\SeriesDynamicGroups\Pages\ListSeriesDynamicGroups;
use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Jobs\SyncDynamicGroups;
use App\Jobs\UpdateXtreamStats;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled, and the listings also need a configured TMDB.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    Bus::fake();
    Http::preventStrayRequests();

    $this->tmdb = Mockery::mock(TmdbService::class);
    $this->tmdb->shouldReceive('isConfigured')->andReturn(true);
    $this->tmdb->shouldReceive('collectDynamicGroupResults')
        ->andReturn([
            ['tmdb_id' => '550', 'title' => 'Fight Club', 'year' => '1999'],
            ['tmdb_id' => '680', 'title' => 'Pulp Fiction', 'year' => '1994'],
        ])
        ->byDefault();
    app()->instance(TmdbService::class, $this->tmdb);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly(['dynamic_groups_config' => null]);

    $this->channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '550',
        'name' => 'Fight Club',
        'name_custom' => null,
    ]);
});

function dynamicGroupRuleActionsRule(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => ['time_window' => 'week', 'pages' => 3],
    ], $overrides);
}

function dynamicGroupRuleActionsGroup($t, string $name = 'Trending Now', array $attributes = []): DynamicGroup
{
    return DynamicGroup::create(array_merge([
        'playlist_id' => $t->playlist->id,
        'user_id' => $t->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => $name,
        'enabled' => true,
    ], $attributes));
}

// --- Create -------------------------------------------------------------------

it('creates a rule with its caching options without calling TMDB, and the queued refresh fills it', function () {
    $this->tmdb->shouldNotReceive('collectDynamicGroupResults');

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction('create', data: [
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'source' => 'trending',
            'tmdb_params' => ['time_window' => 'day', 'pages' => 2],
            'name' => '  Trending Now  ',
            'cache_enabled' => true,
            'cache_keep_days' => 7,
            'cache_max_items' => 5,
        ])
        ->assertHasNoActionErrors();

    $rule = $this->playlist->fresh()->dynamic_groups_config[0];
    expect($rule)->toMatchArray([
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'cache_enabled' => true,
        'cache_never_expire' => false,
        'cache_keep_days' => 7,
        'cache_max_items' => 5,
    ])
        ->and($rule['tmdb_params'])->toMatchArray(['time_window' => 'day', 'pages' => 2])
        ->and($rule)->not->toHaveKey('playlist_id');

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->sole();
    expect($group->name)->toBe('Trending Now')
        ->and($group->enabled)->toBeTrue()
        ->and($group->last_synced_at)->toBeNull()
        ->and($group->channels()->count())->toBe(0);

    Bus::assertDispatched(SyncDynamicGroups::class, fn (SyncDynamicGroups $job): bool => $job->playlistId === $this->playlist->id
        && $job->refreshMembership
        && $job->delay !== null);

    // The queued refresh is what calls TMDB.
    $this->tmdb->shouldReceive('collectDynamicGroupResults')
        ->andReturn([['tmdb_id' => '550', 'title' => 'Fight Club', 'year' => '1999']]);
    (new SyncDynamicGroups($this->playlist->id))->handle();

    expect($group->fresh()->last_synced_at)->not->toBeNull()
        ->and($group->channels()->pluck('channels.id')->all())->toBe([$this->channel->id]);
});

it('creates a disabled rule as a turned-off group without calling TMDB', function () {
    $this->tmdb->shouldNotReceive('collectDynamicGroupResults');

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction('create', data: [
            'playlist_id' => $this->playlist->id,
            'enabled' => false,
            'source' => 'trending',
            'tmdb_params' => ['time_window' => 'week'],
            'name' => 'Paused',
        ])
        ->assertHasNoActionErrors();

    expect($this->playlist->fresh()->dynamic_groups_config[0]['enabled'])->toBeFalse();

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->sole();
    expect($group->enabled)->toBeFalse()
        ->and($group->channels()->count())->toBe(0);
});

it('creates a series rule from the series listing with the type locked to series', function () {
    Livewire::test(ListSeriesDynamicGroups::class)
        ->callAction('create', data: [
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'source' => 'popular',
            'name' => 'Popular Shows',
        ])
        ->assertHasNoActionErrors();

    expect($this->playlist->fresh()->dynamic_groups_config[0])->toMatchArray([
        'type' => 'series',
        'source' => 'popular',
        'name' => 'Popular Shows',
    ])
        ->and(DynamicGroup::where('playlist_id', $this->playlist->id)->sole()->type)->toBe('series');
});

it('rejects a rule that duplicates an existing name and source on the playlist', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction('create', data: [
            'playlist_id' => $this->playlist->id,
            'enabled' => true,
            'source' => 'trending',
            'tmdb_params' => ['time_window' => 'week'],
            'name' => 'Trending Now',
        ])
        ->assertNotified(__('Dynamic Group not saved'));

    expect($this->playlist->fresh()->dynamic_groups_config)->toHaveCount(1)
        ->and(DynamicGroup::count())->toBe(0);
});

// --- Edit ---------------------------------------------------------------------

it('fills the edit form from the rule and keeps the group id when renamed', function () {
    $other = dynamicGroupRuleActionsRule(['source' => 'popular', 'name' => 'Other', 'tmdb_params' => ['pages' => 3]]);
    $this->playlist->updateQuietly(['dynamic_groups_config' => [
        $other,
        dynamicGroupRuleActionsRule(['cache_enabled' => true, 'cache_never_expire' => false, 'cache_keep_days' => 3, 'cache_max_items' => 20]),
    ]]);
    $group = dynamicGroupRuleActionsGroup($this);
    $group->channels()->attach($this->channel);
    $this->tmdb->shouldNotReceive('collectDynamicGroupResults');

    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->assertSchemaStateSet([
            'playlist_id' => $this->playlist->id,
            'name' => 'Trending Now',
            'cache_enabled' => true,
            'cache_keep_days' => 3,
        ])
        ->fillForm(['name' => 'Hot Right Now', 'cache_max_items' => 10])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $config = $this->playlist->fresh()->dynamic_groups_config;
    expect($config)->toHaveCount(2)
        ->and($config[0])->toEqual($other)
        ->and($config[1])->toMatchArray(['name' => 'Hot Right Now', 'cache_keep_days' => 3, 'cache_max_items' => 10]);

    $renamed = DynamicGroup::where('playlist_id', $this->playlist->id)->sole();
    expect($renamed->id)->toBe($group->id)
        ->and($renamed->name)->toBe('Hot Right Now')
        ->and($renamed->sort_order)->toBe(1)
        ->and($renamed->channels()->count())->toBe(1);
});

it('turns a group off from the edit action and back on again', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);
    $group->channels()->attach($this->channel);

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction(TestAction::make('edit')->table($group), data: ['enabled' => false])
        ->assertHasNoActionErrors();

    expect($group->fresh()->enabled)->toBeFalse()
        ->and($group->channels()->count())->toBe(0)
        ->and($this->playlist->fresh()->dynamic_groups_config[0]['enabled'])->toBeFalse();

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction(TestAction::make('edit')->table($group->fresh()), data: ['enabled' => true])
        ->assertHasNoActionErrors();

    // Members come back with the queued refresh, not on save.
    expect($group->fresh()->enabled)->toBeTrue()
        ->and($group->channels()->count())->toBe(0);
});

it('keeps one waiting refresh per playlist however many saves queue one', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction(TestAction::make('edit')->table($group), data: ['name' => 'First'])
        ->callAction(TestAction::make('edit')->table($group->fresh()), data: ['name' => 'Second']);
    SyncDynamicGroups::queueRefresh($this->playlist->id);

    expect($group->fresh()->name)->toBe('Second');
    Bus::assertDispatchedTimes(SyncDynamicGroups::class, 1);
});

it('never folds a pipeline run into a waiting refresh', function () {
    SyncDynamicGroups::queueRefresh($this->playlist->id);
    dispatch(new SyncDynamicGroups(
        playlistId: $this->playlist->id,
        syncRunId: 123,
        completionPhase: SyncRunPhase::DynamicGroups,
    ));

    Bus::assertDispatchedTimes(SyncDynamicGroups::class, 2);
});

it('hides edit for a row whose rule is no longer in the playlist config', function () {
    $group = dynamicGroupRuleActionsGroup($this);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionHidden('edit', $group);
});

it('blocks renaming onto another row that holds the same name and source', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);
    // Rule-less row (its rule was removed on the Playlist form, not re-synced yet).
    dynamicGroupRuleActionsGroup($this, 'Leftover');

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction(TestAction::make('edit')->table($group), data: ['name' => 'Leftover'])
        ->assertNotified(__('Dynamic Group not saved'));

    expect($group->fresh()->name)->toBe('Trending Now')
        ->and($this->playlist->fresh()->dynamic_groups_config[0]['name'])->toBe('Trending Now');
});

it('offers edit on the View page', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);

    Livewire::test(ViewDynamicGroup::class, ['record' => $group->id])
        ->callAction('edit', data: ['name' => 'Renamed'])
        ->assertHasNoActionErrors();

    expect($group->fresh()->name)->toBe('Renamed');
});

// --- Preview ------------------------------------------------------------------

it('previews the rule from the create slide-over once a playlist is picked', function () {
    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction('create')
        ->assertSchemaComponentHidden('dynamic_group_preview')
        ->fillForm(['playlist_id' => $this->playlist->id, 'source' => 'trending', 'name' => 'Trending Now'])
        ->assertActionVisible(TestAction::make('preview_dynamic_group')->schemaComponent('dynamic_group_preview'))
        ->mountAction(TestAction::make('preview_dynamic_group')->schemaComponent('dynamic_group_preview'))
        ->assertMountedActionModalSee(['Preview: Trending Now', 'Fight Club', 'Pulp Fiction']);
});

it('previews the rule from the edit slide-over against the row\'s playlist', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);

    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->mountAction(TestAction::make('preview_dynamic_group')->schemaComponent('dynamic_group_preview'))
        ->assertMountedActionModalSee(['Fight Club', 'Pulp Fiction']);
});

it('turns the row off when a rule disabled on the playlist is re-saved from the edit action', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule(['enabled' => false])]]);
    $group = dynamicGroupRuleActionsGroup($this);
    $group->channels()->attach($this->channel);
    $this->tmdb->shouldNotReceive('collectDynamicGroupResults');

    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->assertSchemaStateSet(['enabled' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($group->fresh()->enabled)->toBeFalse();
});

it('loads only the playlist columns the listing needs, so serializing a row never queues UpdateXtreamStats', function () {
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);

    $record = Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionVisible('edit', $group)
        ->instance()
        ->getTableRecords()
        ->sole();

    expect(array_keys($record->playlist->getAttributes()))
        ->toEqualCanonicalizing(['id', 'name', 'dynamic_groups_config']);

    $record->toArray();
    Bus::assertNotDispatched(UpdateXtreamStats::class);

    // The full row is what queues it.
    $this->playlist->fresh()->toArray();
    Bus::assertDispatched(UpdateXtreamStats::class);
});

// --- Global caching toggle ----------------------------------------------------

it('locks the caching toggle off while caching is off in Settings and keeps the rule\'s stored cache options on save', function () {
    app(GeneralSettings::class)->enable_cache = false;
    $cacheOptions = ['cache_enabled' => true, 'cache_never_expire' => true, 'cache_keep_days' => 3, 'cache_max_items' => 10];
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule($cacheOptions)]]);
    $group = dynamicGroupRuleActionsGroup($this);
    $this->tmdb->shouldNotReceive('collectDynamicGroupResults');

    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->assertFormFieldHidden('cache_enabled')
        ->assertFormFieldVisible('cache_enabled_locked')
        ->assertFormFieldDisabled('cache_enabled_locked')
        ->assertSchemaStateSet(['cache_enabled_locked' => false])
        ->assertFormFieldHidden('cache_never_expire')
        ->assertFormFieldHidden('cache_keep_days')
        ->assertFormFieldHidden('cache_max_items')
        ->fillForm(['name' => 'Hot Right Now'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $rule = $this->playlist->fresh()->dynamic_groups_config[0];
    expect($rule)->toMatchArray(['name' => 'Hot Right Now', ...$cacheOptions])
        ->and($rule)->not->toHaveKey('cache_enabled_locked');
});

it('does not add cache options to a rule that has none while caching is off in Settings', function () {
    app(GeneralSettings::class)->enable_cache = false;
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule()]]);
    $group = dynamicGroupRuleActionsGroup($this);

    Livewire::test(ListVodDynamicGroups::class)
        ->callAction(TestAction::make('edit')->table($group), data: ['name' => 'Hot Right Now'])
        ->assertHasNoActionErrors();

    expect($this->playlist->fresh()->dynamic_groups_config[0])
        ->toMatchArray(['name' => 'Hot Right Now', 'cache_enabled' => false])
        ->not->toHaveKeys(['cache_never_expire', 'cache_keep_days', 'cache_max_items', 'cache_enabled_locked']);
});

it('offers the caching toggle while caching is on in Settings', function () {
    app(GeneralSettings::class)->enable_cache = true;
    $this->playlist->updateQuietly(['dynamic_groups_config' => [dynamicGroupRuleActionsRule(['cache_enabled' => true])]]);
    $group = dynamicGroupRuleActionsGroup($this);

    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction(TestAction::make('edit')->table($group))
        ->assertFormFieldVisible('cache_enabled')
        ->assertFormFieldHidden('cache_enabled_locked')
        ->assertFormFieldVisible('cache_max_items');
});

it('shows Caching Enabled off for every row while caching is off in Settings', function (bool $cachingEnabled) {
    app(GeneralSettings::class)->enable_cache = $cachingEnabled;
    $this->playlist->updateQuietly(['dynamic_groups_config' => [
        dynamicGroupRuleActionsRule(['cache_enabled' => true]),
        dynamicGroupRuleActionsRule(['name' => 'Not Cached']),
    ]]);
    $cached = dynamicGroupRuleActionsGroup($this);
    $notCached = dynamicGroupRuleActionsGroup($this, 'Not Cached');

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('cache_enabled', $cachingEnabled, $cached)
        ->assertTableColumnStateSet('cache_enabled', false, $notCached);
})->with([
    'caching on' => true,
    'caching off' => false,
]);
