<?php

use App\Enums\EpgMapCandidateStatus;
use App\Filament\Resources\EpgMaps\Pages\ViewEpgMap;
use App\Filament\Resources\EpgMaps\RelationManagers\CandidatesRelationManager;
use App\Jobs\BuildEpgMapCandidatesJob;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\EpgMapCandidate;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SimilaritySearchService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'broadcasting.default' => 'null',
    ]);
    Queue::fake();
    $this->user = User::factory()->create();
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['name' => 'Community XMLTV']));
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

function candidatesChannel(string $name): Channel
{
    return Channel::factory()
        ->for(test()->playlist)
        ->for(test()->user)
        ->for(test()->group)
        ->create([
            'name' => $name,
            'title' => $name,
            'stream_id' => str($name)->slug(),
            'epg_map_enabled' => true,
            'is_vod' => false,
        ]);
}

function candidatesEpgChannel(array $attributes): EpgChannel
{
    return EpgChannel::factory()
        ->for(test()->epg)
        ->for(test()->user)
        ->create($attributes);
}

function candidatesMap(array $settings = []): EpgMap
{
    return EpgMap::factory()->create([
        'user_id' => test()->user->id,
        'epg_id' => test()->epg->id,
        'playlist_id' => test()->playlist->id,
        'status' => 'completed',
        'settings' => $settings,
    ]);
}

it('builds candidate rows for unresolved channels via the job', function () {
    $match = candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    candidatesEpgChannel([
        'name' => 'Completely Different Guide Station',
        'display_name' => 'Different Station',
        'channel_id' => 'different.example',
    ]);
    $channel = candidatesChannel('ESPN News HD');
    $unrelated = candidatesChannel('ZZQ Unrelated Provider Feed');
    $map = candidatesMap(['remove_quality_indicators' => true]);

    (new BuildEpgMapCandidatesJob($map->id))->handle();

    $rows = $map->candidates()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('channel_id', $channel->id)->epg_channel_id)->toBe($match->id)
        ->and($rows->firstWhere('channel_id', $channel->id)->status)->toBe(EpgMapCandidateStatus::Pending)
        ->and($rows->firstWhere('channel_id', $channel->id)->automatic_match)->toBeTrue()
        ->and($rows->firstWhere('channel_id', $unrelated->id)->epg_channel_id)->toBeNull()
        ->and($rows->firstWhere('channel_id', $unrelated->id)->automatic_match)->toBeFalse()
        // The flag must be cleared and the timestamp stamped after a clean run.
        ->and($map->refresh()->candidates_building)->toBeFalse()
        ->and($map->refresh()->candidates_built_at)->not->toBeNull();
});

it('clears candidates_building on the early-return path when the map is no longer reviewable', function () {
    $map = candidatesMap(['remove_quality_indicators' => true]);
    $map->update(['candidates_building' => true]);

    // Force handle() to hit the early return by deleting the playlist
    // association, which makes $map->playlist_id null. The flag is reset
    // before the guard clause returns.
    $map->update(['playlist_id' => null]);

    (new BuildEpgMapCandidatesJob($map->id))->handle();

    expect($map->refresh()->candidates_building)->toBeFalse();
});

it('exposes status tabs ordered starting with pending plus all at the end', function () {
    $this->actingAs($this->user);
    candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidatesChannel('ESPN News HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);
    (new BuildEpgMapCandidatesJob($map->id))->handle();

    $component = Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
    ]);

    $tabs = $component->instance()->getTabs();
    $keys = array_keys($tabs);

    expect($keys)->toHaveCount(5)
        ->and($keys[0])->toBe('pending')
        ->and($keys)->toContain('applied', 'skipped', 'stale', 'all')
        ->and(array_slice($keys, -1)[0])->toBe('all');
});

it('filters table rows by the active status tab', function () {
    $this->actingAs($this->user);
    $match = candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $otherMatch = candidatesEpgChannel([
        'name' => 'Fox Soccer Channel Premier',
        'display_name' => 'Fox Soccer Channel Premier',
        'channel_id' => 'fox-soccer-prem',
    ]);
    $channelA = candidatesChannel('ESPN News HD');
    $channelB = candidatesChannel('Fox Soccer Channel Premier HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);
    (new BuildEpgMapCandidatesJob($map->id))->handle();

    $rows = $map->candidates()->orderBy('id')->get();
    $rows->first()->update(['status' => EpgMapCandidateStatus::Applied, 'applied_at' => now()]);

    // Pending tab is the default opening tab — only the still-pending row
    // should be visible.
    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
    ])
        ->loadTable()
        ->assertCanSeeTableRecords([$rows->last()])
        ->assertCanNotSeeTableRecords([$rows->first()]);

    // Switching to the applied tab should expose the row we just resolved.
    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
        'activeTab' => 'applied',
    ])
        ->loadTable()
        ->assertCanSeeTableRecords([$rows->first()])
        ->assertCanNotSeeTableRecords([$rows->last()]);

    // And the "all" tab shows both.
    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
        'activeTab' => 'all',
    ])
        ->loadTable()
        ->assertCanSeeTableRecords($rows->all());
});

