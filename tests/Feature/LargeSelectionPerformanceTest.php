<?php

use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\LazyCollection;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->createQuietly(['type' => 'live']);
});

/**
 * @param  array<int, float>  $sorts
 * @return array<int, Channel>
 */
function largeSelectionMakeGroupChannels(Playlist $playlist, Group $group, array $sorts): array
{
    return array_map(fn (float $sort): Channel => Channel::factory()->for($playlist)->createQuietly([
        'user_id' => $playlist->user_id,
        'group_id' => $group->id,
        'group' => $group->name,
        'is_vod' => false,
        'sort' => $sort,
    ]), $sorts);
}

dataset('rarely used inline editable columns', [
    'stream_id_custom', 'title_custom', 'url_custom', 'shift', 'tvg_shift',
    'logo_type', 'can_merge', 'probe_enabled', 'epg_map_enabled',
]);

it('hides rarely used inline editable channel columns by default', function (string $column) {
    $table = Livewire::test(ListChannels::class)->instance()->getTable();

    expect($table->getColumn($column)->isToggledHiddenByDefault())->toBeTrue();
})->with('rarely used inline editable columns');

it('hides rarely used inline editable VOD columns by default', function (string $column) {
    $table = Livewire::test(ListVod::class)->instance()->getTable();

    expect($table->getColumn($column)->isToggledHiddenByDefault())->toBeTrue();
})->with(['stream_id_custom', 'title_custom', 'url_custom', 'shift', 'tvg_shift', 'logo_type', 'can_merge', 'probe_enabled']);

it('keeps the everyday channel columns visible by default', function (string $column) {
    $table = Livewire::test(ListChannels::class)->instance()->getTable();

    expect($table->getColumn($column)->isToggledHiddenByDefault())->toBeFalse();
})->with(['sort', 'enabled', 'name_custom', 'channel']);

it('keeps group channel tables paginated while reordering', function () {
    $component = Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->group,
        'pageClass' => EditGroup::class,
    ]);

    expect($component->instance()->getTable()->isPaginatedWhileReordering())->toBeTrue();
});

it('reorders a page of group channels within their existing sort slots', function () {
    [$first, $second, $third, $fourth] = largeSelectionMakeGroupChannels($this->playlist, $this->group, [10, 20, 30, 40]);

    // Only the second page (third + fourth) is on screen, and the user swaps them.
    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->group,
        'pageClass' => EditGroup::class,
    ])->call('reorderTable', [$fourth->id, $third->id]);

    expect((float) $first->fresh()->sort)->toBe(10.0)
        ->and((float) $second->fresh()->sort)->toBe(20.0)
        ->and((float) $fourth->fresh()->sort)->toBe(30.0)
        ->and((float) $third->fresh()->sort)->toBe(40.0);
});

it('gives distinct positions when the reordered channels share a sort value', function () {
    [$first, $second] = largeSelectionMakeGroupChannels($this->playlist, $this->group, [5, 5]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->group,
        'pageClass' => EditGroup::class,
    ])->call('reorderTable', [$second->id, $first->id]);

    expect((float) $second->fresh()->sort)->toBe(5.0)
        ->and((float) $first->fresh()->sort)->toBe(6.0);
});

it('runs channel bulk actions on a select-all selection without loading every record', function () {
    $channels = largeSelectionMakeGroupChannels($this->playlist, $this->group, [1, 2, 3]);
    Channel::whereKey(collect($channels)->pluck('id'))->update(['enabled' => false]);

    $component = Livewire::test(ListChannels::class)->loadTable();
    $component->set('isTrackingDeselectedTableRecords', true);
    $component->set('deselectedTableRecords', [(string) $channels[2]->id]);

    $component
        ->mountAction(TestAction::make('Bulk channel actions')->table()->bulk())
        ->callAction(TestAction::make('enable')->schemaComponent());

    expect($channels[0]->fresh()->enabled)->toBeTrue()
        ->and($channels[1]->fresh()->enabled)->toBeTrue()
        ->and($channels[2]->fresh()->enabled)->toBeFalse();
});

