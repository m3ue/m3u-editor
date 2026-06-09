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
    $playlist = Playlist::factory()->for($this->user)->createQuietly();
    Storage::disk('local')->makeDirectory("playlist/{$playlist->uuid}");
    Storage::disk('local')->makeDirectory('playlist/00000000-0000-0000-0000-000000000001');
    Storage::disk('local')->put('playlist/00000000-0000-0000-0000-000000000002.m3u', 'content');
    Storage::disk('local')->put("playlist/{$playlist->uuid}.m3u", 'content');

    $this->artisan('app:cleanup-stale-files', ['--force' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists("playlist/{$playlist->uuid}");
    Storage::disk('local')->assertExists("playlist/{$playlist->uuid}.m3u");
    Storage::disk('local')->assertMissing('playlist/00000000-0000-0000-0000-000000000001');
    Storage::disk('local')->assertMissing('playlist/00000000-0000-0000-0000-000000000002.m3u');
});

it('preserves manually uploaded playlist files referenced in the uploads column', function () {
    $uploadedFilename = 'KLmX92nBvY3abcdef.m3u';
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'uploads' => ["playlist/{$uploadedFilename}"],
    ]);
    Storage::disk('local')->put("playlist/{$uploadedFilename}", 'content');
    Storage::disk('local')->put('playlist/orphaned-non-uuid-file.m3u', 'content');

    $this->artisan('app:cleanup-stale-files', ['--force' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists("playlist/{$uploadedFilename}");
    Storage::disk('local')->assertMissing('playlist/orphaned-non-uuid-file.m3u');
});

it('preserves manually uploaded epg files referenced in the uploads column', function () {
    $uploadedFilename = 'epg-upload-abc123.xml';
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uploads' => ["epg/{$uploadedFilename}"],
    ]);
    Storage::disk('local')->put("epg/{$uploadedFilename}", 'content');
    Storage::disk('local')->put('epg/orphaned-non-uuid-file.xml', 'content');

    $this->artisan('app:cleanup-stale-files', ['--force' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists("epg/{$uploadedFilename}");
    Storage::disk('local')->assertMissing('epg/orphaned-non-uuid-file.xml');
});

it('removes stale epg and epg-cache directories', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly();
    Storage::disk('local')->makeDirectory("epg/{$epg->uuid}");
    Storage::disk('local')->makeDirectory('epg/00000000-0000-0000-0000-000000000003');
    Storage::disk('local')->makeDirectory("epg-cache/{$epg->uuid}/v2");
    Storage::disk('local')->makeDirectory('epg-cache/00000000-0000-0000-0000-000000000003/v2');

    $this->artisan('app:cleanup-stale-files', ['--force' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists("epg/{$epg->uuid}");
    Storage::disk('local')->assertExists("epg-cache/{$epg->uuid}");
    Storage::disk('local')->assertMissing('epg/00000000-0000-0000-0000-000000000003');
    Storage::disk('local')->assertMissing('epg-cache/00000000-0000-0000-0000-000000000003');
});

it('does not delete anything in dry-run mode', function () {
    Storage::disk('local')->makeDirectory('playlist/00000000-0000-0000-0000-000000000004');
    Storage::disk('local')->put('playlist/00000000-0000-0000-0000-000000000004.m3u', 'content');

    $this->artisan('app:cleanup-stale-files', ['--dry-run' => true])
        ->assertSuccessful();

    Storage::disk('local')->assertExists('playlist/00000000-0000-0000-0000-000000000004');
    Storage::disk('local')->assertExists('playlist/00000000-0000-0000-0000-000000000004.m3u');
});
