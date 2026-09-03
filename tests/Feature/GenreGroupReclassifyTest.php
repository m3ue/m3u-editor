<?php

use App\Filament\Resources\VodGroups\Pages\EditVodGroup;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Jobs\FetchTmdbIds;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\GenreGroupReclassifyService;
use App\Services\TmdbService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

/**
 * Mock TmdbService so isConfigured() returns true and the canonical
 * movie/tv genre lists return a fixed small set.
 */
function mockTmdbForGenreTest(array $movieNames = ['Action', 'Drama', 'Comedy'], array $tvNames = ['Action & Adventure', 'Drama', 'Comedy']): void
{
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldReceive('getMovieGenres')->andReturn(
        collect($movieNames)->map(fn ($n, $i) => ['id' => $i + 1, 'name' => $n])->all()
    );
    $tmdb->shouldReceive('getTvGenres')->andReturn(
        collect($tvNames)->map(fn ($n, $i) => ['id' => $i + 1, 'name' => $n])->all()
    );

    app()->instance(TmdbService::class, $tmdb);
}

/**
 * Refresh a channel from the database.
 */
function refreshChannel(Channel $channel): Channel
{
    return $channel->refresh();
}

/**
 * Channel factory default is `enabled` = random boolean. The reclassify service
 * only touches enabled content, so tests must explicitly opt in.
 */
function enabledChannel(Playlist $playlist, Group $group, User $user, array $attrs = []): Channel
{
    return Channel::factory()->for($user)->for($playlist)->for($group, 'group')->create(
        array_merge(['enabled' => true], $attrs),
    );
}

function disabledChannel(Playlist $playlist, Group $group, User $user, array $attrs = []): Channel
{
    return Channel::factory()->for($user)->for($playlist)->for($group, 'group')->create(
        array_merge(['enabled' => false], $attrs),
    );
}

function enabledSeries(Playlist $playlist, Category $category, User $user, array $attrs = []): Series
{
    return Series::factory()->for($user)->for($playlist)->for($category, 'category')->create(
        array_merge(['enabled' => true], $attrs),
    );
}

function disabledSeries(Playlist $playlist, Category $category, User $user, array $attrs = []): Series
{
    return Series::factory()->for($user)->for($playlist)->for($category, 'category')->create(
        array_merge(['enabled' => false], $attrs),
    );
}

// ────────────────────────────────────────────────────────────────────────────
// Item-level VOD routing tests
// ────────────────────────────────────────────────────────────────────────────

it('routes each VOD channel to its own genre group (mixed-genre case — CJ\'s bug)', function () {
    mockTmdbForGenreTest();

    // "New Releases" group: one Action channel, one Comedy channel, one with no genre.
    $newReleases = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod',
        'name' => 'New Releases',
        'name_internal' => 'New Releases',
    ]);

    $actionCh = enabledChannel($this->playlist, $newReleases, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);
    $comedyCh = enabledChannel($this->playlist, $newReleases, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Comedy'],
    ]);
    $noGenreCh = enabledChannel($this->playlist, $newReleases, $this->user, [
        'is_vod' => true,
        'info' => null,
    ]);

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    $actionGroup = Group::where('name', 'Action')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();
    $comedyGroup = Group::where('name', 'Comedy')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();
    $uncategorized = Group::where('name', 'Uncategorized')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();

    expect($actionGroup)->not->toBeNull()
        ->and($comedyGroup)->not->toBeNull()
        ->and($uncategorized)->not->toBeNull()
        ->and($result['moved'])->toBe(3)
        ->and((int) refreshChannel($actionCh)->group_id)->toBe((int) $actionGroup->id)
        ->and((int) refreshChannel($comedyCh)->group_id)->toBe((int) $comedyGroup->id)
        ->and((int) refreshChannel($noGenreCh)->group_id)->toBe((int) $uncategorized->id)
        // Original "New Releases" group still exists, just empty.
        ->and($newReleases->refresh()->exists())->toBeTrue()
        ->and(Channel::where('group_id', $newReleases->id)->count())->toBe(0);
});

