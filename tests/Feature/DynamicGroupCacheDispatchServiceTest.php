<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Bus::fake() is mandatory — Playlist::factory()->create() fires
    // PlaylistCreated → SyncPipelineService → dispatch(ProcessM3uImport)
    // which hits Redis in real env. The dispatch-service tests themselves
    // never trigger DownloadCachedContentFile::dispatch (that's covered by
    // the Phase 2 command tests with their own Bus::fake()), so this only
    // needs to swallow the model-event-triggered side effect.
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->service = app(DynamicGroupCacheDispatchService::class);
});

it('findCompletedCache returns the Completed file for matching identity', function () {
    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    expect($this->service->findCompletedCache('movie', '550', null, null, null))->toBeInstanceOf(CachedContentFile::class)
        ->and($this->service->findCompletedCache('movie', '550', null, null, null)->id)->toBe($file->id);
});

it('findCompletedCache ignores quality when looking up (returns first match regardless of quality)', function () {
    $hdFile = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);
    $fourKFile = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '4K',
    ]);

    // No quality filter on the lookup — either match is acceptable.
    $found = $this->service->findCompletedCache('movie', '550', null, null, null);

    expect($found)->not->toBeNull()
        ->and(collect([$hdFile->id, $fourKFile->id])->contains($found->id))->toBeTrue();
});

it('findCompletedCache returns null when no Completed row matches', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'quality' => null,
    ]);

    expect($this->service->findCompletedCache('movie', '550', null, null, null))->toBeNull();
});

it('findCompletedCache returns null for non-Completed rows (Pending, Downloading, Failed)', function () {
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'status' => CachedContentFileStatus::Pending,
    ]);
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'status' => CachedContentFileStatus::Downloading,
    ]);
    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '552',
    ]);

    expect($this->service->findCompletedCache('movie', '550', null, null, null))->toBeNull()
        ->and($this->service->findCompletedCache('movie', '551', null, null, null))->toBeNull()
        ->and($this->service->findCompletedCache('movie', '552', null, null, null))->toBeNull();
});

it('findCompletedCache requires season + episode to match for episode content', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode',
        'tmdb_id' => '1399',
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    // Same tmdb, different season/episode → no match
    expect($this->service->findCompletedCache('episode', '1399', null, 1, 2))->toBeNull()
        ->and($this->service->findCompletedCache('episode', '1399', null, 2, 1))->toBeNull();

    // Exact match
    expect($this->service->findCompletedCache('episode', '1399', null, 1, 1))->not->toBeNull();
});

it('findCacheableRuleForChannel returns the matching cache-enabled rule', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'tmdb_id' => 550,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);
    $channel->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'name' => 'Top Movies',
                'cache_enabled' => true,
                'cache_prefer_quality_keyword' => '4K',
            ],
        ],
    ]);

    $found = $this->service->findCacheableRuleForChannel($channel->fresh());

    expect($found)->not->toBeNull()
        ->and($found['group']->id)->toBe($group->id)
        ->and($found['rule']['cache_enabled'])->toBeTrue()
        ->and($found['rule']['cache_prefer_quality_keyword'])->toBe('4K');
});

it('findCacheableRuleForChannel returns null when no rule has cache_enabled', function () {
    $channel = Channel::factory()->for($this->playlist)->create();
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);
    $channel->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'name' => 'Top Movies',
                'cache_enabled' => false,
            ],
        ],
    ]);

    expect($this->service->findCacheableRuleForChannel($channel->fresh()))->toBeNull();
});

it('findCacheableRuleForChannel returns null when channel has no dynamic groups', function () {
    $channel = Channel::factory()->for($this->playlist)->create();

    expect($this->service->findCacheableRuleForChannel($channel))->toBeNull();
});

it('findCacheableRuleForChannel returns null when playlist has no rule matching the group name', function () {
    $channel = Channel::factory()->for($this->playlist)->create();
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);
    $channel->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Different Name', 'cache_enabled' => true],
        ],
    ]);

    expect($this->service->findCacheableRuleForChannel($channel->fresh()))->toBeNull();
});

it('findCacheableRuleForChannel returns the first cache-enabled rule across multiple groups', function () {
    $channel = Channel::factory()->for($this->playlist)->create();
    $disabledGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Disabled Group',
    ]);
    $enabledGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Enabled Group',
    ]);
    $channel->dynamicGroups()->attach($disabledGroup);
    $channel->dynamicGroups()->attach($enabledGroup);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Disabled Group', 'cache_enabled' => false],
            ['name' => 'Enabled Group', 'cache_enabled' => true, 'cache_prefer_quality_keyword' => '4K'],
        ],
    ]);

    $found = $this->service->findCacheableRuleForChannel($channel->fresh());

    expect($found)->not->toBeNull()
        ->and($found['group']->id)->toBe($enabledGroup->id)
        ->and($found['rule']['cache_prefer_quality_keyword'])->toBe('4K');
});

