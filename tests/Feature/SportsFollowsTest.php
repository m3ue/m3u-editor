<?php

use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerSportsFollow;
use App\Services\EspnSportsService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function sportsTeamsResponse(): array
{
    return ['sports' => [['leagues' => [['teams' => [
        ['team' => ['id' => '21', 'displayName' => 'Toronto Maple Leafs', 'shortDisplayName' => 'Maple Leafs', 'name' => 'Maple Leafs', 'abbreviation' => 'TOR', 'color' => '003E7E', 'logos' => [['href' => 'https://a.espncdn.com/i/teamlogos/nhl/500/tor.png']]]],
        ['team' => ['id' => '1', 'displayName' => 'Anaheim Ducks', 'shortDisplayName' => 'Ducks', 'name' => 'Ducks', 'abbreviation' => 'ANA', 'logos' => [['href' => 'https://a.espncdn.com/i/teamlogos/nhl/500/ana.png']]]],
    ]]]]]];
}

function sportsSettings(bool $enabled): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = $enabled;
    $settings->save();
}

function ownerSports(array $body): TestResponse
{
    return test()->postJson(route('xtream.api.player'), [
        'username' => test()->user->name,
        'password' => test()->playlist->uuid,
        ...$body,
    ]);
}

function childSports(array $body): TestResponse
{
    return test()->postJson(route('xtream.api.player'), [
        'username' => 'kiduser',
        'password' => 'kidpass',
        ...$body,
    ]);
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['*/hockey/nhl/teams*' => Http::response(sportsTeamsResponse())]);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    // The owner's admin viewer is created by the Playlist::created observer.
    $this->viewer = PlaylistViewer::where('viewerable_type', $this->playlist->getMorphClass())
        ->where('viewerable_id', $this->playlist->id)
        ->where('is_admin', true)
        ->firstOrFail();

    $this->childAuth = PlaylistAuth::create([
        'name' => 'Kid',
        'username' => 'kiduser',
        'password' => 'kidpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->childAuth);
    $this->childViewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'kid',
        'is_admin' => false,
        'playlist_auth_id' => $this->childAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);
});

it('parses ESPN teams alphabetically', function () {
    $teams = EspnSportsService::parseTeams(sportsTeamsResponse());

    expect(array_column($teams, 'display_name'))->toBe(['Anaheim Ducks', 'Toronto Maple Leafs'])
        ->and($teams[1])->toMatchArray(['id' => '21', 'nickname' => 'Maple Leafs', 'color' => '003e7e']);
});

it('refuses every sports action while sports is disabled', function (string $action) {
    sportsSettings(false);

    ownerSports(['action' => $action, 'league' => 'hockey/nhl'])->assertForbidden();
    Http::assertNothingSent();
})->with(['get_sports_teams', 'get_sports_follows', 'toggle_sports_follow', 'sync_sports_follows']);

it('lists a league\'s teams with proxied crests, cached', function () {
    sportsSettings(true);

    $teams = ownerSports(['action' => 'get_sports_teams', 'league' => 'hockey/nhl'])
        ->assertOk()
        ->json('teams');

    expect($teams)->toHaveCount(2)
        ->and($teams[1]['display_name'])->toBe('Toronto Maple Leafs')
        ->and($teams[1]['logo'])->toContain('/logo-proxy/')->not->toContain('espncdn');

    ownerSports(['action' => 'get_sports_teams', 'league' => 'hockey/nhl'])->assertOk();
    Http::assertSentCount(1);

    ownerSports(['action' => 'get_sports_teams', 'league' => 'not/a-league'])->assertStatus(422);
});

it('follows and unfollows a team idempotently, with the crest re-resolved', function () {
    sportsSettings(true);
    $follow = ['action' => 'toggle_sports_follow', 'viewer_id' => $this->viewer->ulid, 'team_key' => 'hockey/nhl:21', 'name' => 'Toronto Maple Leafs'];

    ownerSports($follow)->assertOk();
    $follows = ownerSports($follow)->assertOk()->json('follows');

    expect($follows)->toHaveCount(1)
        ->and($follows[0])->toMatchArray(['team_key' => 'hockey/nhl:21', 'league_id' => 'hockey/nhl', 'team_id' => '21', 'name' => 'Toronto Maple Leafs'])
        ->and($follows[0]['logo'])->toContain('/logo-proxy/');

    $after = ownerSports([...$follow, 'followed' => false])->assertOk()->json('follows');
    expect($after)->toBe([])
        ->and(ViewerSportsFollow::count())->toBe(0);
});

it('rejects follow keys for unknown leagues or malformed keys', function (string $teamKey) {
    sportsSettings(true);

    ownerSports(['action' => 'toggle_sports_follow', 'team_key' => $teamKey, 'name' => 'Someone'])->assertStatus(422);
})->with(['hockey/nhl', 'curling/world:1', 'hockey/nhl:21; drop']);

it('keeps each viewer\'s follows apart, even when another viewer\'s id is sent', function () {
    sportsSettings(true);

    childSports([
        'action' => 'toggle_sports_follow',
        // A foreign viewer id falls back to the auth's own viewer.
        'viewer_id' => $this->viewer->ulid,
        'team_key' => 'hockey/nhl:1',
        'name' => 'Anaheim Ducks',
    ])->assertOk();

    expect($this->childViewer->sportsFollows()->pluck('team_key')->all())->toBe(['hockey/nhl:1'])
        ->and($this->viewer->sportsFollows()->count())->toBe(0);
});

it('unions synced follows into the account without removing any', function () {
    sportsSettings(true);
    ViewerSportsFollow::factory()->for($this->viewer, 'viewer')->create([
        'team_key' => 'hockey/nhl:21',
        'team_id' => '21',
        'name' => 'Toronto Maple Leafs',
    ]);

    $follows = ownerSports([
        'action' => 'sync_sports_follows',
        'viewer_id' => $this->viewer->ulid,
        'follows' => [
            ['team_key' => 'hockey/nhl:1', 'name' => 'Anaheim Ducks'],
            ['team_key' => 'hockey/nhl:21', 'name' => 'Toronto Maple Leafs'],
            ['team_key' => 'bogus', 'name' => 'Skipped'],
        ],
    ])->assertOk()->json('follows');

    expect(array_column($follows, 'team_key'))->toBe(['hockey/nhl:21', 'hockey/nhl:1']);
});

it('drops a viewer\'s follows with the viewer', function () {
    ViewerSportsFollow::factory()->for($this->childViewer, 'viewer')->create();

    $this->childViewer->delete();

    expect(ViewerSportsFollow::count())->toBe(0);
});
