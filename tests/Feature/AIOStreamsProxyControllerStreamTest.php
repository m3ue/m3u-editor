<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
});

it('rewrites each stream candidate url to a proxied url, never returning the raw resolved url', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response([
            'streams' => [
                ['name' => 'Movie.720p', 'url' => 'https://debrid.example.com/secret-token/720p.mkv'],
                ['name' => 'Movie.1080p', 'url' => 'https://debrid.example.com/secret-token/1080p.mkv'],
            ],
        ], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertOk();

    $streams = $response->json('streams');

    expect($streams)->toHaveCount(2);

    foreach ($streams as $stream) {
        expect($stream['url'])
            ->toContain("/aiostreams-media/{$this->integration->id}/live/")
            ->not->toContain('debrid.example.com')
            ->not->toContain('secret-token');
    }
});

it('returns unauthorized for unrecognized credentials', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => []], 200),
    ]);

    $response = $this->get("/{$this->user->name}/wrong-password/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});
