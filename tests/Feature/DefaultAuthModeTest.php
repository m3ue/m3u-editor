<?php

/**
 * A playlist's default login (the owner's m3u editor username + a password) follows
 * its default_auth_mode: UUID as Password (the default, unchanged behavior), Custom
 * Password (the UUID stops working) or Disabled (Playlist Auths only). URLs the app
 * builds for itself keep working through a private internal token.
 */

use App\Enums\DefaultAuthMode;
use App\Facades\PlaylistFacade;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Livewire\XtreamApiInfo;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\PlaylistCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires the sync pipeline; Bus::fake() keeps it off Redis.
    Bus::fake();

    $this->user = User::factory()->create(['name' => 'owner', 'permissions' => ['use_proxy']]);
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->resolver = new PlaylistCredentialResolver;
});

function setDefaultAuth(Playlist $playlist, DefaultAuthMode $mode, ?string $password = null): void
{
    $playlist->update(['default_auth_mode' => $mode, 'default_auth_password' => $password]);
}

function attachGuestAuth(Playlist $playlist): PlaylistAuth
{
    $auth = PlaylistAuth::create([
        'name' => 'Guest',
        'username' => 'guest-1',
        'password' => 'guest-pass',
        'enabled' => true,
        'user_id' => $playlist->user_id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    return $auth;
}

// --- Credential resolution ---

it('keeps the owner + UUID login by default', function () {
    expect($this->playlist->fresh()->getDefaultAuthMode())->toBe(DefaultAuthMode::Uuid)
        ->and($this->resolver->resolveDefaultLogin('owner', $this->playlist->uuid)?->is($this->playlist))->toBeTrue()
        ->and(PlaylistFacade::authenticate('owner', $this->playlist->uuid)[1])->toBe('owner_auth');
});

it('accepts only the custom password in Custom Password mode', function () {
    setDefaultAuth($this->playlist, DefaultAuthMode::Custom, 'tv-pass-123');

    expect($this->resolver->resolveDefaultLogin('owner', 'tv-pass-123')?->is($this->playlist))->toBeTrue()
        ->and(PlaylistFacade::authenticate('owner', 'tv-pass-123')[1])->toBe('owner_auth')
        ->and($this->resolver->resolveDefaultLogin('owner', $this->playlist->uuid))->toBeNull()
        ->and($this->resolver->resolveDefaultLogin('someone-else', 'tv-pass-123'))->toBeNull()
        ->and($this->resolver->resolveDefaultLogin('owner', 'TV-PASS-123'))->toBeNull()
        ->and(PlaylistFacade::authenticate('owner', $this->playlist->uuid)[0])->toBeNull();
});

it('rejects the owner login when the default login is disabled but keeps Playlist Auths working', function () {
    setDefaultAuth($this->playlist, DefaultAuthMode::Disabled);
    attachGuestAuth($this->playlist);

    $guestLogin = PlaylistFacade::authenticate('guest-1', 'guest-pass');

    expect(PlaylistFacade::authenticate('owner', $this->playlist->uuid)[0])->toBeNull()
        ->and($guestLogin[0]?->is($this->playlist))->toBeTrue()
        ->and($guestLogin[1])->toBe('playlist_auth');
});

it('tells the owners apart when two users pick the same custom password', function () {
    setDefaultAuth($this->playlist, DefaultAuthMode::Custom, 'same-pass');
    $otherUser = User::factory()->create(['name' => 'other']);
    $otherPlaylist = CustomPlaylist::factory()->for($otherUser)->create([
        'default_auth_mode' => DefaultAuthMode::Custom,
        'default_auth_password' => 'same-pass',
    ]);

    expect($this->resolver->resolveDefaultLogin('owner', 'same-pass')?->is($this->playlist))->toBeTrue()
        ->and($this->resolver->resolveDefaultLogin('other', 'same-pass')?->is($otherPlaylist))->toBeTrue();
});

it('accepts the internal token in every mode, but not a forged one', function (DefaultAuthMode $mode) {
    setDefaultAuth($this->playlist, $mode, 'tv-pass-123');
    $token = PlaylistCredentialResolver::internalToken($this->playlist->uuid);

    expect($this->resolver->resolveDefaultLogin('owner', $token)?->is($this->playlist))->toBeTrue()
        ->and($this->resolver->resolveDefaultLogin('someone-else', $token))->toBeNull()
        ->and($this->resolver->resolveDefaultLogin('owner', $this->playlist->uuid.'_'.str_repeat('0', 32)))->toBeNull();
})->with([DefaultAuthMode::Uuid, DefaultAuthMode::Custom, DefaultAuthMode::Disabled]);

// --- URLs the app builds for itself ---

it('uses the UUID in in-app stream URLs while UUID login is on, and the internal token otherwise', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['enabled' => true]);
    $token = PlaylistCredentialResolver::internalToken($this->playlist->uuid);

    expect($channel->getProxyUrl())->toContain("/owner/{$this->playlist->uuid}/{$channel->id}");

    setDefaultAuth($this->playlist, DefaultAuthMode::Disabled);

    expect($channel->fresh()->getProxyUrl())->toContain('/owner/'.urlencode($token)."/{$channel->id}");
});

it('re-points DVR VOD stream URLs at the internal password when the default login mode changes', function () {
    $setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    $recording = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($this->user)->create();
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $vodChannel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create([
        'is_vod' => true,
        'dvr_recording_id' => $recording->id,
        'url' => "http://localhost/dvr/owner/{$this->playlist->uuid}/{$recording->uuid}.ts",
    ]);
    $token = PlaylistCredentialResolver::internalToken($this->playlist->uuid);

    setDefaultAuth($this->playlist, DefaultAuthMode::Custom, 'tv-pass-123');

    expect($vodChannel->fresh()->url)
        ->toContain("/dvr/owner/{$token}/{$recording->uuid}.")
        ->not->toContain("/owner/{$this->playlist->uuid}/");
});

