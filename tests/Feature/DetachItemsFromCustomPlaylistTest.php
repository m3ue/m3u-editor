<?php

use App\Jobs\AddItemsToCustomPlaylist;
use App\Jobs\DetachItemsFromCustomPlaylist;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    // The jobs fan their work out via Bus::batch(); run the batch on the real
    // sync queue driver so the chunk jobs execute inline against the test database
    config(['queue.default' => 'sync']);

    $this->user = User::factory()->create();
    // Fixture only: created quietly so the source playlist does not kick off a real M3U import
    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
});

it('removes the pivot row and the playlist tag for detached channels', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Some Tag'],
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        type: 'channel',
    ))->handle();

    $channel->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeFalse()
        ->and($channel->tags()->where('type', $this->customPlaylist->uuid)->exists())->toBeFalse();
});

it('does not affect a tag from the same playlist type on a different item', function () {
    $channelToDetach = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);
    $channelToKeep = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channelToDetach->id, $channelToKeep->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Shared Tag'],
        type: 'channel',
    ))->handle();

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channelToDetach->id],
        customPlaylistId: $this->customPlaylist->id,
        type: 'channel',
    ))->handle();

    $channelToKeep->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channelToKeep->id)->exists())->toBeTrue()
        ->and($channelToKeep->tags->pluck('name')->all())->toContain('Shared Tag');
});

it('handles a selection spanning multiple chunk boundaries', function () {
    $channels = Channel::factory()->count(2500)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Bulk Tag'],
        type: 'channel',
    ))->handle();

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->count())->toBe(0);

    // The work must have fanned out as one chunk job per 1000 items, and the
    // batch must have run every chunk to completion without failures
    $batch = DB::table('job_batches')->where('name', 'detach-items-from-custom-playlist')->first();
    expect($batch)->not->toBeNull()
        ->and($batch->total_jobs)->toBe(3)
        ->and($batch->pending_jobs)->toBe(0)
        ->and($batch->failed_jobs)->toBe(0)
        ->and($batch->finished_at)->not->toBeNull();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'success'
            && $notification->data['title'] === 'Items detached from custom playlist',
    );
});

it('sends a failure notification when the job fails', function () {
    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: [1],
        customPlaylistId: $this->customPlaylist->id,
        type: 'channel',
    ))->failed(new Exception('Something went wrong'));

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'danger'
            && $notification->data['title'] === 'Failed to detach items from custom playlist'
            && $notification->data['body'] === 'Something went wrong',
    );
});

it('detaches series from the custom playlist', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$series->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Drama'],
        type: 'series',
    ))->handle();

    (new DetachItemsFromCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$series->id],
        customPlaylistId: $this->customPlaylist->id,
        type: 'series',
    ))->handle();

    expect($this->customPlaylist->series()->where('series.id', $series->id)->exists())->toBeFalse();
});
