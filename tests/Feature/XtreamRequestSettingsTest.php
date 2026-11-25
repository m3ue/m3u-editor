<?php

use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Jobs\UpdateXtreamStats;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\ProfileService;
use App\Services\XtreamHealthService;
use App\Services\XtreamService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

const REQUEST_SETTINGS_CREDENTIALS = [
    'url' => 'http://provider.example.com:8080',
    'username' => 'user',
    'password' => 'pass',
];

beforeEach(function () {
    Queue::fake();
    config(['cache.default' => 'array']);
    $this->user = User::factory()->admin()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'xtream' => true,
        'xtream_config' => REQUEST_SETTINGS_CREDENTIALS,
        'user_agent' => 'PlaylistAgent/1.0',
        'disable_ssl_verification' => true,
    ]);

    $this->sslOptions = new ArrayObject;
    $sslOptions = $this->sslOptions;
    Http::fake(function ($request, array $options) use ($sslOptions) {
        $sslOptions[] = $options['verify'] ?? null;

        return Http::response(['user_info' => ['auth' => 1, 'status' => 'Active', 'max_connections' => 2, 'active_cons' => 0]]);
    });
});

function requestSettingsAssertSent(string $userAgent): void
{
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->header('User-Agent') === [$userAgent]);
}

function requestSettingsAlias(Playlist $playlist, array $entry): PlaylistAlias
{
    return PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Alias',
        'uuid' => Str::uuid()->toString(),
        'user_agent' => 'AliasAgent/1.0',
        'xtream_config' => [$entry],
    ]);
}

it('uses explicit request settings and falls back for empty user agents', function () {
    XtreamService::make(xtream_config: REQUEST_SETTINGS_CREDENTIALS)->withRequestSettings('Custom/1.0', true)->userInfo();
    XtreamService::make(xtream_config: REQUEST_SETTINGS_CREDENTIALS)->withRequestSettings('')->userInfo();
    XtreamService::make($this->playlist)->userInfo();

    $agents = Http::recorded()->map(fn ($pair) => $pair[0]->header('User-Agent')[0])->all();

    expect($agents)->toBe(['Custom/1.0', XtreamService::DEFAULT_USER_AGENT, 'PlaylistAgent/1.0'])
        ->and($this->sslOptions->getArrayCopy())->toBe([false, true, false]);
});

it('sends the playlist user agent in health checks', function () {
    XtreamHealthService::findWorkingUrl($this->playlist);
    XtreamHealthService::checkAllUrls($this->playlist);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->header('User-Agent') !== ['PlaylistAgent/1.0']);
});

it('sends the default user agent in health checks without one', function () {
    XtreamHealthService::checkUrl('http://provider.example.com:8080', 'user', 'pass');

    requestSettingsAssertSent(XtreamService::DEFAULT_USER_AGENT);
});

it('sends the playlist settings when testing credentials', function () {
    ProfileService::testCredentials(REQUEST_SETTINGS_CREDENTIALS, 'PlaylistAgent/1.0', true);

    requestSettingsAssertSent('PlaylistAgent/1.0');
    expect($this->sslOptions->getArrayCopy())->toBe([false]);
});

it('sends the playlist settings when updating stats', function () {
    (new UpdateXtreamStats($this->playlist))->handle();

    requestSettingsAssertSent('PlaylistAgent/1.0');
    expect($this->sslOptions->getArrayCopy())->toBe([false]);
});

it('uses the user agent of the account an alias queries', function (array $entry, string $expected) {
    $alias = requestSettingsAlias($this->playlist, $entry);

    (new UpdateXtreamStats($alias))->handle();

    requestSettingsAssertSent($expected);
    expect($this->sslOptions->getArrayCopy())->toBe([false]);
})->with([
    'own credentials' => [REQUEST_SETTINGS_CREDENTIALS, 'AliasAgent/1.0'],
    'source credentials' => [['url' => 'http://provider.example.com:8080'], 'PlaylistAgent/1.0'],
]);

it('tests the connection with the unsaved form settings', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm(['user_agent' => 'FormAgent/1.0', 'disable_ssl_verification' => false])
        ->callAction(TestAction::make('test_xtream_connection')->schemaComponent('xtream_config.url'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'player_api.php')
        && $request->header('User-Agent') === ['FormAgent/1.0']);
    expect(Arr::last($this->sslOptions->getArrayCopy()))->toBeTrue();
});

it('tests a fallback URL with the form settings', function () {
    $this->actingAs($this->user);
    $this->playlist->updateQuietly(['xtream_fallback_urls' => ['http://fallback.example.com:8080']]);

    $page = Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm(['user_agent' => 'FormAgent/1.0']);
    $item = array_key_first($page->get('data.xtream_fallback_urls'));
    $page->callAction(TestAction::make('test_dns_url')->schemaComponent('xtream_fallback_urls')->arguments(['item' => $item]));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://fallback.example.com:8080/')
        && $request->header('User-Agent') === ['FormAgent/1.0']);
});

it('tests an alias connection with the alias form settings', function () {
    $this->actingAs($this->user);
    $alias = requestSettingsAlias($this->playlist, REQUEST_SETTINGS_CREDENTIALS);

    $page = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->id])
        ->fillForm(['user_agent' => 'FormAgent/1.0']);
    $item = array_key_first($page->get('data.xtream_config'));
    $page->callAction(TestAction::make('test_xtream_connection')->schemaComponent("xtream_config.{$item}.url"));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'player_api.php')
        && $request->header('User-Agent') === ['FormAgent/1.0']);
    expect(Arr::last($this->sslOptions->getArrayCopy()))->toBeFalse();
});

it('tests a saved profile with its playlist settings', function () {
    $profile = PlaylistProfile::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Backup',
        'username' => 'backup',
        'password' => 'secret',
        'max_streams' => 1,
    ]);

    ProfileService::verifyCredentials($profile);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'username=backup')
        && $request->header('User-Agent') === ['PlaylistAgent/1.0']);
    expect(Arr::last($this->sslOptions->getArrayCopy()))->toBeFalse();
});
