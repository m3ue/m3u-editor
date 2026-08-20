<?php

use App\Jobs\AddItemsToCustomPlaylist;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    // The job fans its work out via Bus::batch(); run the batch on the real
    // sync queue driver so the chunk jobs execute inline against the test database
    config(['queue.default' => 'sync']);

    $this->user = User::factory()->create();
    // Fixture only: created quietly so the source playlist does not kick off a real M3U import
    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
});

it('syncs items to the custom playlist pivot', function () {
    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'My Group Tag'],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
        $channel->refresh();
        expect($channel->tags->pluck('name')->all())->toContain('My Group Tag');
    }
});

it('creates and attaches a new tag in create mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'create', 'new_category' => 'Brand New Tag'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    expect($channel->tags->pluck('name')->all())->toContain('Brand New Tag');
});

it('re-tagging with a new shared tag replaces the previous tag, not accumulates it', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'First Tag'],
        type: 'channel',
    ))->handle();

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Second Tag'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    $tagNames = $channel->tags()->where('type', $this->customPlaylist->uuid)->pluck('name')->all();

    expect($tagNames)->toContain('Second Tag')
        ->and($tagNames)->not->toContain('First Tag')
        ->and($tagNames)->toHaveCount(1);
});

it('tags each item with its own group name in original mode, not a batch-wide value', function () {
    $channelA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'group' => 'Sports',
    ]);
    $channelB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'group' => 'News',
    ]);
    $channelC = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'group' => 'Movies',
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channelA->id, $channelB->id, $channelC->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    $channelA->refresh();
    $channelB->refresh();
    $channelC->refresh();

    expect($channelA->tags->pluck('name')->all())->toContain('Sports')
        ->and($channelB->tags->pluck('name')->all())->toContain('News')
        ->and($channelC->tags->pluck('name')->all())->toContain('Movies');
});

it('does not skip a group literally named "0" in original mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'group' => '0',
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    $channel->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue()
        ->and($channel->tags->pluck('name')->all())->toContain('0');
});

it('does not create a tag for items with no group set in original mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'group' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    $channel->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue()
        ->and($channel->tags)->toBeEmpty();
});

it('handles a selection spanning multiple chunk boundaries', function () {
    $channels = Channel::factory()->count(1200)->create([
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

    expect($this->customPlaylist->channels()->count())->toBe(1200);

    // The work must have fanned out as one chunk job per 1000 items, and the
    // batch must have run every chunk to completion without failures
    $batch = DB::table('job_batches')->where('name', 'add-items-to-custom-playlist')->first();
    expect($batch)->not->toBeNull()
        ->and($batch->total_jobs)->toBe(2)
        ->and($batch->pending_jobs)->toBe(0)
        ->and($batch->failed_jobs)->toBe(0)
        ->and($batch->finished_at)->not->toBeNull();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'success'
            && $notification->data['title'] === 'Items added to custom playlist',
    );
});

it('buckets interleaved group names across the whole selection in original mode', function () {
    // Alternate the two groups item by item so every 1000-item slice contains both
    $channels = Channel::factory()
        ->count(1200)
        ->state(new Sequence(['group' => 'Sports'], ['group' => 'News']))
        ->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => null,
        ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    // One chunk job per group (600 items each), not one per group per slice
    $batch = DB::table('job_batches')->where('name', 'add-items-to-custom-playlist')->first();
    expect($batch->total_jobs)->toBe(2)
        ->and($batch->failed_jobs)->toBe(0)
        ->and($this->customPlaylist->channels()->count())->toBe(1200)
        ->and($this->customPlaylist->channels()->withAnyTags(['Sports'], $this->customPlaylist->uuid)->count())->toBe(600)
        ->and($this->customPlaylist->channels()->withAnyTags(['News'], $this->customPlaylist->uuid)->count())->toBe(600);
});

it('notifies completion immediately for an empty selection without dispatching a batch', function () {
    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Bulk Tag'],
        type: 'channel',
    ))->handle();

    expect(DB::table('job_batches')->count())->toBe(0);

    Notification::assertSentTo($this->user, DatabaseNotification::class);
});

it('syncs series to the custom playlist and tags with category name in original mode', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Drama',
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$series->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'series',
    ))->handle();

    $series->refresh();

    expect($this->customPlaylist->series()->where('series.id', $series->id)->exists())->toBeTrue()
        ->and($series->tags->pluck('name')->all())->toContain('Drama');
});