it('sends a VOD channel with no genre data to Uncategorized', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Whatever',
    ]);
    $noInfo = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => null,
    ]);
    $infoButEmpty = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => null],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    $uncategorized = Group::where('name', 'Uncategorized')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();
    expect($uncategorized)->not->toBeNull()
        ->and((int) refreshChannel($noInfo)->group_id)->toBe((int) $uncategorized->id)
        ->and((int) refreshChannel($infoButEmpty)->group_id)->toBe((int) $uncategorized->id);
});

it('recovers already-misfiled VOD channels from Uncategorized (CJ\'s data recovery)', function () {
    mockTmdbForGenreTest();

    // Pretend an earlier buggy reclassify pass parked them all in "Uncategorized".
    $uncategorized = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Uncategorized',
    ]);
    $ch = enabledChannel($this->playlist, $uncategorized, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Drama'],
    ]);

    // Re-run with the fix in place — should pull the channel out to a Drama group.
    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    $dramaGroup = Group::where('name', 'Drama')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();
    expect($dramaGroup)->not->toBeNull()
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $dramaGroup->id)
        ->and((int) refreshChannel($ch)->group_id)->not->toBe((int) $uncategorized->id);
});

it('does not churn a correctly-placed VOD channel (updated_at stays put)', function () {
    mockTmdbForGenreTest();

    $action = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Action',
    ]);
    $ch = enabledChannel($this->playlist, $action, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);
    $originalUpdatedAt = $ch->updated_at;

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect(refreshChannel($ch)->updated_at->equalTo($originalUpdatedAt))
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $action->id);
});

it('reuses an existing case-variant group instead of creating a duplicate', function () {
    mockTmdbForGenreTest();

    $existing = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'action',
    ]);
    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Whatever',
    ]);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect(Group::where('type', 'vod')->where('playlist_id', $this->playlist->id)
        ->whereRaw('LOWER(name) = ?', ['action'])->count())->toBe(1)
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $existing->id);
});

it('uses canonical casing when creating a new VOD genre group', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Whatever',
    ]);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'comedy'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    $comedy = Group::where('name', 'Comedy')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();
    expect($comedy)->not->toBeNull()
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $comedy->id);
});

it('skips VOD channels whose current group is protected by auto_sync_to_custom_config', function () {
    mockTmdbForGenreTest();

    $protected = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Whatever',
    ]);
    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            [
                'enabled' => true,
                'type' => 'vod_groups',
                'group_filter' => 'selected',
                'groups' => [$protected->id],
            ],
        ],
    ]);

    $ch = enabledChannel($this->playlist, $protected, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result['moved'])->toBe(0)
        ->and($result['protected'])->toBe(1)
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $protected->id);
});

it('skips VOD channels folded into a merged group (membership points at a child row)', function () {
    mockTmdbForGenreTest();

    $merged = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'My Bucket', 'is_merged' => true, 'custom' => true,
    ]);
    // Real merged membership: the child row carries parent_id, is_merged = false.
    $child = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Some Provider Group', 'parent_id' => $merged->id,
    ]);
    $ch = enabledChannel($this->playlist, $child, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result['moved'])->toBe(0)
        ->and($result['protected'])->toBe(1)
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $child->id)
        ->and(Group::where('name', 'Action')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->exists())->toBeFalse();
});

it('never routes a VOD channel into a merged group, even one named after a genre', function () {
    mockTmdbForGenreTest();

    // A merged parent that happens to be named "Action".
    Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Action', 'is_merged' => true, 'custom' => true,
    ]);
    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Whatever',
    ]);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    // The service must create a fresh non-merged "Action" group rather than
    // assigning the channel to the merged container (which ChannelObserver rejects).
    $target = Group::where('name', 'Action')->where('type', 'vod')
        ->where('playlist_id', $this->playlist->id)->where('is_merged', false)->first();

    expect($target)->not->toBeNull()
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $target->id);
});

