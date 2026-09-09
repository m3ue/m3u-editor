<?php

use App\Jobs\CopyAttributesToPlaylist;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ProviderMigrationPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * Build a TV-1 (expired) / TV-2 (working) pair sharing the "Zee TV" channel under different
 * upstream stream ids, plus a distractor channel on each side.
 *
 * @return array{source: Playlist, target: Playlist, sourceZee: Channel, targetZee: Channel}
 */
function migrationFixture(User $user, array $sourceZeeOverrides = [], array $targetZeeOverrides = []): array
{
    $source = Playlist::factory()->create(['user_id' => $user->id]);
    $target = Playlist::factory()->create(['user_id' => $user->id]);

    $sourceZee = Channel::factory()->create(array_merge([
        'playlist_id' => $source->id,
        'user_id' => $user->id,
        'is_vod' => false,
        'name' => 'Zee TV',
        'title' => 'Zee TV',
        'stream_id' => '945',
        'stream_id_custom' => null,
        'name_custom' => null,
        'title_custom' => null,
        'enabled' => true,
        'group' => 'Entertainment',
        'channel' => 42,
        'sort' => 7,
        'epg_channel_id' => null,
    ], $sourceZeeOverrides));

    Channel::factory()->create([
        'playlist_id' => $source->id,
        'user_id' => $user->id,
        'is_vod' => false,
        'name' => 'Old Only Channel',
        'stream_id' => 'old-1',
        'enabled' => true,
    ]);

    $targetZee = Channel::factory()->create(array_merge([
        'playlist_id' => $target->id,
        'user_id' => $user->id,
        'is_vod' => false,
        'name' => 'Zee TV',
        'title' => 'Zee TV',
        'stream_id' => '117225',
        'stream_id_custom' => null,
        'name_custom' => null,
        'title_custom' => null,
        'enabled' => false,
        'group' => 'Uncategorized',
        'channel' => 1,
        'sort' => 999,
        'epg_channel_id' => null,
    ], $targetZeeOverrides));

    Channel::factory()->create([
        'playlist_id' => $target->id,
        'user_id' => $user->id,
        'is_vod' => false,
        'name' => 'New Only Channel',
        'stream_id' => 'new-1',
        'enabled' => true,
    ]);

    return compact('source', 'target', 'sourceZee', 'targetZee');
}

function migrationJob(Playlist $source, Playlist $target, array $overrides = []): CopyAttributesToPlaylist
{
    return new CopyAttributesToPlaylist(...array_merge([
        'source' => $source,
        'targetId' => $target->id,
        'channelAttributes' => ['enabled', 'group', 'sort', 'channel'],
        'channelMatchAttributes' => [],
        'migrationMode' => true,
        'overwrite' => true,
    ], $overrides));
}

it('maps an expired stream id to the working one and keeps the curated presentation', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture($this->user);

    migrationJob($source, $target)->handle();

    $targetZee->refresh();
    expect($targetZee->enabled)->toBeTrue()
        ->and($targetZee->group)->toBe('Entertainment')
        ->and($targetZee->channel)->toBe(42)
        ->and((float) $targetZee->sort)->toBe(7.0)
        // Target keeps its own working stream identity.
        ->and($targetZee->stream_id)->toBe('117225');
});

it('does not mutate the source playlist or its channels', function () {
    ['source' => $source, 'target' => $target, 'sourceZee' => $sourceZee] = migrationFixture($this->user);

    $before = $sourceZee->only(['enabled', 'group', 'channel', 'sort', 'stream_id']);
    migrationJob($source, $target)->handle();

    expect($sourceZee->refresh()->only(['enabled', 'group', 'channel', 'sort', 'stream_id']))->toEqual($before);
});

it('copies a disabled state onto the match', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture(
        $this->user,
        sourceZeeOverrides: ['enabled' => false],
        targetZeeOverrides: ['enabled' => true],
    );

    migrationJob($source, $target)->handle();

    expect($targetZee->refresh()->enabled)->toBeFalse();
});

it('leaves non-selected fields untouched', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture($this->user);

    // Only migrate "enabled"; group/channel/sort must not move.
    migrationJob($source, $target, ['channelAttributes' => ['enabled']])->handle();

    $targetZee->refresh();
    expect($targetZee->enabled)->toBeTrue()
        ->and($targetZee->group)->toBe('Uncategorized')
        ->and($targetZee->channel)->toBe(1);
});

it('preserves an EPG mapping by copying the FK when the target has no equivalent EPG channel', function () {
    $epg = Epg::factory()->create(['user_id' => $this->user->id]);
    $sourceEpgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'zeetv.in',
        'name' => 'Zee TV',
    ]);

    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture(
        $this->user,
        sourceZeeOverrides: ['epg_channel_id' => $sourceEpgChannel->id],
    );

    migrationJob($source, $target, ['preserveEpg' => true])->handle();

    expect($targetZee->refresh()->epg_channel_id)->toBe($sourceEpgChannel->id);
});

