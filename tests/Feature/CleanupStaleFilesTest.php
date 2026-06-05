<?php

use App\Models\Epg;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('removes stale playlist directories and loose files', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['uuid' => 'known-playlist-uuid']);
    Storage::disk('local')->makeDirectory("playlist/{$playlist->uuid}");
    Storage::disk('local')->makeDirectory('playlist/stale-uuid-1');
    Storage::disk('local')->put('playlist/stale-uuid-2.m3u', 'content');
    Storage::disk('local')->put("playlist/{$playlist->uuid}.m3u", 'content');

    $this->artisan('app:cleanup-stale-files', ['--force' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists("playlist/{$playlist->uuid}");
    Storage::disk('local')->assertExists("playlist/{$playlist->uuid}.m3u");
    Storage::disk('local')->assertMissing('playlist/stale-uuid-1');
    Storage::disk('local')->assertMissing('playlist/stale-uuid-2.m3u');
});

it('removes stale epg and epg-cache directories', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly(['uuid' => 'known-epg-uuid']);
    Storage::disk('local')->makeDirectory("epg/{$epg->uuid}");
    Storage::disk('local')->makeDirectory('epg/stale-epg-uuid');
    Storage::disk('local')->makeDirectory("epg-cache/{$epg->uuid}/v2");
    Storage::disk('local')->makeDirectory('epg-cache/stale-epg-uuid/v2');

    $this->artisan('app:cleanup-stale-files', ['--force' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists("epg/{$epg->uuid}");
    Storage::disk('local')->assertExists("epg-cache/{$epg->uuid}");
    Storage::disk('local')->assertMissing('epg/stale-epg-uuid');
    Storage::disk('local')->assertMissing('epg-cache/stale-epg-uuid');
});

it('does not delete anything in dry-run mode', function () {
    Storage::disk('local')->makeDirectory('playlist/orphaned-uuid');
    Storage::disk('local')->put('playlist/orphaned-uuid.m3u', 'content');

    $this->artisan('app:cleanup-stale-files', ['--dry-run' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists('playlist/orphaned-uuid');
    Storage::disk('local')->assertExists('playlist/orphaned-uuid.m3u');
});
