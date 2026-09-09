<?php

use App\Filament\Resources\Playlists\Pages\MigrateProvider;
use App\Jobs\CopyAttributesToPlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\ProviderMigrationPlanRow;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * @return array{source: Playlist, target: Playlist, sourceZee: Channel, targetZee: Channel}
 */
function pageFixture(User $user): array
{
    $source = Playlist::factory()->create(['user_id' => $user->id]);
    $target = Playlist::factory()->create(['user_id' => $user->id]);

    $sourceZee = Channel::factory()->create([
        'playlist_id' => $source->id, 'user_id' => $user->id, 'is_vod' => false,
        'name' => 'Zee TV', 'stream_id' => '945', 'stream_id_custom' => null,
        'name_custom' => null, 'enabled' => true, 'group' => 'Entertainment', 'channel' => 42,
        'epg_channel_id' => null,
    ]);
    $targetZee = Channel::factory()->create([
        'playlist_id' => $target->id, 'user_id' => $user->id, 'is_vod' => false,
        'name' => 'Zee TV', 'stream_id' => '117225', 'stream_id_custom' => null,
        'name_custom' => null, 'enabled' => false, 'group' => 'Uncategorized', 'channel' => 1,
        'epg_channel_id' => null,
    ]);

    return compact('source', 'target', 'sourceZee', 'targetZee');
}

it('renders for the playlist owner', function () {
    ['source' => $source] = pageFixture($this->user);

    Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->assertOk();
});

it('is not reachable for a user who does not own the playlist', function () {
    $source = Playlist::factory()->create(['user_id' => User::factory()->create()->id]);

    expect(fn () => Livewire::test(MigrateProvider::class, ['record' => $source->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('is not found for a network playlist', function () {
    $source = Playlist::factory()->create(['user_id' => $this->user->id, 'is_network_playlist' => true]);

    Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->assertNotFound();
});

it('materializes plan rows and dispatches the migration job in migration mode', function () {
    ['source' => $source, 'target' => $target, 'sourceZee' => $sourceZee, 'targetZee' => $targetZee] = pageFixture($this->user);

    $component = Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->set('data.fields', ['enabled', 'group', 'channel'])
        ->call('buildPreview');

    $rows = ProviderMigrationPlanRow::query()->where('source_playlist_id', $source->id)->get();
    expect($rows)->toHaveCount(1);

    $matched = $rows->firstWhere('bucket', 'matched');
    expect($matched->source_channel_id)->toBe($sourceZee->id)
        ->and($matched->matched_target_channel_id)->toBe($targetZee->id)
        ->and($matched->include)->toBeTrue();

    $component->call('apply');

    Bus::assertDispatched(CopyAttributesToPlaylist::class, function (CopyAttributesToPlaylist $job) use ($source, $target, $sourceZee, $targetZee) {
        return $job->migrationMode === true
            && $job->source->id === $source->id
            && $job->targetId === $target->id
            && $job->planFingerprint !== null
            && $job->resolvedMap === [[
                'source_id' => $sourceZee->id,
                'target_id' => $targetZee->id,
                'epg_channel_id' => null,
                'epg_confirmed' => false,
            ]];
    });

    // Plan rows are cleared once applied.
    expect(ProviderMigrationPlanRow::query()->where('source_playlist_id', $source->id)->count())->toBe(0);
});

it('does not dispatch when every matched row is excluded', function () {
    ['source' => $source, 'target' => $target] = pageFixture($this->user);

    $component = Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->call('buildPreview');

    ProviderMigrationPlanRow::query()
        ->where('source_playlist_id', $source->id)
        ->update(['include' => false]);

    $component->call('apply')->assertNotified();

    Bus::assertNotDispatched(CopyAttributesToPlaylist::class);
});

it('rebuilding the preview replaces the previous rows for the same pair', function () {
    ['source' => $source, 'target' => $target] = pageFixture($this->user);

    $component = Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->call('buildPreview');

    $firstCount = ProviderMigrationPlanRow::query()->where('source_playlist_id', $source->id)->count();

    $component->call('buildPreview');

    expect(ProviderMigrationPlanRow::query()->where('source_playlist_id', $source->id)->count())->toBe($firstCount);
});

it('sweeps stale plan rows and any other previews for the user on build', function () {
    ['source' => $source, 'target' => $target] = pageFixture($this->user);

    // A stale row from an abandoned preview, plus a row for a different source playlist.
    $stale = ProviderMigrationPlanRow::query()->create([
        'session_key' => (string) Str::uuid(), 'user_id' => $this->user->id,
        'source_playlist_id' => 999, 'target_playlist_id' => 998, 'source_channel_id' => 1,
        'bucket' => 'matched', 'include' => true,
    ]);
    ProviderMigrationPlanRow::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(3)]);

    $otherPreview = ProviderMigrationPlanRow::query()->create([
        'session_key' => (string) Str::uuid(), 'user_id' => $this->user->id,
        'source_playlist_id' => 555, 'target_playlist_id' => 556, 'source_channel_id' => 2,
        'bucket' => 'matched', 'include' => true,
    ]);

    Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->call('buildPreview');

    expect(ProviderMigrationPlanRow::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(ProviderMigrationPlanRow::query()->whereKey($otherPreview->id)->exists())->toBeFalse()
        ->and(ProviderMigrationPlanRow::query()->where('source_playlist_id', $source->id)->count())->toBe(1);
});

it('releases another row when a manual match points at an already-claimed replacement channel', function () {
    ['source' => $source, 'target' => $target, 'targetZee' => $targetZee] = pageFixture($this->user);

    // A second source channel with no clean auto-match, so we can move it by hand.
    $sourceOther = Channel::factory()->create([
        'playlist_id' => $source->id, 'user_id' => $this->user->id, 'is_vod' => false,
        'name' => 'Some Other Channel', 'stream_id' => '888', 'enabled' => true,
    ]);

    $component = Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->call('buildPreview');

    $autoMatched = ProviderMigrationPlanRow::query()
        ->where('session_key', $component->get('sessionKey'))
        ->where('bucket', 'matched')
        ->firstOrFail();
    expect((int) $autoMatched->matched_target_channel_id)->toBe($targetZee->id);

    $otherRow = ProviderMigrationPlanRow::query()
        ->where('session_key', $component->get('sessionKey'))
        ->where('source_channel_id', $sourceOther->id)
        ->firstOrFail();

    // Point the second row at the channel the first row already claimed.
    $component->callAction(
        TestAction::make('changeMatch')->table($otherRow),
        ['matched_target_channel_id' => $targetZee->id],
    );

    expect($otherRow->refresh()->matched_target_channel_id)->toBe($targetZee->id)
        ->and($autoMatched->refresh()->matched_target_channel_id)->toBeNull()
        ->and($autoMatched->bucket)->toBe('unmatched')
        ->and($autoMatched->include)->toBeFalse();
});

it('prunes stale rows on mount', function () {
    ['source' => $source] = pageFixture($this->user);

    $stale = ProviderMigrationPlanRow::query()->create([
        'session_key' => (string) Str::uuid(), 'user_id' => $this->user->id,
        'source_playlist_id' => 1, 'target_playlist_id' => 2, 'source_channel_id' => 1,
        'bucket' => 'matched', 'include' => true,
    ]);
    ProviderMigrationPlanRow::query()->whereKey($stale->id)->update(['created_at' => now()->subHours(5)]);

    Livewire::test(MigrateProvider::class, ['record' => $source->id]);

    expect(ProviderMigrationPlanRow::query()->whereKey($stale->id)->exists())->toBeFalse();
});
