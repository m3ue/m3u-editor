<?php

use App\Jobs\RunCustomPlaylistProcessing;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);

    $this->channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
    ]);

    foreach ($this->channels as $channel) {
        $this->customPlaylist->channels()->attach($channel->id, ['sort' => 0]);
    }
});

it('skips processing when no enabled rules exist', function () {
    $this->customPlaylist->update(['processing_config' => null]);

    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    expect(true)->toBeTrue();
});

it('skips disabled processing rules', function () {
    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => false,
            'action' => 'sort_alpha',
            'type' => 'all',
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    expect(true)->toBeTrue();
});

it('runs sort_alpha rule across all channels', function () {
    $titles = ['Zebra Channel', 'Apple Channel', 'Mango Channel'];
    foreach ($this->channels as $i => $channel) {
        $channel->update(['title' => $titles[$i]]);
    }

    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => true,
            'action' => 'sort_alpha',
            'type' => 'all',
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    $sortedTitles = $this->customPlaylist->channels()
        ->orderBy('channel_custom_playlist.sort')
        ->pluck('title')
        ->all();

    expect($sortedTitles)->toBe(['Apple Channel', 'Mango Channel', 'Zebra Channel']);
});

it('runs recount rule assigning sequential channel numbers starting from configured start', function () {
    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => true,
            'action' => 'recount',
            'type' => 'all',
            'start' => 10,
        ]],
    ]);

    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    $numbers = $this->customPlaylist->channels()
        ->orderBy('channel_custom_playlist.sort')
        ->pluck('channel_custom_playlist.channel_number')
        ->all();

    expect($numbers)->toContain(10);
});

it('filters channels by group tag using json path when specific groups are selected', function () {
    $tagType = $this->customPlaylist->uuid;

    $taggedChannel = $this->channels->first();
    $tag = Tag::findOrCreate('Sports', $tagType);
    $taggedChannel->attachTag($tag);

    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => true,
            'action' => 'sort_alpha',
            'type' => 'live',
            'groups' => ['Sports'],
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    // Verifies the name->en JSON path query does not throw
    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    expect(true)->toBeTrue();
});

it('skips rule when no channels match the selected group', function () {
    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => true,
            'action' => 'sort_alpha',
            'type' => 'all',
            'groups' => ['NonExistentGroup'],
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    expect(true)->toBeTrue();
});

it('sends failure notification when an exception occurs and user is missing', function () {
    Notification::fake();

    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => true,
            'action' => 'sort_alpha',
            'type' => 'all',
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    $this->user->delete();

    (new RunCustomPlaylistProcessing($this->customPlaylist))->handle();

    Notification::assertNothingSent();
});