it('is a no-op when the TMDB genre lookup comes back empty (transient outage)', function () {
    // isConfigured() true, but the canonical genre lists are empty (HTTP failure).
    mockTmdbForGenreTest(movieNames: [], tvNames: []);

    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Action',
    ]);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result)->toMatchArray(['no_op' => true, 'moved' => 0])
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $src->id)
        ->and(Group::where('name', 'Uncategorized')->where('playlist_id', $this->playlist->id)->exists())->toBeFalse();
});

it('is a no-op for Series when the TMDB TV genre lookup comes back empty', function () {
    mockTmdbForGenreTest(movieNames: [], tvNames: []);

    $house = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Drama']);
    $s = enabledSeries($this->playlist, $house, $this->user, ['genre' => 'Drama']);

    $result = GenreGroupReclassifyService::reclassifyCategories($this->playlist);

    expect($result)->toMatchArray(['no_op' => true, 'moved' => 0])
        ->and((int) $s->refresh()->category_id)->toBe((int) $house->id)
        ->and(Category::where('name', 'Uncategorized')->where('playlist_id', $this->playlist->id)->exists())->toBeFalse();
});

it('does not rewrite a moved VOD channel\'s group_internal (provider group name)', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'New Releases',
    ]);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
        'group_internal' => 'Provider Bucket 7',
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    $moved = refreshChannel($ch);
    $action = Group::where('name', 'Action')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();

    expect((int) $moved->group_id)->toBe((int) $action->id)
        ->and($moved->group)->toBe('Action')
        // group_internal is the provider's own group name; the move action leaves it alone.
        ->and($moved->group_internal)->toBe('Provider Bucket 7');
});

it('treats Uncategorized + null genre as no-op routing (stays in Uncategorized)', function () {
    mockTmdbForGenreTest();

    $uncategorized = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Uncategorized',
    ]);
    $ch = enabledChannel($this->playlist, $uncategorized, $this->user, [
        'is_vod' => true,
        'info' => null,
    ]);
    $originalUpdatedAt = $ch->updated_at;

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result['moved'])->toBe(0)
        ->and(refreshChannel($ch)->updated_at->equalTo($originalUpdatedAt))
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $uncategorized->id);
});

it('is a no-op when TMDB is not configured', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(TmdbService::class, $tmdb);

    $src = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Whatever',
    ]);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result)->toMatchArray(['no_op' => true, 'moved' => 0])
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $src->id);
});

// ────────────────────────────────────────────────────────────────────────────
// Enabled-only scoping tests (Phase 2 Follow-up)
// ────────────────────────────────────────────────────────────────────────────

it('does not reclassify disabled VOD channels even when their genre would route them elsewhere', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $disabled = disabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result['moved'])->toBe(0)
        ->and((int) refreshChannel($disabled)->group_id)->toBe((int) $src->id)
        ->and(Group::where('name', 'Action')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->exists())->toBeFalse();
});

it('does not pull disabled VOD channels out of Uncategorized (inverse of the recovery test)', function () {
    mockTmdbForGenreTest();

    $uncategorized = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Uncategorized',
    ]);
    $disabled = disabledChannel($this->playlist, $uncategorized, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Drama'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect((int) refreshChannel($disabled)->group_id)->toBe((int) $uncategorized->id)
        ->and(Group::where('name', 'Drama')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->exists())->toBeFalse();
});

it('does not reclassify disabled Series even when their genre would route them elsewhere', function () {
    mockTmdbForGenreTest();

    $house = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Whatever']);
    $disabled = disabledSeries($this->playlist, $house, $this->user, ['genre' => 'Drama']);

    $result = GenreGroupReclassifyService::reclassifyCategories($this->playlist);

    expect($result['moved'])->toBe(0)
        ->and((int) $disabled->refresh()->category_id)->toBe((int) $house->id)
        ->and(Category::where('name', 'Drama')->exists())->toBeFalse();
});

