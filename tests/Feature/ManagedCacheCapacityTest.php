<?php

use App\Enums\CacheDispatchResult;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Services\ManagedCacheAccountGroupIdentity;
use App\Services\ManagedCacheCapacityService;
use App\Services\ManagedCacheCapacitySnapshot;
use App\Services\ManagedCacheCapacitySnapshotSource;
use App\Services\XtreamManagedCacheCapacitySnapshotSource;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    app()->forgetInstance('cache.store');
    Http::preventStrayRequests();
});

it('fails closed when managed cache capacity is unknown', function () {
    expect(app(ManagedCacheCapacityService::class)->availability(null, null, null))
        ->toBe(CacheDispatchResult::Deferred);
});

it('binds the managed cache capacity interface to the Xtream source', function () {
    expect(app(ManagedCacheCapacitySnapshotSource::class))
        ->toBeInstanceOf(XtreamManagedCacheCapacitySnapshotSource::class);
});

it('rejects Xtream counters when active connections exceed the configured limit', function () {
    $playlist = new Playlist([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'https://provider.test',
            'username' => 'account-42',
            'password' => 'synthetic-secret',
        ],
    ]);
    $channel = new Channel(['url' => 'https://provider.test/movie/account-42/synthetic-secret/1']);
    $channel->setRelation('playlist', $playlist);

    Http::fake([
        'https://provider.test/player_api.php?username=account-42&password=synthetic-secret' => Http::response([
            'user_info' => [
                'auth' => 1,
                'max_connections' => 3,
                'active_cons' => 4,
            ],
        ]),
    ]);

    expect(app(XtreamManagedCacheCapacitySnapshotSource::class)->snapshotFor($channel))->toBeNull();
});

it('returns a fresh opaque Xtream account snapshot for configured channels and episodes', function () {
    $playlist = new Playlist([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'https://provider.test',
            'username' => 'account-42',
            'password' => 'synthetic-secret',
        ],
    ]);
    $channel = new Channel(['url' => 'https://provider.test/movie/account-42/synthetic-secret/1']);
    $channel->setRelation('playlist', $playlist);
    $episode = new Episode(['url' => 'https://provider.test/series/account-42/synthetic-secret/2']);
    $episode->setRelation('playlist', $playlist);

    Http::fake([
        'https://provider.test/player_api.php?username=account-42&password=synthetic-secret' => Http::response([
            'user_info' => [
                'auth' => 1,
                'max_connections' => 3,
                'active_cons' => 1,
            ],
        ]),
    ]);

    $source = app(XtreamManagedCacheCapacitySnapshotSource::class);
    $channelSnapshot = $source->snapshotFor($channel);
    expect($source->snapshotFor($episode)?->group)->toBe($channelSnapshot?->group);
    $episodeSnapshot = app(XtreamManagedCacheCapacitySnapshotSource::class)->snapshotFor($episode);

    expect($channelSnapshot)->not->toBeNull()
        ->and($channelSnapshot->limit)->toBe(3)
        ->and($channelSnapshot->live)->toBe(1)
        ->and($channelSnapshot->background)->toBe(0)
        ->and($channelSnapshot->group)->toBe($episodeSnapshot?->group)
        ->and($channelSnapshot->group)->not->toContain('account-42')
        ->and($channelSnapshot->group)->not->toContain('provider.test');

    Http::assertSentCount(2);
});

it('shares an Xtream account group when one playlist adds a fallback URL', function () {
    $config = [
        'url' => 'https://provider.test',
        'username' => 'account-42',
        'password' => 'synthetic-secret',
    ];
    $primaryOnly = new Playlist(['xtream' => true, 'xtream_config' => $config]);
    $withFallback = new Playlist([
        'xtream' => true,
        'xtream_config' => $config,
        'xtream_fallback_urls' => ['https://fallback.provider.test'],
    ]);
    $primaryChannel = new Channel(['url' => 'https://provider.test/movie/account-42/synthetic-secret/1']);
    $fallbackChannel = new Channel(['url' => 'https://provider.test/movie/account-42/synthetic-secret/2']);
    $primaryChannel->setRelation('playlist', $primaryOnly);
    $fallbackChannel->setRelation('playlist', $withFallback);

    Http::fake([
        'https://provider.test/player_api.php?username=account-42&password=synthetic-secret' => Http::response([
            'user_info' => ['auth' => 1, 'max_connections' => 3, 'active_cons' => 1],
        ]),
    ]);

    $source = app(XtreamManagedCacheCapacitySnapshotSource::class);

    expect($source->snapshotFor($primaryChannel)?->group)
        ->toBe($source->snapshotFor($fallbackChannel)?->group);
});

it('defers a cache item whose source URL is not owned by its Xtream account', function () {
    $playlist = new Playlist([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'https://provider.test',
            'username' => 'account-42',
            'password' => 'synthetic-secret',
        ],
    ]);
    $channel = new Channel(['url_custom' => 'https://foreign-provider.test/movie/other-account/other-secret/1']);
    $channel->setRelation('playlist', $playlist);

    Http::fake();

    expect(app(XtreamManagedCacheCapacitySnapshotSource::class)->snapshotFor($channel))->toBeNull();

    Http::assertNothingSent();
});

