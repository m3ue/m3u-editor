<?php

/**
 * Covers two related fixes to the VOD metadata sync pipeline:
 *
 * - Channel::fetchMetadata() previously treated an empty/failed provider
 *   response as success (persisting last_metadata_fetch and returning true)
 *   because XtreamService::call() swallows transport/HTTP failures into an
 *   empty array rather than throwing.
 * - ProcessVodChannelsComplete previously always reported success and
 *   cleared `errors` to null, even when every attempted metadata fetch
 *   failed, hiding the failure from the user and the playlist status.
 */

use App\Enums\Status;
use App\Jobs\ProcessVodChannelsComplete;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

function vodProviderFixture(array $payload): object
{
    return new class($payload)
    {
        public function __construct(private readonly array $payload) {}

        public function getVodInfo(string $vodId, int $timeout = 60): array
        {
            return $this->payload;
        }
    };
}

it('treats an empty provider response as a failed metadata fetch', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => null,
    ]);

    // Mirrors what XtreamService::getVodInfo() returns after every retry to a
    // failing provider: an empty array, not an exception.
    $result = $channel->fetchMetadata(vodProviderFixture([]), skipTmdb: true);

    expect($result)->toBeFalse()
        ->and($channel->refresh()->last_metadata_fetch)->toBeNull();
});

it('still succeeds when the provider returns real info', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => null,
    ]);

    $result = $channel->fetchMetadata(vodProviderFixture(['info' => ['plot' => 'A movie']]), skipTmdb: true);

    expect($result)->toBeTrue()
        ->and($channel->refresh()->last_metadata_fetch)->not->toBeNull();
});

it('keeps movie_data when the provider answers without extended info', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => null,
    ]);

    $result = $channel->fetchMetadata(vodProviderFixture([
        'info' => [],
        'movie_data' => ['stream_id' => 1, 'name' => 'A movie', 'container_extension' => 'mkv'],
    ]), skipTmdb: true);

    $channel->refresh();
    expect($result)->toBeTrue()
        ->and($channel->last_metadata_fetch)->not->toBeNull()
        ->and($channel->movie_data['container_extension'] ?? null)->toBe('mkv');
});

it('reports a danger notification and keeps errors set when every attempted metadata fetch failed', function () {
    Notification::fake();

    (new ProcessVodChannelsComplete(
        playlist: $this->playlist,
        metadataAttempted: 5,
        metadataFailed: 5,
    ))->handle(app(GeneralSettings::class));

    expect($this->playlist->refresh()->errors)->not->toBeNull()
        ->and($this->playlist->status)->toBe(Status::Completed);

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'danger',
    );
    Notification::assertNotSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'success',
    );
});

it('reports a warning notification when some, but not all, metadata fetches failed', function () {
    Notification::fake();

    (new ProcessVodChannelsComplete(
        playlist: $this->playlist,
        metadataAttempted: 5,
        metadataFailed: 2,
    ))->handle(app(GeneralSettings::class));

    expect($this->playlist->refresh()->errors)->toBeNull();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'warning',
    );
});

it('still reports success when no metadata fetches failed', function () {
    Notification::fake();

    (new ProcessVodChannelsComplete(
        playlist: $this->playlist,
        metadataAttempted: 5,
        metadataFailed: 0,
    ))->handle(app(GeneralSettings::class));

    expect($this->playlist->refresh()->errors)->toBeNull();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['status'] === 'success',
    );
});
