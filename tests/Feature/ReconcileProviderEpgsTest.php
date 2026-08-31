<?php

/**
 * app:reconcile-provider-epgs ties existing provider-created EPGs to their
 * playlist (backfilling epgs.playlist_id) and reports/prunes the duplicates
 * that the old URL-matching failover flow left behind.
 */

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
});

function providerPlaylist(User $user): Playlist
{
    return Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://primary.example.com:8080',
            'username' => 'user',
            'password' => 'pass',
            'import_epg' => true,
        ],
        'xtream_fallback_urls' => ['http://fallback.example.com:8080'],
    ]));
}

it('is a no-op dry run by default', function () {
    $user = User::factory()->create();
    $playlist = providerPlaylist($user);
    $epg = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
    ]);

    $this->artisan('app:reconcile-provider-epgs')->assertSuccessful();

    expect($epg->refresh()->playlist_id)->toBeNull();
});

it('ties the keeper EPG to the playlist with --apply', function () {
    $user = User::factory()->create();
    $playlist = providerPlaylist($user);

    $small = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
    ]);
    $large = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://fallback.example.com:8080/xmltv.php?username=user&password=pass',
    ]);
    EpgChannel::factory()->count(3)->for($user)->create(['epg_id' => $large->id]);

    $this->artisan('app:reconcile-provider-epgs --apply')->assertSuccessful();

    expect($large->refresh()->playlist_id)->toBe($playlist->id)
        ->and($small->refresh()->playlist_id)->toBeNull();
});

it('prunes an unreferenced duplicate with --apply --prune', function () {
    $user = User::factory()->create();
    $playlist = providerPlaylist($user);

    $keeper = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
    ]);
    EpgChannel::factory()->for($user)->create(['epg_id' => $keeper->id]);

    $duplicate = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://fallback.example.com:8080/xmltv.php?username=user&password=pass',
    ]);

    $this->artisan('app:reconcile-provider-epgs --apply --prune')->assertSuccessful();

    expect(Epg::whereKey($duplicate->id)->exists())->toBeFalse()
        ->and($keeper->refresh()->playlist_id)->toBe($playlist->id);
});

it('never prunes a duplicate that still has mapped channels', function () {
    $user = User::factory()->create();
    $playlist = providerPlaylist($user);

    $keeper = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
    ]);
    EpgChannel::factory()->count(2)->for($user)->create(['epg_id' => $keeper->id]);

    $duplicate = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'url' => 'http://fallback.example.com:8080/xmltv.php?username=user&password=pass',
    ]);
    $dupChannel = EpgChannel::factory()->for($user)->create(['epg_id' => $duplicate->id]);
    Channel::factory()->for($playlist)->for($user)->create(['epg_channel_id' => $dupChannel->id]);

    $this->artisan('app:reconcile-provider-epgs --apply --prune')->assertSuccessful();

    expect(Epg::whereKey($duplicate->id)->exists())->toBeTrue();
});

it('leaves an EPG tied to a different playlist alone', function () {
    $user = User::factory()->create();
    $playlist = providerPlaylist($user);
    $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create());

    $foreign = Epg::factory()->for($user)->create([
        'source_type' => 'url',
        'playlist_id' => $otherPlaylist->id,
        'url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
    ]);

    $this->artisan('app:reconcile-provider-epgs --apply')->assertSuccessful();

    expect($foreign->refresh()->playlist_id)->toBe($otherPlaylist->id);
});