it('defers a cache item configured with a non-HTTP Xtream origin', function () {
    $playlist = new Playlist([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'ftp://provider.test',
            'username' => 'account-42',
            'password' => 'synthetic-secret',
        ],
    ]);
    $channel = new Channel(['url' => 'ftp://provider.test/movie/account-42/synthetic-secret/1']);
    $channel->setRelation('playlist', $playlist);

    Http::fake();

    expect(app(XtreamManagedCacheCapacitySnapshotSource::class)->snapshotFor($channel))->toBeNull();

    Http::assertNothingSent();
});

it('encodes Xtream credentials when requesting a fresh capacity snapshot', function () {
    $username = 'account&other=attacker';
    $password = 'secret?x=1';
    $playlist = new Playlist([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'https://provider.test',
            'username' => $username,
            'password' => $password,
        ],
    ]);
    $channel = new Channel([
        'url' => 'https://provider.test/movie/'.rawurlencode($username).'/'.rawurlencode($password).'/1',
    ]);
    $channel->setRelation('playlist', $playlist);

    Http::fake([
        'https://provider.test/player_api.php?username=account%26other%3Dattacker&password=secret%3Fx%3D1' => Http::response([
            'user_info' => ['auth' => 1, 'max_connections' => 3, 'active_cons' => 1],
        ]),
    ]);

    expect(app(XtreamManagedCacheCapacitySnapshotSource::class)->snapshotFor($channel))->not->toBeNull();
});

it('reserves one provider slot for live playback and one for managed background work', function () {
    $service = app(ManagedCacheCapacityService::class);

    expect($service->availability(3, 1, 0))->toBe(CacheDispatchResult::Queued)
        ->and($service->availability(3, 2, 0))->toBe(CacheDispatchResult::Deferred)
        ->and($service->availability(3, 1, 1))->toBe(CacheDispatchResult::Deferred)
        ->and($service->availability(3, 3, 0))->toBe(CacheDispatchResult::Deferred);
});

it('atomically reserves one managed prefetch per account group until it is released', function () {
    $service = app(ManagedCacheCapacityService::class);
    $group = app(ManagedCacheAccountGroupIdentity::class)->for('https://provider.example', 'account-42');
    $snapshot = new ManagedCacheCapacitySnapshot($group, limit: 3, live: 1, background: 0);

    $reservation = $service->reserve($snapshot);

    expect($reservation)->not->toBeNull()
        ->and($service->reserve($snapshot))->toBeNull();

    $service->release($reservation);

    expect($service->reserve($snapshot))->not->toBeNull();
});

it('expires an unrenewed reservation after two minutes', function () {
    $service = app(ManagedCacheCapacityService::class);
    $snapshot = new ManagedCacheCapacitySnapshot('group-a', limit: 3, live: 1, background: 0);

    expect($service->reserve($snapshot))->not->toBeNull();

    $this->travel(121)->seconds();

    expect($service->reserve($snapshot))->not->toBeNull();
});

it('renews an active reservation heartbeat without reviving a released token', function () {
    $service = app(ManagedCacheCapacityService::class);
    $reservation = $service->reserve(new ManagedCacheCapacitySnapshot('group-a', limit: 3, live: 1, background: 0));

    expect($service->renew($reservation))->toBeTrue();

    $service->release($reservation);

    expect($service->renew($reservation))->toBeFalse();
});

it('rejects a stale reservation when the snapshot group or capacity no longer matches', function () {
    $service = app(ManagedCacheCapacityService::class);
    $reservation = $service->reserve(new ManagedCacheCapacitySnapshot('group-a', limit: 3, live: 1, background: 0));

    expect($service->canUseReservation($reservation, new ManagedCacheCapacitySnapshot('group-b', limit: 3, live: 1, background: 0)))->toBeFalse()
        ->and($service->canUseReservation($reservation, new ManagedCacheCapacitySnapshot('group-a', limit: 3, live: 2, background: 0)))->toBeFalse()
        ->and($service->canUseReservation($reservation, new ManagedCacheCapacitySnapshot('group-a', limit: 3, live: 1, background: 0)))->toBeTrue();
});

it('keeps a file reservation available for the downloader until it releases the matching token', function () {
    $service = app(ManagedCacheCapacityService::class);
    $snapshot = new ManagedCacheCapacitySnapshot('opaque-group', limit: 3, live: 1, background: 0);
    $reservation = $service->reserve($snapshot);

    $service->attachToFile(42, $reservation);

    expect($service->reservationForFile(42))->toBe($reservation);

    $service->release($reservation);

    expect($service->reserve($snapshot))->not->toBeNull();
});

it('defers unbounded or incomplete snapshots without creating a reservation', function () {
    $service = app(ManagedCacheCapacityService::class);
    $group = app(ManagedCacheAccountGroupIdentity::class)->for('https://provider.example', 'account-42');

    expect($service->reserve(new ManagedCacheCapacitySnapshot($group, limit: null, live: 0, background: 0)))->toBeNull()
        ->and($service->reserve(new ManagedCacheCapacitySnapshot($group, limit: 3, live: null, background: 0)))->toBeNull();
});
