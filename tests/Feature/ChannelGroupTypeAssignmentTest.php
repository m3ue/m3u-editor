<?php

/**
 * A Live channel's normal edit form offered every group in its playlist as a move
 * target, including VOD groups - accepting the choice and saving a Live channel
 * into a VOD group without touching `is_vod`. The channel then became invisible
 * to both the Live and VOD group pickers elsewhere in the app, since those filter
 * by `type` (live/vod) in addition to the channel's own `is_vod` flag.
 *
 * Fixed by restricting the Channel edit form's group picker (and the Vod edit
 * form's, symmetrically) to groups of the matching type.
 */

use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The options a mounted table action's schema offers for a field, the same way
 * BouquetEditHydrationTest introspects a mounted "edit" table action.
 */
function mountedTableActionFieldOptions(Testable $component, string $key): array
{
    $page = $component->instance();
    $schema = $page->{$page->getMountedActionSchemaName()};

    return $schema->getComponent($key, withHidden: false)->getOptions();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('does not offer a VOD group as a target on the Live channel edit form', function () {
    $liveGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live', 'name' => 'News']);
    $vodGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Movies']);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($liveGroup)->create([
        'is_vod' => false, 'enabled' => true,
    ]);

    $options = Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->instance()
        ->form->getComponent('group_id')
        ->getOptions();

    expect($options)->toHaveKey((string) $liveGroup->id)
        ->and($options)->not->toHaveKey((string) $vodGroup->id);
});

it('does not offer a Live group as a target on the VOD edit form', function () {
    $liveGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live', 'name' => 'News']);
    $vodGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Movies']);

    $movie = Channel::factory()->for($this->user)->for($this->playlist)->for($vodGroup)->create([
        'is_vod' => true, 'enabled' => true,
    ]);

    $component = Livewire::test(ListVod::class)->mountTableAction('edit', $movie);
    $options = mountedTableActionFieldOptions($component, 'group_id');

    expect($options)->toHaveKey((string) $vodGroup->id)
        ->and($options)->not->toHaveKey((string) $liveGroup->id);
});

it('still offers a legacy null-type group (pre-dating the VOD group split) on the Live channel edit form', function () {
    $legacyGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => null, 'name' => 'Legacy']);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($legacyGroup)->create([
        'is_vod' => false, 'enabled' => true,
    ]);

    $options = Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->instance()
        ->form->getComponent('group_id')
        ->getOptions();

    expect($options)->toHaveKey((string) $legacyGroup->id);
});
