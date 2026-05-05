<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clean up any leftover playlist EPG cache files from previous runs
    Storage::disk('local')->deleteDirectory('playlist-epg-files');
});

test('epg download does not crash when epg source file is missing', function () {
    $user = User::factory()->create();

    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);

    // Create an EPG with a URL but no cached data and no file on disk
    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'url' => 'http://example.com/epg.xml',
        'is_cached' => false,
    ]);

    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'channel_id' => 'test-channel-1',
        'user_id' => $user->id,
    ]);

    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'channel' => 1,
    ]);

    // The EPG source file does not exist — should return valid XML without crashing
    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/gzip');

    $content = gzdecode($response->getContent());
    expect($content)->toContain('<?xml version="1.0"');
    expect($content)->toContain('</tv>');
});

test('epg download does not crash when epg source file is corrupted', function () {
    $user = User::factory()->create();

    $playlist = Playlist::factory()->for($user)->create([
        'dummy_epg' => false,
    ]);

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'url' => 'http://example.com/epg.xml',
        'is_cached' => false,
    ]);

    // Write a corrupted file at the expected path
    Storage::disk('local')->put($epg->file_path, 'not-valid-xml-or-gzip-data');

    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'channel_id' => 'test-channel-1',
        'user_id' => $user->id,
    ]);

    Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
        'channel' => 1,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/gzip');

    $content = gzdecode($response->getContent());
    expect($content)->toContain('<?xml version="1.0"');
    expect($content)->toContain('</tv>');

    // Cleanup
    Storage::disk('local')->delete($epg->file_path);
});