// ────────────────────────────────────────────────────────────────────────────
// Chunking + query-efficiency tests (Phase 2 Follow-up)
// ────────────────────────────────────────────────────────────────────────────

it('correctly routes channels across chunk boundaries (>100 records, single chunk size)', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    // 250 channels across 3 chunks at chunk-size=100.
    $channels = collect();
    foreach (['Action', 'Drama', 'Comedy'] as $genre) {
        foreach (range(1, 80) as $i) {
            $channels->push(enabledChannel($this->playlist, $src, $this->user, [
                'is_vod' => true,
                'info' => ['genre' => $genre],
            ]));
        }
    }
    // 3 genres × 80 = 240 channels, plus 10 no-genre channels.
    foreach (range(1, 10) as $i) {
        $channels->push(enabledChannel($this->playlist, $src, $this->user, [
            'is_vod' => true,
            'info' => null,
        ]));
    }

    $result = GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect($result['moved'])->toBe(250)
        ->and(Group::where('name', 'Action')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first()->id)->not->toBeNull()
        ->and(Group::where('name', 'Drama')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first()->id)->not->toBeNull()
        ->and(Group::where('name', 'Comedy')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first()->id)->not->toBeNull()
        ->and(Group::where('name', 'Uncategorized')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first()->id)->not->toBeNull()
        ->and(Channel::where('group_id', $src->id)->count())->toBe(0)
        // Spot-check distribution: 80 channels per genre.
        ->and(Channel::whereIn('group_id', Group::where('playlist_id', $this->playlist->id)->pluck('id'))->where('group', 'Action')->count())->toBe(80)
        ->and(Channel::whereIn('group_id', Group::where('playlist_id', $this->playlist->id)->pluck('id'))->where('group', 'Drama')->count())->toBe(80)
        ->and(Channel::whereIn('group_id', Group::where('playlist_id', $this->playlist->id)->pluck('id'))->where('group', 'Comedy')->count())->toBe(80)
        ->and(Channel::whereIn('group_id', Group::where('playlist_id', $this->playlist->id)->pluck('id'))->where('group', 'Uncategorized')->count())->toBe(10);
});

it('does not re-query groups/categories per channel (no N+1)', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    foreach (range(1, 50) as $i) {
        enabledChannel($this->playlist, $src, $this->user, [
            'is_vod' => true,
            'info' => ['genre' => 'Action'],
        ]);
    }

    // Snapshot the query count around the reclassify call. We don't try to prove
    // "fewer than N queries" (impossible — every channel update needs one query
    // minimum, plus the existing ChannelObserver::saving does a pre-existing
    // per-channel `select is_merged ... limit 1` defensive check on every save —
    // see app/Observers/ChannelObserver.php:46-59). What we prove instead: no
    // EXTRA queries from the reclassify service itself. Pre-fix, the service ran:
    //   - $channel->group()->first()          → 1 query per channel (re-fetch group)
    //   - Group::query()->whereRaw(...)->first() → 1 query per channel (target lookup)
    //   - + the same N updates + observer is_merged checks
    // i.e. ~4N queries for N channels = 200 for 50 channels. With the in-memory
    // lookup, those ~2N queries are gone: only fixed-setup (1 group load, 1
    // chunkById, 1 group create for the new "Action" group, 1 fallback select
    // for the first channel) + N updates + N observer checks = 1 + 1 + 1 + 1 +
    // 2N. For N=50: 103 queries (1 setup + 1 chunk + 1 create + 1 lookup + 50
    // updates + 50 observer checks). Budget: 150 (well under the 200 pre-fix
    // baseline).
    $count = 0;
    $queries = [];
    DB::listen(function ($query) use (&$count, &$queries) {
        $count++;
        $queries[] = preg_replace('/\s+/', ' ', substr($query->sql, 0, 80));
    });

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    $breakdown = array_count_values($queries);

    // Pure reclassify-service queries (NOT including the pre-existing per-channel
    // `select is_merged ... limit 1` from ChannelObserver::saving):
    $serviceQueries = array_filter(
        $queries,
        fn ($sql) => ! str_contains($sql, 'select "is_merged" from "groups"')
            && ! str_starts_with($sql, 'update "channels"'),
    );

    expect($count)->toBeLessThan(150)
        ->and(count($serviceQueries))->toBeLessThan(10)
        // Zero per-channel `group()` re-queries (this was the original N+1 fix).
        ->and($breakdown['select * from "groups" where "groups"."id" = ? limit 1'] ?? 0)->toBe(0);
});

