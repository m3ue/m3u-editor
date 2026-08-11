<?php

/**
 * Regression coverage for the DvrRecordingStatusEvent push, which lets the TV
 * app mark channels as recording live over Reverb instead of polling
 * get_dvr_recordings. See DvrRecording::broadcastStatus() and boot().
 */

use App\Enums\DvrRecordingStatus;
use App\Events\DvrRecordingStatusEvent;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();
    $this->group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for($this->group)
        ->create(['enabled' => true, 'title_custom' => 'News 24']);
});

it('broadcasts on creation with the recording scoped to the owning playlist channel', function () {
    Event::fake([DvrRecordingStatusEvent::class]);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled, 'title' => 'Evening News']);

    Event::assertDispatched(
        DvrRecordingStatusEvent::class,
        function (DvrRecordingStatusEvent $event) use ($recording) {
            return $event->uuid === $recording->uuid
                && $event->status === 'scheduled'
                && $event->channelId === $this->channel->id
                && $event->title === 'Evening News'
                && $event->notifiableType === $this->playlist->getMorphClass()
                && $event->notifiableUuid === $this->playlist->uuid;
        }
    );
});

it('broadcasts again when the status transitions', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    Event::fake([DvrRecordingStatusEvent::class]);

    $recording->update(['status' => DvrRecordingStatus::Recording]);

    Event::assertDispatched(
        DvrRecordingStatusEvent::class,
        fn (DvrRecordingStatusEvent $event) => $event->uuid === $recording->uuid
            && $event->status === 'recording'
    );
});

it('does not broadcast when an update leaves status unchanged', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    Event::fake([DvrRecordingStatusEvent::class]);

    $recording->update(['title' => 'Renamed']);

    Event::assertNotDispatched(DvrRecordingStatusEvent::class);
});

it('broadcasts a deleted status when the recording is removed', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Completed, 'title' => 'Evening News']);

    Event::fake([DvrRecordingStatusEvent::class]);

    $recording->delete();

    Event::assertDispatched(
        DvrRecordingStatusEvent::class,
        fn (DvrRecordingStatusEvent $event) => $event->uuid === $recording->uuid
            && $event->status === 'deleted'
            && $event->channelId === $this->channel->id
    );
});

it('broadcasts on the owner and admin channels scoped to the playlist uuid', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->make(['status' => DvrRecordingStatus::Scheduled]);
    $recording->save();

    $event = DvrRecordingStatusEvent::fromRecording($recording->fresh());
    $channels = collect($event->broadcastOn())->pluck('name');

    expect($channels)->toHaveCount(2);
    expect($channels)->toContain(
        "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}",
        "private-tv.{$this->playlist->getMorphClass()}-admin.{$this->playlist->uuid}",
    );
    expect($event->broadcastAs())->toBe('dvr.status');
});

it('also broadcasts on every entitled PlaylistAuth channel, not just the owner channel', function () {
    // Regression: a TV session logged in via PlaylistAuth credentials (rather
    // than owner_auth) subscribes only to its own `tv.{type}.{uuid}.{authId}`
    // channel — never the bare owner channel. Before this fix, dvr.status
    // pushes only went to the owner channel, so such sessions never saw the
    // EPG "recording" dot or DVR status label update from the push alone.
    $playlistAuth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true, 'dvr_enabled' => true]);
    $playlistAuth->assignTo($this->playlist);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->make(['status' => DvrRecordingStatus::Scheduled]);
    $recording->save();

    $event = DvrRecordingStatusEvent::fromRecording($recording->fresh());
    $channels = collect($event->broadcastOn())->pluck('name');

    expect($channels)->toContain(
        "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$playlistAuth->id}"
    );
});

it('does not broadcast dvr.status to a PlaylistAuth channel whose DVR access is disabled', function () {
    $playlistAuth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true, 'dvr_enabled' => false]);
    $playlistAuth->assignTo($this->playlist);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->make(['status' => DvrRecordingStatus::Scheduled]);
    $recording->save();

    $event = DvrRecordingStatusEvent::fromRecording($recording->fresh());
    $channels = collect($event->broadcastOn())->pluck('name');

    expect($channels)->not->toContain(
        "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$playlistAuth->id}"
    );
});

it('serializes the wire payload with snake_case keys matching DvrRecording.fromXtream on the client', function () {
    // Regression: Laravel's default broadcast payload serializes constructor
    // property names verbatim (camelCase). The TV client parses snake_case
    // (channel_id, channel_name, scheduled_start, scheduled_end) via the same
    // DvrRecording.fromXtream() used for the REST response — a camelCase leak
    // here means those fields silently come back null on the client.
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::Recording,
            'title' => 'Evening News',
            'scheduled_start' => '2026-07-22T20:00:00Z',
            'scheduled_end' => '2026-07-22T21:00:00Z',
        ]);

    $event = DvrRecordingStatusEvent::fromRecording($recording->fresh());

    expect($event->broadcastWith())->toBe([
        'notifiableType' => $this->playlist->getMorphClass(),
        'notifiableUuid' => $this->playlist->uuid,
        'uuid' => $recording->uuid,
        'status' => 'recording',
        'channel_id' => $this->channel->id,
        'channel_name' => 'News 24',
        'title' => 'Evening News',
        'scheduled_start' => $recording->fresh()->scheduled_start->toIso8601String(),
        'scheduled_end' => $recording->fresh()->scheduled_end->toIso8601String(),
    ]);
});