it('serves the live DVR playlist to recording-channel viewers through a signed URL instead of owner credentials', function () {
    $setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    $recording = DvrRecording::factory()->recording()->for($setting, 'dvrSetting')->for($this->user)->create([
        'proxy_network_id' => 'network-1',
    ]);
    Http::fake(['*' => Http::response("#EXTM3U\nlive000001.ts\n")]);

    $signedUrl = URL::signedRoute('dvr.recording.hls.signed', ['uuid' => $recording->uuid], absolute: false);

    $this->get($signedUrl.'&client_id=player-1')
        ->assertOk()
        ->assertSee('/broadcast/network-1/segment/live000001.ts', false);

    $this->get("/dvr/signed/{$recording->uuid}/live.m3u8")->assertForbidden();
});

// --- M3U / HDHR outputs ---

it('requires the owner credentials for the M3U in Custom Password mode and embeds them in stream URLs', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => true,
        'url' => 'http://provider.example.com/live/u/p/1234.ts',
    ]);
    setDefaultAuth($this->playlist, DefaultAuthMode::Custom, 'tv-pass-123');

    $this->get("/{$this->playlist->uuid}/playlist.m3u")->assertUnauthorized();
    $this->get("/{$this->playlist->uuid}/playlist.m3u?username=owner&password={$this->playlist->uuid}")->assertUnauthorized();

    $response = $this->get("/{$this->playlist->uuid}/playlist.m3u?username=owner&password=tv-pass-123");

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain("/live/owner/tv-pass-123/{$channel->id}.")
        ->not->toContain("/owner/{$this->playlist->uuid}/");
});

it('requires a Playlist Auth for the M3U and HDHR lineup when the default login is disabled', function () {
    setDefaultAuth($this->playlist, DefaultAuthMode::Disabled);

    $this->get("/{$this->playlist->uuid}/playlist.m3u")->assertUnauthorized();
    $this->get("/{$this->playlist->uuid}/hdhr/lineup.json")->assertUnauthorized();

    attachGuestAuth($this->playlist);

    $this->get("/{$this->playlist->uuid}/playlist.m3u?username=guest-1&password=guest-pass")->assertOk();
    $this->get("/{$this->playlist->uuid}/hdhr/guest-1/guest-pass/lineup.json")->assertOk();
});

it('accepts the owner credentials on the HDHR path in Custom Password mode', function () {
    setDefaultAuth($this->playlist, DefaultAuthMode::Custom, 'tv-pass-123');

    $this->get("/{$this->playlist->uuid}/hdhr/lineup.json")->assertUnauthorized();
    $this->get("/{$this->playlist->uuid}/hdhr/owner/tv-pass-123/lineup.json")->assertOk();
});

// --- Info panels and output URLs ---

it('reports the default login per mode in the Xtream info and output URLs', function () {
    $uuidInfo = PlaylistFacade::getXtreamInfo($this->playlist->fresh());
    expect($uuidInfo['password'])->toBe($this->playlist->uuid)
        ->and($uuidInfo['mode'])->toBe(DefaultAuthMode::Uuid)
        ->and(PlaylistFacade::getUrls($this->playlist->fresh())['m3u'])->not->toContain('password=');

    setDefaultAuth($this->playlist, DefaultAuthMode::Custom, 'tv-pass-123');
    $urls = PlaylistFacade::getUrls($this->playlist->fresh());
    expect(PlaylistFacade::getXtreamInfo($this->playlist->fresh())['password'])->toBe('tv-pass-123')
        ->and($urls['m3u'])->toEndWith('?username=owner&password=tv-pass-123')
        ->and($urls['hdhr'])->toEndWith('/owner/tv-pass-123');

    setDefaultAuth($this->playlist, DefaultAuthMode::Disabled);
    expect(PlaylistFacade::getXtreamInfo($this->playlist->fresh())['password'])->toBeNull();
});

it('shows a disabled notice instead of credentials in the Xtream API panel', function () {
    setDefaultAuth($this->playlist, DefaultAuthMode::Disabled);

    Livewire::test(XtreamApiInfo::class, ['record' => $this->playlist->fresh()])
        ->assertSee(__('The default login is disabled for this playlist. Use one of its Playlist Auths to log in.'))
        ->assertDontSee($this->playlist->uuid);
});

// --- Playlist form ---

it('requires a custom password when Custom Password is selected', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm(['user_agent' => 'Test Agent', 'default_auth_mode' => DefaultAuthMode::Custom->value])
        ->call('save')
        ->assertHasFormErrors(['default_auth_password' => 'required']);
});

it('saves the default login mode and custom password from the playlist form', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'default_auth_mode' => DefaultAuthMode::Custom->value,
            'default_auth_password' => 'tv-pass-123',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $playlist = $this->playlist->fresh();
    expect($playlist->getDefaultAuthMode())->toBe(DefaultAuthMode::Custom)
        ->and($playlist->default_auth_password)->toBe('tv-pass-123');
});

it('rejects a custom password another of the owner\'s playlists already uses', function () {
    $this->actingAs($this->user);
    CustomPlaylist::factory()->for($this->user)->create([
        'default_auth_mode' => DefaultAuthMode::Custom,
        'default_auth_password' => 'tv-pass-123',
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'default_auth_mode' => DefaultAuthMode::Custom->value,
            'default_auth_password' => 'tv-pass-123',
        ])
        ->call('save')
        ->assertHasFormErrors(['default_auth_password']);
});