it('findCacheableRuleForEpisode goes through the parent series', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'tmdb_id' => 1399,
    ]);
    $episode = Episode::factory()->for($this->playlist)->for($series)->create([
        'season' => 1,
        'episode_num' => 1,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'tmdb',
        'name' => 'Top Series',
    ]);
    $series->dynamicGroups()->attach($group);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Top Series', 'cache_enabled' => true],
        ],
    ]);

    $found = $this->service->findCacheableRuleForEpisode($episode->fresh());

    expect($found)->not->toBeNull()
        ->and($found['group']->id)->toBe($group->id);
});

it('findCacheableRuleForEpisode returns null when series has no dynamic groups', function () {
    $series = Series::factory()->for($this->playlist)->create();
    $episode = Episode::factory()->for($this->playlist)->for($series)->create();

    expect($this->service->findCacheableRuleForEpisode($episode))->toBeNull();
});

it('resolveQuality returns the rule cache_prefer_quality_keyword when set', function () {
    expect($this->service->resolveQuality(['cache_prefer_quality_keyword' => '4K']))->toBe('4K')
        ->and($this->service->resolveQuality(['cache_prefer_quality_keyword' => '1080p']))->toBe('1080p');
});

it('resolveQuality returns null when the rule does not set the keyword', function () {
    expect($this->service->resolveQuality([]))->toBeNull()
        ->and($this->service->resolveQuality(['cache_enabled' => true]))->toBeNull();
});

it('shouldSkip returns false when no existing row matches the fingerprint', function () {
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();
});

it('shouldSkip returns true when an existing row is Completed (cross-playlist dedup)', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeTrue();
});

it('shouldSkip returns true when a Failed row is within the retry cooldown window', function () {
    app(GeneralSettings::class)->refresh();

    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 0,
        'last_failed_at' => now()->subMinutes(30),
    ]);

    // Default retry_cooldown_minutes = 360 (6h) → 30 min ago is still in cooldown
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeTrue();
});

it('shouldSkip returns false when a Failed row is past the retry cooldown window', function () {
    app(GeneralSettings::class)->refresh();

    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 0,
        'last_failed_at' => now()->subHours(7),
    ]);

    // Past the 360-minute (6h) retry window
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();
});

it('shouldSkip uses the failure_cooldown_hours for high failure_count rows', function () {
    app(GeneralSettings::class)->refresh();

    CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 5,
        'last_failed_at' => now()->subHours(12),
    ]);

    // Default failure_cooldown_hours = 24, so 12h ago is still in cooldown
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeTrue();
});

it('shouldSkip returns false for Pending and Downloading rows', function () {
    CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'status' => CachedContentFileStatus::Pending,
    ]);

    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();

    CachedContentFile::query()->update(['status' => CachedContentFileStatus::Downloading]);

    expect($this->service->shouldSkip($fingerprint))->toBeFalse();
});

it('resolveRuleForGroup is publicly callable (Phase 4 visibility widening)', function () {
    // The Phase 4 "Cached / Total" column on both legacy DynamicGroupsWidget
    // files and on the per-type VodDynamicGroupsListTest /
    // SeriesDynamicGroupsListTest listing pages.
    // and the "Select Content" picker both call this from outside the service.
    // A pure visibility change (`private` -> `public`) should not affect
    // behavior — assert it still resolves the same way.
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Top Movies',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Top Movies', 'cache_enabled' => true, 'cache_prefer_quality_keyword' => '4K'],
        ],
    ]);

    expect($this->service->resolveRuleForGroup($group->fresh()))->not->toBeNull()
        ->and($this->service->resolveRuleForGroup($group->fresh())['cache_enabled'])->toBeTrue()
        ->and($this->service->resolveRuleForGroup($group->fresh())['cache_prefer_quality_keyword'])->toBe('4K');
});

it('resolveGroupForRule returns the matching DynamicGroup for a playlist + rule name', function () {
    // Reverse of resolveRuleForGroup(): given a playlist_id and a rule with
    // a `name`, find the materialized DynamicGroup row. Used by the Phase 4
    // picker UI which lives inside a Repeater item (no direct handle to the
    // DynamicGroup, just the rule's name).
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Top Movies',
    ]);

    expect(
        $this->service->resolveGroupForRule($this->playlist->id, ['name' => 'Top Movies']),
    )->not->toBeNull()
        ->and($this->service->resolveGroupForRule($this->playlist->id, ['name' => 'Top Movies'])->id)
        ->toBe($group->id);
});

it('resolveGroupForRule returns null when no DynamicGroup exists for that name', function () {
    // The rule may be brand-new (no SyncDynamicGroups run yet) — the picker
    // must handle null gracefully, so the lookup itself must return null
    // rather than throwing.
    expect(
        $this->service->resolveGroupForRule($this->playlist->id, ['name' => 'Never Synced']),
    )->toBeNull();
});

it('resolveGroupForRule returns null when the rule has no name', function () {
    // Defensive: malformed rule (missing `name`) must not crash.
    expect(
        $this->service->resolveGroupForRule($this->playlist->id, []),
    )->toBeNull()
        ->and($this->service->resolveGroupForRule($this->playlist->id, ['name' => '']))->toBeNull();
});