it('re-maps an EPG mapping to the target providers own EPG channel with the same identity', function () {
    $sourceEpg = Epg::factory()->create(['user_id' => $this->user->id]);
    $sourceEpgChannel = EpgChannel::factory()->create([
        'epg_id' => $sourceEpg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'zeetv.in',
        'name' => 'Zee TV',
    ]);

    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture(
        $this->user,
        sourceZeeOverrides: ['epg_channel_id' => $sourceEpgChannel->id],
    );

    // Target playlist has its own working EPG carrying the same channel_id.
    $targetEpg = Epg::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $target->id]);
    $targetEpgChannel = EpgChannel::factory()->create([
        'epg_id' => $targetEpg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'ZEETV.IN',
        'name' => 'Zee Television',
    ]);

    migrationJob($source, $target, ['preserveEpg' => true])->handle();

    expect($targetZee->refresh()->epg_channel_id)->toBe($targetEpgChannel->id);
});

it('does not replace an existing different target EPG mapping without confirmation', function () {
    $epg = Epg::factory()->create(['user_id' => $this->user->id]);
    $sourceEpgChannel = EpgChannel::factory()->create(['epg_id' => $epg->id, 'user_id' => $this->user->id]);
    $existingTargetEpgChannel = EpgChannel::factory()->create(['epg_id' => $epg->id, 'user_id' => $this->user->id]);

    ['source' => $source, 'target' => $target, 'sourceZee' => $sourceZee, 'targetZee' => $targetZee] = migrationFixture(
        $this->user,
        sourceZeeOverrides: ['epg_channel_id' => $sourceEpgChannel->id],
        targetZeeOverrides: ['epg_channel_id' => $existingTargetEpgChannel->id],
    );

    $job = migrationJob($source, $target, [
        'preserveEpg' => true,
        'resolvedMap' => [[
            'source_id' => $sourceZee->id,
            'target_id' => $targetZee->id,
            'epg_channel_id' => $sourceEpgChannel->id,
            'epg_confirmed' => false,
        ]],
    ]);
    $job->handle();

    expect($targetZee->refresh()->epg_channel_id)->toBe($existingTargetEpgChannel->id)
        ->and($job->report['epg_conflicts'])->toBe(1);

    // With confirmation it is replaced.
    migrationJob($source, $target, [
        'preserveEpg' => true,
        'resolvedMap' => [[
            'source_id' => $sourceZee->id,
            'target_id' => $targetZee->id,
            'epg_channel_id' => $sourceEpgChannel->id,
            'epg_confirmed' => true,
        ]],
    ])->handle();

    expect($targetZee->refresh()->epg_channel_id)->toBe($sourceEpgChannel->id);
});

it('only disables target-only channels when explicitly asked', function () {
    ['source' => $source, 'target' => $target] = migrationFixture($this->user);
    $newOnly = Channel::where('playlist_id', $target->id)->where('name', 'New Only Channel')->first();

    migrationJob($source, $target)->handle();
    expect($newOnly->refresh()->enabled)->toBeTrue();

    migrationJob($source, $target, ['disableTargetOnly' => true])->handle();
    expect($newOnly->refresh()->enabled)->toBeFalse();
});

it('performs no writes on a dry run but still reports the plan', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture($this->user);
    $groupsBefore = $target->groups()->count();

    $job = migrationJob($source, $target, ['dryRun' => true]);
    $result = $job->handle();

    expect($result)->toBe(1)
        ->and($job->report['updated'])->toBe(1)
        ->and($targetZee->refresh()->enabled)->toBeFalse()
        ->and($targetZee->group)->toBe('Uncategorized')
        ->and($target->groups()->count())->toBe($groupsBefore);
});

it('is idempotent across repeated applications', function () {
    ['source' => $source, 'target' => $target] = migrationFixture($this->user);

    migrationJob($source, $target)->handle();
    $groupsAfterFirst = $target->groups()->count();
    $channelsAfterFirst = $target->channels()->count();

    $secondJob = migrationJob($source, $target);
    $secondJob->handle();

    expect($target->groups()->count())->toBe($groupsAfterFirst)
        ->and($target->channels()->count())->toBe($channelsAfterFirst);
});

it('rejects a stale plan fingerprint', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture($this->user);

    $job = migrationJob($source, $target, ['planFingerprint' => 'not-the-current-fingerprint']);
    $result = $job->handle();

    expect($result)->toBe(0)
        ->and($targetZee->refresh()->enabled)->toBeFalse();
});

it('accepts a matching plan fingerprint from the planner', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = migrationFixture($this->user);
    $fingerprint = app(ProviderMigrationPlanner::class)->fingerprint($source->fresh(), $target->fresh());

    migrationJob($source, $target, ['planFingerprint' => $fingerprint])->handle();

    expect($targetZee->refresh()->enabled)->toBeTrue();
});
