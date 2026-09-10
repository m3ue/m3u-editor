<?php

use App\Filament\Pages\EasyEditor;
use App\Livewire\EasyEditor\ChannelsPane;
use App\Livewire\EasyEditor\GroupsPane;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->for($this->user)->create(['name' => 'Alpha']);

    $this->groupA = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['name' => 'News', 'type' => 'live', 'sort_order' => 1]);
    $this->groupB = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['name' => 'Sports', 'type' => 'live', 'sort_order' => 2]);
    $this->vodGroup = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['name' => 'Action', 'type' => 'vod', 'sort_order' => 1]);

    $this->channelA = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['group_id' => $this->groupA->id, 'is_vod' => false, 'enabled' => false, 'name' => 'CNN']);
    $this->channelB = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['group_id' => $this->groupB->id, 'is_vod' => false, 'enabled' => false, 'name' => 'ESPN']);
    $this->vodChannel = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['group_id' => $this->vodGroup->id, 'is_vod' => true, 'enabled' => false, 'name' => 'Die Hard']);
});

it('renders the easy editor page, preselects the only playlist and shows no group', function () {
    Livewire::test(EasyEditor::class)
        ->assertSuccessful()
        ->assertSet('playlistId', $this->playlist->id)
        ->assertSet('contentType', 'live')
        ->assertSet('selectedGroupId', null)
        ->assertSee(__('Select a group'));
});

it('tracks the selected group emitted by the groups pane', function () {
    Livewire::test(EasyEditor::class)
        ->dispatch('easy-editor-group-selected', groupId: $this->groupA->id)
        ->assertSet('selectedGroupId', $this->groupA->id);
});

it('lists only the live groups for the selected playlist', function () {
    $otherPlaylistGroup = Group::factory()->for($this->user)
        ->create(['type' => 'live', 'name' => 'Elsewhere']);

    Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'live'])
        ->assertCanSeeTableRecords([$this->groupA, $this->groupB])
        ->assertCanNotSeeTableRecords([$this->vodGroup, $otherPlaylistGroup]);
});

it('lists only the vod groups when the content type is vod', function () {
    Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'vod'])
        ->assertCanSeeTableRecords([$this->vodGroup])
        ->assertCanNotSeeTableRecords([$this->groupA, $this->groupB]);
});

it('shows only the selected group\'s live channels', function () {
    Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $this->groupA->id,
    ])
        ->assertCanSeeTableRecords([$this->channelA])
        ->assertCanNotSeeTableRecords([$this->channelB, $this->vodChannel]);
});

it('shows only the selected group\'s vod channels when content type is vod', function () {
    Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'vod',
        'selectedGroupId' => $this->vodGroup->id,
    ])
        ->assertCanSeeTableRecords([$this->vodChannel])
        ->assertCanNotSeeTableRecords([$this->channelA, $this->channelB]);
});

it('renders the reused channel columns', function () {
    Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $this->groupA->id,
    ])
        ->assertCanRenderTableColumn('enabled')
        ->assertCanRenderTableColumn('tvg_shift')
        ->assertCanRenderTableColumn('url_custom')
        ->assertCanRenderTableColumn('easy_editor_drag');
});

it('creates a custom group scoped to the playlist and content type', function () {
    Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'vod'])
        ->callTableAction('createCustomGroup', data: ['name' => 'My VOD Group']);

    assertDatabaseHas(Group::class, [
        'name' => 'My VOD Group',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'custom' => true,
        'type' => 'vod',
    ]);
});

it('enables all channels in a group from the groups pane', function () {
    Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'live'])
        ->callTableAction('enableChannels', $this->groupA);

    expect($this->channelA->fresh()->enabled)->toBeTrue()
        ->and($this->channelB->fresh()->enabled)->toBeFalse();
});

it('moves a channel into another group via the drag drop handler', function () {
    Livewire::test(GroupsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $this->groupA->id,
    ])
        ->call('moveChannelToGroup', $this->channelA->id, $this->groupB->id)
        ->assertNotified();

    expect($this->channelA->fresh()->group_id)->toBe($this->groupB->id)
        ->and($this->channelA->fresh()->group)->toBe($this->groupB->name);
});