// ────────────────────────────────────────────────────────────────────────────
// Item-level Series routing tests
// ────────────────────────────────────────────────────────────────────────────

it('routes each Series to its own genre category (parallel to the VOD mixed-genre case)', function () {
    mockTmdbForGenreTest();

    $house = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Whatever']);
    $s1 = enabledSeries($this->playlist, $house, $this->user, ['genre' => 'Drama']);
    $s2 = enabledSeries($this->playlist, $house, $this->user, ['genre' => 'Comedy']);
    $s3 = enabledSeries($this->playlist, $house, $this->user, ['genre' => null]);

    $result = GenreGroupReclassifyService::reclassifyCategories($this->playlist);

    $drama = Category::where('name', 'Drama')->first();
    $comedy = Category::where('name', 'Comedy')->first();
    $uncategorized = Category::where('name', 'Uncategorized')->first();

    expect($drama)->not->toBeNull()
        ->and($comedy)->not->toBeNull()
        ->and($uncategorized)->not->toBeNull()
        ->and($result['moved'])->toBe(3)
        ->and((int) $s1->refresh()->category_id)->toBe((int) $drama->id)
        ->and((int) $s2->refresh()->category_id)->toBe((int) $comedy->id)
        ->and((int) $s3->refresh()->category_id)->toBe((int) $uncategorized->id)
        // The original Series.genre column was NOT rewritten by the reclassify.
        ->and($s1->refresh()->genre)->toBe('Drama')
        ->and($s2->refresh()->genre)->toBe('Comedy');
});

it('recovers already-misfiled Series from Uncategorized', function () {
    mockTmdbForGenreTest();

    $uncategorized = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Uncategorized']);
    $s = enabledSeries($this->playlist, $uncategorized, $this->user, ['genre' => 'Comedy']);

    GenreGroupReclassifyService::reclassifyCategories($this->playlist);

    $comedy = Category::where('name', 'Comedy')->first();
    expect($comedy)->not->toBeNull()
        ->and((int) $s->refresh()->category_id)->toBe((int) $comedy->id);
});

// ────────────────────────────────────────────────────────────────────────────
// FetchTmdbIds auto-trigger test
// ────────────────────────────────────────────────────────────────────────────

it('FetchTmdbIds::reclassifyGroupsForTouchedPlaylists() routes per-item, not per-group', function () {
    mockTmdbForGenreTest();

    $this->playlist->update(['reclassify_vod_groups_to_tmdb_genres' => true]);

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $actionCh = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Action'],
    ]);
    $comedyCh = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Comedy'],
    ]);

    $job = new FetchTmdbIds(vodPlaylistId: $this->playlist->id, user: $this->user);
    $ref = new ReflectionMethod($job, 'reclassifyGroupsForTouchedPlaylists');
    $ref->setAccessible(true);
    $ref->invoke($job);

    expect((int) refreshChannel($actionCh)->group_id)->toBe((int) Group::where('name', 'Action')->first()->id)
        ->and((int) refreshChannel($comedyCh)->group_id)->toBe((int) Group::where('name', 'Comedy')->first()->id);
});

