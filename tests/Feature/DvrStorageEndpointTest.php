<?php

/**
 * Coverage for the Xtream `get_dvr_storage` action, which lets the TV app show
 * the current user/guest's DVR storage usage against their quota.
 *
 * Locks in the privacy-scoping contract: a guest (PlaylistAuth) only ever sees
 * their own recordings' usage, never the account-wide total, regardless of
 * whether they have an explicit quota configured. The account owner sees the
 * whole DVR setting's usage against its global quota.
 */

use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

function dvrStorageUrl(string $username, string $password): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'get_dvr_storage',
    ]);
}

it('reports account-scoped usage against the global quota for the owner', function () {
    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['global_disk_quota_gb' => 10]);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create(['file_size_bytes' => 1073741824]); // 1 GB

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create(['file_size_bytes' => 536870912]); // 0.5 GB

    $response = $this->getJson(dvrStorageUrl($this->user->name, $this->playlist->uuid));

    $response->assertOk()->assertExactJson([
        'used_bytes' => 1610612736,
        'quota_bytes' => 10 * 1024 ** 3,
        'percent_used' => 15.0,
        'recording_count' => 2,
        'scope' => 'account',
    ]);
});

it('reports unlimited account quota when global_disk_quota_gb is zero', function () {
    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['global_disk_quota_gb' => 0]);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create(['file_size_bytes' => 1073741824]);

    $response = $this->getJson(dvrStorageUrl($this->user->name, $this->playlist->uuid));

    $response->assertOk()->assertJson([
        'used_bytes' => 1073741824,
        'quota_bytes' => null,
        'percent_used' => null,
        'recording_count' => 1,
        'scope' => 'account',
    ]);
});

it('reports the zeroed contract shape when DVR is not configured for the account', function () {
    $response = $this->getJson(dvrStorageUrl($this->user->name, $this->playlist->uuid));

    $response->assertOk()->assertExactJson([
        'used_bytes' => 0,
        'quota_bytes' => null,
        'percent_used' => null,
        'recording_count' => 0,
        'scope' => 'account',
    ]);
});

it('reports guest-scoped usage against the guest own quota', function () {
    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['global_disk_quota_gb' => 100]);

    $username = 'guest_'.Str::random(5);
    $password = 'guestpass';
    $auth = PlaylistAuth::create([
        'name' => 'Guest Sarah',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'dvr_enabled' => true,
        'dvr_storage_quota_gb' => 2,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create([
            'playlist_auth_id' => $auth->id,
            'file_size_bytes' => 1073741824, // 1 GB — the guest's own recording
        ]);

    // Another guest's recording under the same account must not count toward this guest's usage.
    $otherAuth = PlaylistAuth::factory()->for($this->user)->create(['dvr_enabled' => true]);
    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create([
            'playlist_auth_id' => $otherAuth->id,
            'file_size_bytes' => 5368709120, // 5 GB, must be excluded
        ]);

    // The owner's own (non-guest) recording must also not count toward this guest's usage.
    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create([
            'playlist_auth_id' => null,
            'file_size_bytes' => 3221225472, // 3 GB, must be excluded
        ]);

    $response = $this->getJson(dvrStorageUrl($username, $password));

    $response->assertOk()->assertExactJson([
        'used_bytes' => 1073741824,
        'quota_bytes' => 2 * 1024 ** 3,
        'percent_used' => 50.0,
        'recording_count' => 1,
        'scope' => 'guest',
    ]);
});

it('reports 100 percent used when the guest quota is configured as zero bytes', function () {
    // dvr_storage_quota_gb = 0 is distinct from null: null means unlimited, 0 means
    // the guest is capped at zero bytes (see PlaylistAuth::hasReachedStorageQuota()).
    // quota_bytes is therefore 0, not null — percent_used must not treat 0 as falsy.
    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['global_disk_quota_gb' => 100]);

    $username = 'guest_'.Str::random(5);
    $password = 'guestpass';
    $auth = PlaylistAuth::create([
        'name' => 'Guest Zero Quota',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'dvr_enabled' => true,
        'dvr_storage_quota_gb' => 0,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create([
            'playlist_auth_id' => $auth->id,
            'file_size_bytes' => 1073741824, // 1 GB
        ]);

    $response = $this->getJson(dvrStorageUrl($username, $password));

    $response->assertOk()->assertExactJson([
        'used_bytes' => 1073741824,
        'quota_bytes' => 0,
        'percent_used' => 100,
        'recording_count' => 1,
        'scope' => 'guest',
    ]);
});

it('reports unlimited guest quota when dvr_storage_quota_gb is null', function () {
    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['global_disk_quota_gb' => 100]);

    $username = 'guest_'.Str::random(5);
    $password = 'guestpass';
    $auth = PlaylistAuth::create([
        'name' => 'Guest Unlimited',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'dvr_enabled' => true,
        'dvr_storage_quota_gb' => null,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->playlist->dvrSetting)
        ->create([
            'playlist_auth_id' => $auth->id,
            'file_size_bytes' => 536870912, // 0.5 GB
        ]);

    $response = $this->getJson(dvrStorageUrl($username, $password));

    $response->assertOk()->assertExactJson([
        'used_bytes' => 536870912,
        'quota_bytes' => null,
        'percent_used' => null,
        'recording_count' => 1,
        'scope' => 'guest',
    ]);
});