it('dispatchForGroup returns cache_enabled=false with a reason when the group has no playlist', function () {
    $group = new DynamicGroup(['name' => 'Orphan', 'type' => 'vod', 'source' => 'popular', 'playlist_id' => null]);
    $result = $this->service->dispatchForGroup($group);

    expect($result)->toBe(['dispatched' => 0, 'cache_enabled' => false, 'reason' => 'Group has no playlist.']);
});

it('dispatchForGroup returns cache_enabled=false when the group has no matching rule', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Unconfigured',
    ]);
    // dynamic_groups_config empty → resolveRuleForGroup returns null
    $this->playlist->update(['dynamic_groups_config' => []]);

    $result = $this->service->dispatchForGroup($group);

    expect($result)->toBe(['dispatched' => 0, 'cache_enabled' => false, 'reason' => 'No cache rule for this group.']);
});

it('dispatchForGroup returns cache_enabled=false when the matching rule has cache_enabled=false', function () {
    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => false],
    ]]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Trending',
    ]);

    $result = $this->service->dispatchForGroup($group);

    expect($result['dispatched'])->toBe(0)
        ->and($result['cache_enabled'])->toBeFalse()
        ->and($result['reason'])->toContain('Cache is not enabled for this group');
});

it('dispatchForGroup queues one job per eligible channel in a vod group', function () {
    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => true],
    ]]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Trending',
    ]);
    // Channels attach to a DynamicGroup via the dynamic_group_items pivot
    // (MorphToMany), not via the `group` string field — that field is a
    // legacy Channel-side categorization. Use the relation to attach.
    $channels = Channel::factory()->for($this->playlist)->count(3)->create([
        'tmdb_id' => '100',
    ]);
    $group->channels()->attach($channels->pluck('id')->all());

    $result = $this->service->dispatchForGroup($group->fresh());

    // Assert on the service's reported count (the contract the Filament
    // action surfaces to its notification). The actual Bus::fake assertion
    // for dispatch plumbing is covered by a separate test — pendingDispatch
    // destructors in a tight loop don't reliably surface to Bus assertions
    // because their GC timing depends on PHP's reference counting.
    expect($result['dispatched'])->toBe(3)
        ->and($result['cache_enabled'])->toBeTrue()
        ->and($result['reason'])->toBeNull();
});

it('dispatchForGroup creates Pending tracking rows immediately, before any job runs', function () {
    // Regression test: DownloadCachedContentFile previously only created its
    // tracking row after clearing the concurrency gate inside handle(), so a
    // throttled job (no free Downloading slot) had no row at all and was
    // invisible to the Cache Activity widget. dispatchJob() now creates the
    // row up front in Pending status. Bus::fake() means the job body never
    // runs here, so any row found afterwards can only have come from dispatch.
    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => true],
    ]]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Trending',
    ]);
    $channels = collect();
    foreach (['200', '201', '202'] as $tmdbId) {
        $channels->push(Channel::factory()->for($this->playlist)->create(['tmdb_id' => $tmdbId]));
    }
    $group->channels()->attach($channels->pluck('id')->all());

    $this->service->dispatchForGroup($group->fresh());

    expect(CachedContentFile::where('status', CachedContentFileStatus::Pending)->count())->toBe(3);
    foreach (['200', '201', '202'] as $tmdbId) {
        $file = CachedContentFile::where('tmdb_id', $tmdbId)->first();
        expect($file)->not->toBeNull()
            ->and($file->status)->toBe(CachedContentFileStatus::Pending)
            ->and($file->dynamicGroups->pluck('id')->all())->toBe([$group->id]);
    }
});

it('dispatchForGroup skips already-completed channels and reports accurate count', function () {
    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => true],
    ]]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Trending',
    ]);
    // 3 channels with distinct TMDB IDs so each has a unique fingerprint;
    // pre-cache the first one so shouldSkip bails on it but the others fire.
    $channels = collect();
    foreach (['100', '101', '102'] as $tmdbId) {
        $channels->push(Channel::factory()->for($this->playlist)->create(['tmdb_id' => $tmdbId]));
    }
    $group->channels()->attach($channels->pluck('id')->all());
    // Pre-cache tmdb=100 only → shouldSkip fires for one, not the other two.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '100',
        'content_fingerprint' => 'movie:100::::',
    ]);

    $result = $this->service->dispatchForGroup($group->fresh());

    // 3 channels but 1 already completed → 2 should fire.
    expect($result['dispatched'])->toBe(2)
        ->and($result['cache_enabled'])->toBeTrue();
});

it('dispatchForGroup returns dispatched=0 with reason when all channels are already cached', function () {
    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => true],
    ]]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Trending',
    ]);
    $channels = Channel::factory()->for($this->playlist)->count(2)->create([
        'tmdb_id' => '200',
    ]);
    $group->channels()->attach($channels->pluck('id')->all());
    // Pre-cache all → shouldSkip bails on every channel.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '200',
        'content_fingerprint' => 'movie:200::::',
    ]);

    $result = $this->service->dispatchForGroup($group->fresh());

    expect($result['dispatched'])->toBe(0)
        ->and($result['cache_enabled'])->toBeTrue()
        ->and($result['reason'])->toContain('All eligible content is already cached or in cooldown');
});