it('does not run reclassify from FetchTmdbIds when the playlist flag is off', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Action'],
    ]);

    $job = new FetchTmdbIds(vodPlaylistId: $this->playlist->id, user: $this->user);
    $ref = new ReflectionMethod($job, 'reclassifyGroupsForTouchedPlaylists');
    $ref->setAccessible(true);
    $ref->invoke($job);

    expect($this->playlist->fresh()->reclassify_vod_groups_to_tmdb_genres)->toBeFalse()
        ->and((int) refreshChannel($ch)->group_id)->toBe((int) $src->id);
});

// ────────────────────────────────────────────────────────────────────────────
// ────────────────────────────────────────────────────────────────────────────
// `enabled` state on newly-created / existing groups & categories
// ────────────────────────────────────────────────────────────────────────────

it('creates newly-required VOD genre groups with enabled = true', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Action'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    // Sanity: the column default for groups is `false` (per the 2025_10_01
    // migration that introduced `enabled`). The service must override this for
    // groups it creates — see Group::create() block in the service. Use raw
    // attribute to assert the underlying column value (Group model has no
    // 'enabled' boolean cast, so the attribute comes back as int 0/1).
    $actionGroup = Group::where('name', 'Action')->where('type', 'vod')->where('playlist_id', $this->playlist->id)->first();
    expect((int) $actionGroup->getAttributes()['enabled'])->toBe(1);
});

it('creates newly-required Series categories with enabled = true', function () {
    mockTmdbForGenreTest();

    $house = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Whatever']);
    enabledSeries($this->playlist, $house, $this->user, ['genre' => 'Drama']);

    GenreGroupReclassifyService::reclassifyCategories($this->playlist);

    $dramaCategory = Category::where('name', 'Drama')->first();
    expect((int) $dramaCategory->getAttributes()['enabled'])->toBe(1);
});

it('does not modify the enabled state of a pre-existing enabled VOD genre group', function () {
    mockTmdbForGenreTest();

    // Pre-existing group is enabled = true — service must leave that alone.
    $existing = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Drama', 'enabled' => true,
    ]);
    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Drama'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    // Use raw attribute to avoid Eloquent's lack of an `enabled` boolean cast on
    // Group. The service must not have written to this row's `enabled` column.
    expect((int) $existing->refresh()->getAttributes()['enabled'])->toBe(1);
});

it('does NOT silently re-enable a pre-existing disabled VOD genre group', function () {
    mockTmdbForGenreTest();

    // Pre-existing group is enabled = false — this is the most dangerous case,
    // because the user's prior decision (or a pre-existing app-wide default)
    // was to disable this group. Reclassify must NOT silently flip it back on,
    // or it would make excluded content visible to the user again.
    $existing = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'vod', 'name' => 'Drama', 'enabled' => false,
    ]);
    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true,
        'info' => ['genre' => 'Drama'],
    ]);

    GenreGroupReclassifyService::reclassifyVodGroups($this->playlist);

    expect((int) $existing->refresh()->getAttributes()['enabled'])->toBe(0);
});

it('does not modify the enabled state of a pre-existing disabled Series category', function () {
    mockTmdbForGenreTest();

    $existing = Category::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Drama', 'enabled' => false,
    ]);
    $house = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Whatever']);
    enabledSeries($this->playlist, $house, $this->user, ['genre' => 'Drama']);

    GenreGroupReclassifyService::reclassifyCategories($this->playlist);

    expect((int) $existing->refresh()->getAttributes()['enabled'])->toBe(0);
});

// ────────────────────────────────────────────────────────────────────────────
// Filament action tests
// ────────────────────────────────────────────────────────────────────────────

it('moves each VOD channel to its own genre group via the single-row action on ListVodGroups', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $actionCh = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Action'],
    ]);

    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->callAction(TestAction::make('reclassify_tmdb_genres')->table($src))
        ->assertHasNoActionErrors();

    expect((int) refreshChannel($actionCh)->group_id)->toBe((int) Group::where('name', 'Action')->first()->id);
});