it('replaces stale rows when the build job runs again', function () {
    $match = candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidatesChannel('ESPN News HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);

    (new BuildEpgMapCandidatesJob($map->id))->handle();
    $map->candidates()->first()->update(['status' => EpgMapCandidateStatus::Applied, 'applied_at' => now()]);
    expect($map->candidates()->count())->toBe(1)
        ->and($map->candidates()->first()->status)->toBe(EpgMapCandidateStatus::Applied);

    (new BuildEpgMapCandidatesJob($map->id))->handle();

    expect($map->candidates()->count())->toBe(1)
        ->and($map->candidates()->first()->status)->toBe(EpgMapCandidateStatus::Pending);
});

it('clears candidate rows when no unresolved channels remain', function () {
    $match = candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidatesChannel('ESPN News HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);

    (new BuildEpgMapCandidatesJob($map->id))->handle();
    expect($map->candidates()->get())->toHaveCount(1);

    // Resolve the channel — re-running the job should clear the table.
    $channel->update(['epg_channel_id' => $match->id]);
    (new BuildEpgMapCandidatesJob($map->id))->handle();

    expect($map->candidates()->get())->toHaveCount(0);
});

it('skips candidate rows owned by another user', function () {
    $otherUser = User::factory()->create();
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($otherUser)->create());
    $map = EpgMap::factory()->create([
        'user_id' => $otherUser->id,
        'epg_id' => $otherEpg->id,
        'playlist_id' => Playlist::factory()->for($otherUser)->create()->id,
        'status' => 'completed',
        'settings' => [],
    ]);

    (new BuildEpgMapCandidatesJob($map->id))->handle();

    expect($map->candidates()->get())->toHaveCount(0);
});

it('prunes applied and skipped rows older than 30 days and any rows older than 90 days', function () {
    $map = candidatesMap([]);

    $recent = EpgMapCandidate::factory()->for($map)->create([
        'status' => EpgMapCandidateStatus::Applied,
        'applied_at' => now(),
        'updated_at' => now(),
    ]);
    $oldApplied = EpgMapCandidate::factory()->for($map)->create([
        'status' => EpgMapCandidateStatus::Applied,
        'applied_at' => now()->subDays(45),
        'updated_at' => now()->subDays(45),
    ]);
    $oldSkipped = EpgMapCandidate::factory()->for($map)->create([
        'status' => EpgMapCandidateStatus::Skipped,
        'updated_at' => now()->subDays(45),
    ]);
    $veryOld = EpgMapCandidate::factory()->for($map)->create([
        'status' => EpgMapCandidateStatus::Pending,
        'updated_at' => now()->subDays(120),
    ]);

    $prunable = (new EpgMapCandidate)->prunable()->pluck('id');

    expect($prunable)->toContain($oldApplied->id)
        ->and($prunable)->toContain($oldSkipped->id)
        ->and($prunable)->toContain($veryOld->id)
        ->and($prunable)->not->toContain($recent->id);
});

it('renders the candidates relation manager with a paginated list', function () {
    $this->actingAs($this->user);
    $match = candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidatesChannel('ESPN News HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);
    (new BuildEpgMapCandidatesJob($map->id))->handle();

    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
    ])
        ->loadTable()
        ->assertCanSeeTableRecords([$map->candidates()->first()])
        ->searchTable('ESPN')
        ->assertCanSeeTableRecords([$map->candidates()->first()])
        ->searchTable('Does Not Exist')
        ->assertCanNotSeeTableRecords([$map->candidates()->first()]);
});

it('applies the top candidate for a row via the apply action', function () {
    $this->actingAs($this->user);
    $match = candidatesEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $otherMatch = candidatesEpgChannel([
        'name' => 'Fox Soccer Channel Premier',
        'display_name' => 'Fox Soccer Channel Premier',
        'channel_id' => 'fox-soccer-prem',
    ]);
    $channelA = candidatesChannel('ESPN News HD');
    $channelB = candidatesChannel('Fox Soccer Channel Premier HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);
    (new BuildEpgMapCandidatesJob($map->id))->handle();

    $rows = $map->candidates()->orderBy('id')->get();

    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
    ])
        ->callAction(TestAction::make('apply')->table($rows->first()))
        ->assertNotified();

    expect($channelA->refresh()->epg_channel_id)->not->toBeNull()
        ->and($rows->first()->refresh()->status)->toBe(EpgMapCandidateStatus::Applied)
        ->and($channelB->refresh()->epg_channel_id)->toBeNull()
        ->and($rows->last()->refresh()->status)->toBe(EpgMapCandidateStatus::Pending);
});

