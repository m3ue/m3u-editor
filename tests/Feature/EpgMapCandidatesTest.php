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
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
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
        ->and($rows->firstWhere('channel_id', $unrelated->id)->automatic_match)->toBeFalse();
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