it('routes the playlist\'s VOD channels via the EditVodGroup header action', function () {
    mockTmdbForGenreTest();

    $src = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $ch = enabledChannel($this->playlist, $src, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Comedy'],
    ]);

    Livewire::test(EditVodGroup::class, ['record' => $src->id])
        ->callAction('reclassify_tmdb_genres');

    expect((int) refreshChannel($ch)->group_id)->toBe((int) Group::where('name', 'Comedy')->first()->id);
});

// ────────────────────────────────────────────────────────────────────────────
// Phase 5 — split reclassify toggle into VOD + Series
// ────────────────────────────────────────────────────────────────────────────

it('VOD toggle on, Series toggle off: reclassifies VOD channels but leaves Series alone', function () {
    mockTmdbForGenreTest();

    $this->playlist->update([
        'reclassify_vod_groups_to_tmdb_genres' => true,
        'reclassify_series_categories_to_tmdb_genres' => false,
    ]);

    // VOD channel in a non-genre-matching group
    $vodSrc = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $vodCh = enabledChannel($this->playlist, $vodSrc, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Action'],
    ]);

    // Series in a non-genre-matching category
    $seriesSrc = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Whatever']);
    $series = enabledSeries($this->playlist, $seriesSrc, $this->user, ['genre' => 'Action & Adventure']);

    $job = new FetchTmdbIds(vodPlaylistId: $this->playlist->id, user: $this->user);
    $ref = new ReflectionMethod($job, 'reclassifyGroupsForTouchedPlaylists');
    $ref->setAccessible(true);
    $ref->invoke($job);

    $actionGroup = Group::where('name', 'Action')->first();
    expect($actionGroup)->not->toBeNull()
        ->and((int) refreshChannel($vodCh)->group_id)->toBe((int) $actionGroup->id)
        // Series must NOT have moved — its category is still the original $seriesSrc
        ->and((int) $series->refresh()->category_id)->toBe((int) $seriesSrc->id);
});

it('Series toggle on, VOD toggle off: reclassifies Series categories but leaves VOD channels alone', function () {
    mockTmdbForGenreTest();

    $this->playlist->update([
        'reclassify_vod_groups_to_tmdb_genres' => false,
        'reclassify_series_categories_to_tmdb_genres' => true,
    ]);

    // Series in a non-genre-matching category
    $seriesSrc = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Whatever']);
    $series = enabledSeries($this->playlist, $seriesSrc, $this->user, ['genre' => 'Action & Adventure']);

    // VOD channel in a non-genre-matching group
    $vodSrc = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Whatever']);
    $vodCh = enabledChannel($this->playlist, $vodSrc, $this->user, [
        'is_vod' => true, 'info' => ['genre' => 'Action'],
    ]);

    // Mirror the VOD-side test: pass `seriesPlaylistId` (not `vodPlaylistId`)
    // so resolveTouchedSeriesPlaylistIds() returns the test playlist instead of
    // an empty array. With only series scope, the VOD reclassify loop sees no
    // touched VOD playlists and skips the VOD channel — which is exactly the
    // behavior we want to prove: leaving the VOD toggle off prevents the VOD
    // channel from being reclassified.
    $job = new FetchTmdbIds(seriesPlaylistId: $this->playlist->id, user: $this->user);
    $ref = new ReflectionMethod($job, 'reclassifyGroupsForTouchedPlaylists');
    $ref->setAccessible(true);
    $ref->invoke($job);

    $actionCategory = Category::where('name', 'Action & Adventure')->first();
    expect($actionCategory)->not->toBeNull()
        ->and((int) $series->refresh()->category_id)->toBe((int) $actionCategory->id)
        // VOD channel must NOT have moved — its group is still the original $vodSrc
        ->and((int) refreshChannel($vodCh)->group_id)->toBe((int) $vodSrc->id);
});
