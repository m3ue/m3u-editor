<?php

use App\Exceptions\XtreamRateLimitedException;
use App\Jobs\ProcessVodChannelsChunk;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\XtreamService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('aborts the rest of the chunk once the Xtream account is rate limited', function () {
    Http::fake([
        '*get_vod_info*vod_id=1*' => Http::response(['info' => ['release_date' => '2024-01-01']], 200),
        '*get_vod_info*vod_id=2*' => Http::response('Too Many Requests', 429),
        '*get_vod_info*vod_id=3*' => Http::response(['info' => ['release_date' => '2024-01-01']], 200),
    ]);

    $playlist = Playlist::factory()->for(User::factory())->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://primary.example.com:8080',
            'username' => 'testuser',
            'password' => 'testpass',
        ],
        'xtream_fallback_urls' => null,
    ]);

    $channels = collect(['1', '2', '3'])->map(fn (string $id) => Channel::factory()
        ->for($playlist)
        ->for($playlist->user)
        ->create([
            'is_vod' => true,
            'source_id' => $id,
            'last_metadata_fetch' => null,
        ]));

    $job = new ProcessVodChannelsChunk(
        playlist: $playlist,
        channelIds: $channels->pluck('id')->toArray(),
        chunkIndex: 0,
        totalChunks: 1,
    );

    expect(fn () => $job->handle(app(XtreamService::class)))
        ->toThrow(XtreamRateLimitedException::class);

    // Channel 1 was fetched before the 429 hit.
    expect($channels[0]->refresh()->last_metadata_fetch)->not->toBeNull();

    // Channel 2 (the one that 429'd) and channel 3 (never reached) were skipped.
    expect($channels[1]->refresh()->last_metadata_fetch)->toBeNull()
        ->and($channels[2]->refresh()->last_metadata_fetch)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'vod_id=3'));
});