it('moves only the selected channels from the chosen playlist to the target group', function () {
    $channels = largeSelectionMakeGroupChannels($this->playlist, $this->group, [1, 2]);
    $target = Group::factory()->for($this->playlist)->for($this->user)->createQuietly(['type' => 'live', 'name' => 'Target']);

    $otherPlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $otherGroup = Group::factory()->for($otherPlaylist)->for($this->user)->createQuietly(['type' => 'live']);
    [$otherChannel] = largeSelectionMakeGroupChannels($otherPlaylist, $otherGroup, [1]);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->selectTableRecords([$channels[0]->id, $otherChannel->id])
        ->mountAction(TestAction::make('Bulk channel actions')->table()->bulk())
        ->callAction(TestAction::make('move')->schemaComponent(), [
            'playlist' => $this->playlist->id,
            'group' => $target->id,
        ]);

    expect($channels[0]->fresh()->group_id)->toBe($target->id)
        ->and($channels[0]->fresh()->group)->toBe('Target')
        ->and($channels[1]->fresh()->group_id)->toBe($this->group->id)
        ->and($otherChannel->fresh()->group_id)->toBe($otherGroup->id);
});

it('dispatches find and replace with selected channel ids instead of models', function () {
    $channels = largeSelectionMakeGroupChannels($this->playlist, $this->group, [1, 2]);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->selectTableRecords([$channels[0]->id])
        ->mountAction(TestAction::make('Bulk channel actions')->table()->bulk())
        ->callAction(TestAction::make('find-replace')->schemaComponent(), [
            'use_regex' => false,
            'column' => 'title',
            'find_replace' => 'foo',
            'replace_with' => 'bar',
        ]);

    Queue::assertPushed(ChannelFindAndReplace::class, fn (ChannelFindAndReplace $job): bool => $job->channels === null
        && $job->channel_ids === [$channels[0]->id]);
});

it('applies find and replace to exactly the given channel ids, including ones an admin selected from another user', function () {
    [$selected, $unselected] = largeSelectionMakeGroupChannels($this->playlist, $this->group, [1, 2]);
    Channel::whereKey([$selected->id, $unselected->id])->update(['title' => 'US- News', 'title_custom' => null]);

    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    $foreign = Channel::factory()->for($otherPlaylist)->createQuietly([
        'user_id' => $otherUser->id,
        'title' => 'US- News',
        'is_vod' => false,
    ]);

    (new ChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'title',
        find_replace: 'US- ',
        replace_with: '',
        channel_ids: [$selected->id, $foreign->id],
    ))->handle();

    expect($selected->fresh()->title_custom)->toBe('News')
        ->and($foreign->fresh()->title_custom)->toBe('News')
        ->and($unselected->fresh()->title_custom)->toBeNull();
});

it('resets find and replace for exactly the given channel ids, including ones an admin selected from another user', function () {
    [$selected, $unselected] = largeSelectionMakeGroupChannels($this->playlist, $this->group, [1, 2]);
    Channel::whereKey([$selected->id, $unselected->id])->update(['title_custom' => 'Custom']);

    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    $foreign = Channel::factory()->for($otherPlaylist)->createQuietly([
        'user_id' => $otherUser->id,
        'title_custom' => 'Custom',
        'is_vod' => false,
    ]);

    (new ChannelFindAndReplaceReset(
        user_id: $this->user->id,
        column: 'title',
        channel_ids: [$selected->id, $foreign->id],
    ))->handle();

    expect($selected->fresh()->title_custom)->toBeNull()
        ->and($foreign->fresh()->title_custom)->toBeNull()
        ->and($unselected->fresh()->title_custom)->toBe('Custom');
});

it('lazily hydrates a bulk-action selection across chunks, applying the batch scope', function () {
    $channels = largeSelectionMakeGroupChannels($this->playlist, $this->group, [1, 2, 3]);
    Channel::whereKey($channels[1]->id)->update(['is_vod' => true]);

    $records = PlaylistService::lazySelectedRecords(
        Channel::query()->whereKey(collect($channels)->pluck('id')),
        fn (Builder $query) => $query->where('is_vod', false),
        chunkSize: 1,
    );

    expect($records)->toBeInstanceOf(LazyCollection::class)
        ->and($records->map->id->sort()->values()->all())->toBe([$channels[0]->id, $channels[2]->id]);
});
