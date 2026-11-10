<?php

/**
 * Provider profiles on M3U playlists: creation through the playlist lifecycle and
 * the Provider Profiles section of the playlist edit form.
 */

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // Playlist::factory() fires the sync pipeline; Bus::fake() keeps it off the queue.
    Bus::fake();
    // The form's Pool Status callout asks the proxy for active stream counts.
    Http::fake();
    config(['proxy.m3u_proxy_token' => 'test-token', 'cache.default' => 'array']);

    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->actingAs($this->user);
});

function m3uProfilesFormPlaylist(User $user): Playlist
{
    return Playlist::factory()->for($user)->create([
        'xtream' => false,
        'xtream_config' => null,
        'enable_proxy' => true,
        'profiles_enabled' => true,
        'url' => 'http://provider.test:8080/get.php?username=puser&password=ppass&type=m3u_plus',
    ]);
}

it('creates the primary profile from the M3U URL only when the form is saved', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);

    // Saving the model alone (e.g. via the listener) must not auto-create one
    expect($playlist->profiles()->count())->toBe(0);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm(['user_agent' => 'Test Agent'])
        ->call('save')
        ->assertHasNoFormErrors();

    $primary = $playlist->profiles()->where('is_primary', true)->first();

    expect($primary)->not->toBeNull()
        ->and($primary->username)->toBe('puser')
        ->and($primary->password)->toBe('ppass')
        ->and($primary->url)->toBe('http://provider.test:8080')
        ->and($primary->max_streams)->toBe(1);
});

it('saves the primary profile stream limit from the edit form', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldExists('primary_profile_max_streams')
        ->assertFormSet(['primary_profile_max_streams' => 1])
        ->fillForm(['user_agent' => 'Test Agent', 'primary_profile_max_streams' => 4])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($playlist->profiles()->where('is_primary', true)->first()->max_streams)->toBe(4);
});

it('previews the primary account parsed from the M3U URL before it exists', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertSee('Username: puser (Profile will be created when saved)');
});

it('shows the saved primary account', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);
    ProfileService::ensureM3uPrimaryProfile($playlist, 3);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertSee('Username: puser | Max Streams: 3');
});

it('does not create a primary profile when the form is saved with profiles disabled', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm(['user_agent' => 'Test Agent', 'profiles_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($playlist->profiles()->count())->toBe(0);
});

it('adds an additional profile from a pasted M3U URL and normalises it', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'additional_profiles' => [
                [
                    'name' => 'Backup',
                    'url' => 'http://backup.test:9000/get.php?username=bu&password=bp&type=m3u_plus',
                    'username' => null,
                    'password' => null,
                    'max_streams' => 2,
                    'priority' => 1,
                    'enabled' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $backup = $playlist->profiles()->where('is_primary', false)->first();

    expect($backup)->not->toBeNull()
        ->and($backup->name)->toBe('Backup')
        ->and($backup->url)->toBe('http://backup.test:9000')
        ->and($backup->username)->toBe('bu')
        ->and($backup->password)->toBe('bp')
        ->and($backup->max_streams)->toBe(2);

    // No provider API exists for M3U accounts, so nothing was tested over HTTP.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'player_api.php'));
});

it('still requires a username for additional profiles on an Xtream playlist', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'xu', 'password' => 'xp', 'output' => 'ts'],
        'enable_proxy' => true,
        'profiles_enabled' => false,
    ]);
    PlaylistProfile::factory()->forPlaylist($playlist)->primary()->create();
    $playlist->update(['profiles_enabled' => true]);

    // A pasted M3U URL only stands in for the credentials on M3U playlists
    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'additional_profiles' => [
                [
                    'url' => 'http://backup.test/get.php?username=bu&password=bp',
                    'username' => null,
                    'password' => null,
                    'max_streams' => 1,
                    'priority' => 1,
                    'enabled' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($playlist->profiles()->where('is_primary', false)->count())->toBe(0);
});

it('requires a username on an M3U playlist when the URL carries no credentials', function () {
    $playlist = m3uProfilesFormPlaylist($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'additional_profiles' => [
                [
                    'url' => 'http://backup.test:9000',
                    'username' => null,
                    'password' => null,
                    'max_streams' => 1,
                    'priority' => 1,
                    'enabled' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($playlist->profiles()->where('is_primary', false)->count())->toBe(0);
});

it('does not touch the primary profile limit of an Xtream playlist', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'xu', 'password' => 'xp'],
        'enable_proxy' => true,
        'profiles_enabled' => false,
    ]);
    $primary = PlaylistProfile::factory()->forPlaylist($playlist)->primary()->withMaxStreams(5)->create();
    $playlist->update(['profiles_enabled' => true]);

    // The field is hidden for Xtream playlists, so poke the state directly
    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm(['user_agent' => 'Test Agent', 'xtream_config.output' => 'ts'])
        ->set('data.primary_profile_max_streams', 2)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($primary->fresh()->max_streams)->toBe(5);
});

it('loads a saved additional profile password into the edit form so it can be revealed', function (bool $xtream) {
    $playlist = $xtream
        ? Playlist::factory()->for($this->user)->create([
            'xtream' => true,
            'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'xu', 'password' => 'xp', 'output' => 'ts'],
            'enable_proxy' => true,
            'profiles_enabled' => true,
        ])
        : m3uProfilesFormPlaylist($this->user);
    PlaylistProfile::factory()->forPlaylist($playlist)->primary()->create();
    PlaylistProfile::factory()->forPlaylist($playlist)->create(['name' => 'Backup', 'password' => 'backup-secret']);

    // The model hides `password` from attributesToArray(), which the repeater fills from
    $profiles = Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->get('data.additional_profiles');

    expect(collect($profiles)->firstWhere('name', 'Backup')['password'] ?? null)->toBe('backup-secret');
})->with(['Xtream playlist' => [true], 'M3U playlist' => [false]]);
