<?php

use App\Filament\Resources\Playlists\Pages\MigrateProvider;
use App\Jobs\CopyAttributesToPlaylist;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

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

    // Resource route binding is scoped to the current user, so a non-owner cannot resolve it.
    expect(fn () => Livewire::test(MigrateProvider::class, ['record' => $source->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('is not found for a network playlist', function () {
    $source = Playlist::factory()->create(['user_id' => $this->user->id, 'is_network_playlist' => true]);

    Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->assertNotFound();
});

it('builds a preview and dispatches the migration job in migration mode', function () {
    ['source' => $source, 'target' => $target, 'sourceZee' => $sourceZee, 'targetZee' => $targetZee] = pageFixture($this->user);

    Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->set('data.fields', ['enabled', 'group', 'channel'])
        ->call('buildPreview')
        ->assertSet('data.mappings', function (array $mappings) use ($sourceZee, $targetZee) {
            return count($mappings) === 1
                && (int) $mappings[0]['source_id'] === $sourceZee->id
                && (int) $mappings[0]['target_id'] === $targetZee->id;
        })
        ->call('apply');

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
});

it('does not dispatch when no rows are included', function () {
    ['source' => $source, 'target' => $target] = pageFixture($this->user);

    Livewire::test(MigrateProvider::class, ['record' => $source->id])
        ->set('data.target_playlist_id', $target->id)
        ->call('buildPreview')
        ->set('data.mappings.0.include', false)
        ->call('apply')
        ->assertNotified();

    Bus::assertNotDispatched(CopyAttributesToPlaylist::class);
});
