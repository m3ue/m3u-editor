<?php

use App\Jobs\UpdateXtreamStats;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    config(['cache.default' => 'array']);
    $this->user = User::factory()->create();
});

function makeStatsAlias(Playlist $playlist, array $entry): PlaylistAlias
{
    return PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Stats Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => [$entry],
        'xtream_status' => json_encode(['user_info' => ['max_connections' => 9, 'active_cons' => 3]]),
    ]);
}

it('uses the source playlist account for an alias without credentials', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'xtream_config' => [
            'url' => 'http://source.example.com:8080',
            'username' => 'srcuser',
            'password' => 'srcpass',
        ],
    ]);
    $alias = makeStatsAlias($playlist, [
        'url' => 'http://source.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.example.com:8080',
    ]);

    Http::fake(['*' => Http::response(['user_info' => ['max_connections' => 2, 'active_cons' => 1, 'auth' => 1, 'status' => 'Active']])]);

    (new UpdateXtreamStats($alias))->handle();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://source.example.com:8080/')
        && str_contains($request->url(), 'username=srcuser'));
    expect($alias->fresh()->getRawOriginal('xtream_status'))->toContain('"max_connections":2');
});

it('clears stale status for an alias with no account to query', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['xtream_config' => null]);
    $alias = makeStatsAlias($playlist, [
        'url' => 'http://provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.example.com:8080',
    ]);

    Http::fake();

    (new UpdateXtreamStats($alias))->handle();

    Http::assertNothingSent();
    expect($alias->fresh()->getRawOriginal('xtream_status'))->toBeNull()
        ->and(Cache::get("a:{$alias->id}:xtream_status"))->toBe([]);
});
