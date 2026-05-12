<?php

use App\Enums\PlaylistSourceType;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

it('updates the url for an m3u playlist', function () {
    Bus::fake();
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
        'url' => 'https://old.example.com/playlist.m3u',
    ]);

    $response = $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com/playlist.m3u',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.url', 'https://new.example.com/playlist.m3u')
        ->assertJsonPath('data.resync_dispatched', false)
        ->assertJsonMissingPath('data.source_type');

    expect($playlist->fresh()->url)->toBe('https://new.example.com/playlist.m3u');
    Bus::assertNotDispatched(ProcessM3uImport::class);
});

it('dispatches a resync when resync=true is passed', function () {
    Bus::fake();
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
        'url' => 'https://old.example.com/playlist.m3u',
    ]);

    $response = $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com/playlist.m3u',
        'resync' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.resync_dispatched', true)
        ->assertJsonPath('message', 'Playlist updated successfully and resync dispatched');

    Bus::assertDispatched(
        ProcessM3uImport::class,
        fn (ProcessM3uImport $job) => $job->playlist->is($playlist) && $job->force === true,
    );
});

it('does not dispatch a resync when resync=false', function () {
    Bus::fake();
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
        'url' => 'https://old.example.com/playlist.m3u',
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com/playlist.m3u',
        'resync' => false,
    ])->assertOk()->assertJsonPath('data.resync_dispatched', false);

    Bus::assertNotDispatched(ProcessM3uImport::class);
});

it('strips trailing slashes from the url', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
        'url' => 'https://old.example.com/playlist.m3u',
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com/',
    ])->assertOk()->assertJsonPath('data.url', 'https://new.example.com');
});

it('updates xtream_config url and rotates credentials when provided', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::Xtream,
        'xtream_config' => [
            'url' => 'https://old.example.com:8080',
            'username' => 'olduser',
            'password' => 'oldpass',
            'output' => 'ts',
        ],
    ]);

    $response = $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.url', 'https://new.example.com:8080');

    $config = $playlist->fresh()->xtream_config;
    expect($config['url'])->toBe('https://new.example.com:8080')
        ->and($config['username'])->toBe('newuser')
        ->and($config['password'])->toBe('newpass')
        ->and($config['output'])->toBe('ts');
});

it('keeps existing xtream credentials when only url is updated', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::Xtream,
        'xtream_config' => [
            'url' => 'https://old.example.com:8080',
            'username' => 'keepme',
            'password' => 'keepmepass',
            'output' => 'm3u8',
        ],
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com:8080',
    ])->assertOk();

    $config = $playlist->fresh()->xtream_config;
    expect($config['url'])->toBe('https://new.example.com:8080')
        ->and($config['username'])->toBe('keepme')
        ->and($config['password'])->toBe('keepmepass')
        ->and($config['output'])->toBe('m3u8');
});

it('rejects credentials for non-xtream playlists', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
        'url' => 'https://old.example.com/playlist.m3u',
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com/playlist.m3u',
        'username' => 'should-not-apply',
    ])->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($playlist->fresh()->url)->toBe('https://old.example.com/playlist.m3u');
});

it('returns 422 when url is missing', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

it('returns 422 when url is invalid', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", ['url' => 'not-a-url'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

it('returns 404 when playlist does not exist', function () {
    $this->patchJson('/playlist/00000000-0000-0000-0000-000000000000', [
        'url' => 'https://new.example.com/playlist.m3u',
    ])->assertNotFound()->assertJsonPath('success', false);
});

it('returns 403 when playlist belongs to another user', function () {
    $other = User::factory()->create();
    $playlist = Playlist::factory()->for($other)->createQuietly([
        'source_type' => PlaylistSourceType::M3u,
        'url' => 'https://old.example.com/playlist.m3u',
    ]);

    $this->patchJson("/playlist/{$playlist->uuid}", [
        'url' => 'https://new.example.com/playlist.m3u',
    ])->assertForbidden()->assertJsonPath('success', false);

    expect($playlist->fresh()->url)->toBe('https://old.example.com/playlist.m3u');
});