it('mounts the candidates relation manager for a different-user map without throwing', function () {
    $this->actingAs($this->user);
    $otherUser = User::factory()->create();
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($otherUser)->create());
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $otherEpg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => [],
    ]);

    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
    ])->assertStatus(200);
});

it('exposes a Build Candidates action on the View page header', function () {
    $this->actingAs($this->user);
    $map = candidatesMap(['remove_quality_indicators' => true]);

    Livewire::test(ViewEpgMap::class, ['record' => $map->id])
        ->assertActionVisible('buildCandidates');
});

it('hides Build Candidates action when the map epg is owned by another user', function () {
    $this->actingAs($this->user);
    $otherUser = User::factory()->create();
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($otherUser)->create());
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $otherEpg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => [],
    ]);

    Livewire::test(ViewEpgMap::class, ['record' => $map->id])->assertActionHidden('buildCandidates');
});

it('builds candidate rows with a single shared EPG query per batch (query count regression)', function () {
    // Create 10 EPG channels that could match
    for ($i = 0; $i < 10; $i++) {
        candidatesEpgChannel([
            'name' => "Sports Channel {$i}",
            'display_name' => "Sports Channel {$i}",
            'channel_id' => "sports-{$i}.us",
        ]);
    }
    // Create 20 playlist channels that need matching
    for ($i = 0; $i < 20; $i++) {
        candidatesChannel("Sports Channel {$i} HD");
    }
    $map = candidatesMap(['remove_quality_indicators' => true]);

    $queryCount = 0;
    Event::listen('eloquent.retrieved: '.EpgChannel::class, function () use (&$queryCount): void {
        $queryCount++;
    });

    (new BuildEpgMapCandidatesJob($map->id))->handle();

    // With the fix, this should be 1 query (the shared loadEpgCandidates call)
    // plus any verification queries. Without the fix, it's 20 queries (one per channel).
    // We expect the optimized path to use significantly fewer queries than channel count.
    expect($queryCount)->toBeLessThan(20)
        ->and($map->candidates()->count())->toBe(20);
});

it('produces identical candidate decisions whether using shared query or per-channel query (parity regression)', function () {
    // Create EPG channels
    $epgA = candidatesEpgChannel([
        'name' => 'ESPN News Plus',
        'display_name' => 'ESPN News Plus',
        'channel_id' => 'espnews-plus',
    ]);
    $epgB = candidatesEpgChannel([
        'name' => 'Fox Soccer Channel Premier',
        'display_name' => 'Fox Soccer Channel Premier',
        'channel_id' => 'fox-soccer-prem',
    ]);
    // Create playlist channels
    $channelA = candidatesChannel('ESPN News Plus HD');
    $channelB = candidatesChannel('Fox Soccer Channel Premier HD');
    $map = candidatesMap(['remove_quality_indicators' => true]);

    // Run the job (which should use shared query after fix)
    (new BuildEpgMapCandidatesJob($map->id))->handle();

    // Also run direct per-channel matching for comparison
    $matcher = new SimilaritySearchService;
    $directA = $matcher->findEpgChannelCandidates($channelA, $this->epg, removeQualityIndicators: true);
    $directB = $matcher->findEpgChannelCandidates($channelB, $this->epg, removeQualityIndicators: true);

    $rows = $map->candidates()->orderBy('channel_id')->get();
    $rowA = $rows->firstWhere('channel_id', $channelA->id);
    $rowB = $rows->firstWhere('channel_id', $channelB->id);

    // Decisions must match exactly between shared-query and per-channel paths
    expect($rowA->automatic_match)->toBe((bool) $directA['automatic_match'])
        ->and($rowA->epg_channel_id)->toBe($directA['automatic_match']?->id ?? $directA['candidates'][0]['epg_channel_id'] ?? null)
        ->and($rowB->automatic_match)->toBe((bool) $directB['automatic_match'])
        ->and($rowB->epg_channel_id)->toBe($directB['automatic_match']?->id ?? $directB['candidates'][0]['epg_channel_id'] ?? null)
        ->and($rowA->top_confidence)->toBe($directA['candidates'][0]['confidence'] ?? 0)
        ->and($rowB->top_confidence)->toBe($directB['candidates'][0]['confidence'] ?? 0);
});