it('starts the channels table on page 1 even with a stale page param in the url', function () {
    Livewire::withQueryParams(['easyEditorChannelsPage' => 7])
        ->test(ChannelsPane::class, [
            'playlistId' => $this->playlist->id,
            'contentType' => 'live',
            'selectedGroupId' => $this->groupA->id,
        ])
        ->assertSet('paginators.easyEditorChannelsPage', 1);
});

it('reorders only the dragged page slice, leaving the rest of the group in place', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['name' => 'Reorder Group', 'type' => 'live']);

    $channels = collect([10, 20, 30, 40, 50])->map(fn (int $sort) => Channel::factory()
        ->for($this->user)->for($this->playlist)
        ->create(['group_id' => $group->id, 'is_vod' => false, 'sort' => $sort]));

    // Reverse the middle three only (as one paginated page would); the outer two stay put.
    [$a, $b, $c, $d, $e] = $channels->all();

    Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $group->id,
    ])->call('reorderTable', [$d->id, $c->id, $b->id]);

    // a and e keep their (first/last) global positions; b, c, d land in the
    // dragged order in between - renumbered to a clean 1..5 sequence.
    expect((float) $a->fresh()->sort)->toBe(1.0)
        ->and((float) $d->fresh()->sort)->toBe(2.0)
        ->and((float) $c->fresh()->sort)->toBe(3.0)
        ->and((float) $b->fresh()->sort)->toBe(4.0)
        ->and((float) $e->fresh()->sort)->toBe(5.0);
});

it('reorders correctly even when every row shares the same sort value', function () {
    // The state of a playlist imported with auto-sort off: every channel gets
    // sort = 0, so there's nothing to "preserve" - a fresh sequence is required
    // or the drag is a silent no-op.
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['name' => 'Unsorted Group', 'type' => 'live']);

    $channels = collect(range(1, 4))->map(fn () => Channel::factory()
        ->for($this->user)->for($this->playlist)
        ->create(['group_id' => $group->id, 'is_vod' => false, 'sort' => 0]));

    [$one, $two, $three, $four] = $channels->all();

    Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $group->id,
    ])->call('reorderTable', [$four->id, $three->id, $two->id, $one->id]);

    expect((float) $four->fresh()->sort)->toBe(1.0)
        ->and((float) $three->fresh()->sort)->toBe(2.0)
        ->and((float) $two->fresh()->sort)->toBe(3.0)
        ->and((float) $one->fresh()->sort)->toBe(4.0);
});

it('will not reorder channels outside the current user via a tampered key', function () {
    $otherUser = User::factory()->create();
    $foreign = Channel::factory()->for($otherUser)
        ->create(['is_vod' => false, 'sort' => 999]);

    Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $this->groupA->id,
    ])->call('reorderTable', [$this->channelA->id, $foreign->id]);

    expect((float) $foreign->fresh()->sort)->toBe(999.0);
});

it('keeps the two pane tables from sharing query-string keys', function () {
    $groups = Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'live']);
    $channels = Livewire::test(ChannelsPane::class, [
        'playlistId' => $this->playlist->id,
        'contentType' => 'live',
        'selectedGroupId' => $this->groupA->id,
    ]);

    // Pagination is deliberately not bound to the URL for either embedded table.
    expect($groups->instance()->queryStringHandlesPagination())->toBe([])
        ->and($channels->instance()->queryStringHandlesPagination())->toBe([])
        ->and($groups->instance()->getTable()->getQueryStringIdentifier())
        ->not->toBe($channels->instance()->getTable()->getQueryStringIdentifier());
});

it('will not move a channel across playlists via the drag drop handler', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherGroup = Group::factory()->for($this->user)->for($otherPlaylist)
        ->create(['type' => 'live']);

    Livewire::test(GroupsPane::class, ['playlistId' => $this->playlist->id, 'contentType' => 'live'])
        ->call('moveChannelToGroup', $this->channelA->id, $otherGroup->id);

    expect($this->channelA->fresh()->group_id)->toBe($this->groupA->id);
});
