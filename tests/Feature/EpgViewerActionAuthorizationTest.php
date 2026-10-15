<?php

/**
 * The EPG Viewer's Edit Channel and Schedule Recording actions are opened by public
 * Livewire methods that take a channel id from the browser. They must only work for
 * the logged-in owner of the viewed playlist, on that playlist's own channels: never
 * from the guest panel's view-only viewer, and never on another user's channel.
 */

use App\Livewire\EpgViewer;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Queue::fake();

    $this->owner = User::factory()->create(['name' => 'owner', 'permissions' => ['use_dvr']]);
    $this->playlist = Playlist::factory()->for($this->owner)->create();
    $group = Group::factory()->for($this->playlist)->for($this->owner)->create();
    $epg = Epg::factory()->for($this->owner)->create();
    $this->channel = Channel::factory()->for($this->owner)->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'title_custom' => null,
        'epg_channel_id' => EpgChannel::factory()->for($epg)->for($this->owner)->create(['channel_id' => 'news.owner'])->id,
    ]);
    DvrSetting::factory()->enabled()->for($this->owner)->for($this->playlist)->create();

    $this->otherUser = User::factory()->create(['name' => 'other']);
    $otherPlaylist = Playlist::factory()->for($this->otherUser)->create();
    $otherGroup = Group::factory()->for($otherPlaylist)->for($this->otherUser)->create();
    $otherEpg = Epg::factory()->for($this->otherUser)->create();
    $this->otherChannel = Channel::factory()->for($this->otherUser)->for($otherPlaylist)->for($otherGroup)->create([
        'enabled' => true,
        'title_custom' => null,
        'epg_channel_id' => EpgChannel::factory()->for($otherEpg)->for($this->otherUser)->create(['channel_id' => 'news.other'])->id,
    ]);

    $this->programme = [
        'title' => 'Evening News',
        'start' => now()->addHour()->toIso8601String(),
        'stop' => now()->addHours(2)->toIso8601String(),
    ];
});

// --- Guest panel (view-only, no logged-in user) ---

it('does not let a guest open or save Edit Channel from the view-only viewer', function () {
    Livewire::test(EpgViewer::class, ['record' => $this->playlist, 'viewOnly' => true])
        ->call('openChannelEdit', $this->channel->id)
        ->assertActionNotMounted('editChannel');

    expect($this->channel->fresh()->title_custom)->toBeNull();
});

it('does not let a guest schedule a recording on the owner\'s DVR from the view-only viewer', function () {
    Livewire::test(EpgViewer::class, ['record' => $this->playlist, 'viewOnly' => true])
        ->call('openScheduleProgramme', $this->programme, $this->channel->id)
        ->assertActionNotMounted('scheduleProgramme');

    expect(DvrRecordingRule::count())->toBe(0);
});

it('does not let the browser switch off view-only mode', function () {
    Livewire::test(EpgViewer::class, ['record' => $this->playlist, 'viewOnly' => true])
        ->set('viewOnly', false);
})->throws(CannotUpdateLockedPropertyException::class);

// --- Owner panel (logged-in user) ---

it('lets the owner edit a channel of the viewed playlist', function () {
    $this->actingAs($this->owner);

    Livewire::test(EpgViewer::class, ['record' => $this->playlist])
        ->call('openChannelEdit', $this->channel->id)
        ->assertActionMounted('editChannel')
        ->setActionData(['title_custom' => 'Renamed'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->channel->fresh()->title_custom)->toBe('Renamed');
});

it('does not let a user edit another user\'s channel through their own viewer', function () {
    $this->actingAs($this->owner);

    Livewire::test(EpgViewer::class, ['record' => $this->playlist])
        ->call('openChannelEdit', $this->otherChannel->id)
        ->assertActionNotMounted('editChannel');

    expect($this->otherChannel->fresh()->title_custom)->toBeNull();
});

it('lets the owner schedule a recording for a channel of the viewed playlist', function () {
    $this->actingAs($this->owner);

    Livewire::test(EpgViewer::class, ['record' => $this->playlist])
        ->call('openScheduleProgramme', $this->programme, $this->channel->id)
        ->assertActionMounted('scheduleProgramme')
        ->setActionData(['rule_type' => 'once', 'new_only' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DvrRecordingRule::where('channel_id', $this->channel->id)->count())->toBe(1);
});

it('does not let a user schedule another user\'s channel on their own DVR', function () {
    $this->actingAs($this->owner);

    Livewire::test(EpgViewer::class, ['record' => $this->playlist])
        ->call('openScheduleProgramme', $this->programme, $this->otherChannel->id)
        ->assertActionNotMounted('scheduleProgramme');

    expect(DvrRecordingRule::count())->toBe(0);
});
