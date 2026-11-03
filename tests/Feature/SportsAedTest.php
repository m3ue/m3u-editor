<?php

use App\Filament\Resources\AedProfiles\Pages\CreateAedProfile;
use App\Filament\Resources\AedProfiles\Pages\ListAedProfiles;
use App\Jobs\RefreshSportsAedProfile;
use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\AedExtractorService;
use App\Services\EpgCacheService;
use App\Services\Sports\SportsAedRefresher;
use App\Services\Sports\SportsTeamMatcher;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function sportsAedTeam(string $id, string $name, string $nickname): array
{
    return [
        'id' => $id,
        'displayName' => $name,
        'shortDisplayName' => $nickname,
        'name' => $nickname,
        'abbreviation' => strtoupper($id),
        'logo' => "https://a.espncdn.com/i/teamlogos/{$id}.png",
    ];
}

/**
 * Tonight's (Nov 3, 2026 US time) games, as ESPN lists them on its Nov 3 day.
 *
 * @return list<array<string, mixed>>
 */
function sportsAedScoreboardEvents(string $path, string $date): array
{
    if ($date !== '20261103') {
        return [];
    }
    $game = fn (string $id, string $start, array $home, array $away): array => [
        'id' => $id,
        'date' => $start,
        'name' => "{$away['displayName']} at {$home['displayName']}",
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED', 'state' => 'pre', 'shortDetail' => '7:00 PM']],
        'competitions' => [[
            'venue' => ['fullName' => 'Scotiabank Arena'],
            'competitors' => [
                ['homeAway' => 'home', 'team' => $home],
                ['homeAway' => 'away', 'team' => $away],
            ],
            'broadcasts' => [['names' => ['ESPN+']]],
        ]],
    ];

    return match (true) {
        str_contains($path, '/hockey/nhl/') => [
            $game('401', '2026-11-04T00:00Z', sportsAedTeam('tor', 'Toronto Maple Leafs', 'Maple Leafs'), sportsAedTeam('uta', 'Utah Mammoth', 'Mammoth')),
            $game('402', '2026-11-04T00:00Z', sportsAedTeam('mtl', 'Montreal Canadiens', 'Canadiens'), sportsAedTeam('wpg', 'Winnipeg Jets', 'Jets')),
        ],
        str_contains($path, '/basketball/womens-college-basketball/') => [
            $game('601', '2026-11-04T00:00Z', sportsAedTeam('356', 'Illinois Fighting Illini', 'Illinois'), sportsAedTeam('2582', 'Southern Jaguars', 'Southern')),
        ],
        str_contains($path, '/basketball/nba/') => [
            $game('501', '2026-11-04T00:30Z', sportsAedTeam('bkn', 'Brooklyn Nets', 'Nets'), sportsAedTeam('cha', 'Charlotte Hornets', 'Hornets')),
        ],
        default => [],
    };
}

function fakeSportsAedEspn(): void
{
    Http::fake(fn (HttpRequest $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/teams')
        ? Http::response(['sports' => [['leagues' => [['teams' => str_contains($request->url(), 'womens-college-basketball') ? [
            ['team' => [...sportsAedTeam('79', 'Southern Illinois Salukis', 'S Illinois'), 'location' => 'Southern Illinois']],
            ['team' => sportsAedTeam('356', 'Illinois Fighting Illini', 'Illinois')],
            ['team' => sportsAedTeam('2582', 'Southern Jaguars', 'Southern')],
        ] : []]]]]])
        : Http::response([
            'leagues' => [['logos' => [['href' => 'https://a.espncdn.com/i/teamlogos/leagues/500/league.png']]]],
            'events' => sportsAedScoreboardEvents(parse_url($request->url(), PHP_URL_PATH), (string) $request['dates']),
        ]));
}

function enableSportsAed(): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = true;
    $settings->save();
}

function sportsAedProfile(User $user, array $attributes = []): AedProfile
{
    return AedProfile::factory()->for($user)->sportsMatching(['hockey/nhl', 'basketball/nba'])->create([
        'no_event_format' => null,
        'title_regex' => '^NO REGEX MATCH$',
        ...$attributes,
    ]);
}

/**
 * What the background refresh does before EPG requests can match: fetch the
 * profile's games and team lists into the cache.
 */
function warmSportsAed(AedProfile $profile): void
{
    app(SportsAedRefresher::class)->refresh($profile);
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    // config/queue.php hard-codes Redis: an unfaked dispatch would land on
    // the dev queue
    Queue::fake();
    $this->travelTo(now()->parse('2026-11-03 18:00:00', 'UTC'));
});

it('normalizes titles and names a team only by whole words', function () {
    expect(SportsTeamMatcher::normalize('Atlético-Madrid: LIVE!'))->toBe(' atletico madrid live ');

    $nets = SportsTeamMatcher::phrasesFor(['display_name' => 'Brooklyn Nets', 'short_name' => 'Nets', 'nickname' => 'Nets']);
    expect(SportsTeamMatcher::mentions(SportsTeamMatcher::normalize('76ers @ Hornets'), $nets))->toBeFalse()
        ->and(SportsTeamMatcher::mentions(SportsTeamMatcher::normalize('NBA 03: Hornets @ Nets'), $nets))->toBeTrue();
});

it('never matches a team by its city alone, but knows common aliases', function () {
    $rangers = SportsTeamMatcher::phrasesFor(['display_name' => 'New York Rangers', 'short_name' => 'Rangers', 'nickname' => 'Rangers']);
    expect($rangers)->toContain('ny rangers')->not->toContain('new york')
        ->and(SportsTeamMatcher::mentions(' new york knicks at boston ', $rangers))->toBeFalse();
});

it('matches games in any provider title format and falls back for the rest', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    $profile = sportsAedProfile(User::factory()->create(), [
        'no_event_format' => '{channel}',
        'output_timezone' => 'America/New_York',
    ]);

    // An EPG request never waits on ESPN: cold, it matches nothing (falling
    // back to the no-event title) and queues the fetch for later requests
    expect(app(AedExtractorService::class)->resolve($profile, 'ESPN+ 53: Winnipeg Jets vs. Montreal Canadiens')->title)
        ->toBe('ESPN+ 53: Winnipeg Jets vs. Montreal Canadiens');
    Http::assertNothingSent();
    Queue::assertPushed(RefreshSportsAedProfile::class, fn (RefreshSportsAedProfile $job): bool => $job->profileId === $profile->id && ! $job->force);

    warmSportsAed($profile);
    $extractor = app(AedExtractorService::class);

    $espnPlus = $extractor->resolve($profile, 'ESPN+ 53: Winnipeg Jets vs. Montreal Canadiens @ Nov 03 7:00PM ET');
    expect($espnPlus->title)->toBe('Winnipeg Jets at Montreal Canadiens')
        ->and($espnPlus->start->format('Y-m-d H:i T'))->toBe('2026-11-03 19:00 EST')
        ->and($espnPlus->durationMinutes)->toBe(165)
        ->and($espnPlus->description)->toBe('NHL | Scotiabank Arena | ESPN+')
        ->and($espnPlus->icon)->toContain('/logo-proxy/')
        ->and($espnPlus->category)->toBe('Sports');

    $dazn = $extractor->resolve($profile, 'DAZN UK 25: Mammoth @ Maple Leafs @ 4 Nov 02:00 AM London');
    expect($dazn->title)->toBe('Utah Mammoth at Toronto Maple Leafs');

    // "Hornets" is not "Nets": no game, so the no-event fallback applies.
    $other = $extractor->resolve($profile, 'NBA LP 08 | 76ers @ Hornets');
    expect($other->title)->toBe('NBA LP 08 | 76ers @ Hornets')
        ->and($other->hasTime())->toBeFalse();

    $flo = $extractor->resolve($profile, '(FLSP 765) | football:  Davenport vs Michigan Tech');
    expect($flo->hasTime())->toBeFalse();
});

it('does not read a team inside a longer name of another team', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    $profile = sportsAedProfile(User::factory()->create(), ['sports_league_ids' => ['basketball/womens-college-basketball']]);
    warmSportsAed($profile);
    $extractor = app(AedExtractorService::class);

    // "Southern" (Jaguars) sits inside "Southern Illinois" (Salukis); the real
    // game here, against Illinois-Springfield, isn't on ESPN at all.
    expect($extractor->resolve($profile, 'NCAAW B 08: Illinois-Springfield vs. Southern Illinois @ 3 Nov 07:00 PM ET'))->toBeNull()
        ->and($extractor->resolve($profile, 'NCAAW 03: Southern vs. Illinois')->title)->toBe('Southern Jaguars at Illinois Fighting Illini');
});

it('ignores a stale title whose own date is far from the game', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    $profile = sportsAedProfile(User::factory()->create(), [
        'title_regex' => null,
        'time_regex' => '\((\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)',
        'time_format' => 'Y-m-d H:i:s',
        'source_timezone' => 'America/New_York',
    ]);
    warmSportsAed($profile);
    $extractor = app(AedExtractorService::class);

    $stale = $extractor->resolve($profile, 'NHL 07: Utah Mammoth vs. Toronto Maple Leafs (2026-10-27 19:00:00)');
    expect($stale->title)->toBe('NHL 07: Utah Mammoth vs. Toronto Maple Leafs (2026-10-27 19:00:00)')
        ->and($stale->start->toDateString())->toBe('2026-10-27');

    $tonight = $extractor->resolve($profile, 'NHL 07: Utah Mammoth vs. Toronto Maple Leafs (2026-11-03 19:00:00)');
    expect($tonight->title)->toBe('Utah Mammoth at Toronto Maple Leafs');
});

it('prefers the men\'s game for an unmarked title and the women\'s for a marked one', function () {
    enableSportsAed();
    $derby = fn (string $id, string $start): array => [
        'id' => $id,
        'date' => $start,
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED', 'state' => 'pre']],
        'competitions' => [['competitors' => [
            ['homeAway' => 'home', 'team' => sportsAedTeam('359', 'Arsenal', 'Arsenal')],
            ['homeAway' => 'away', 'team' => sportsAedTeam('363', 'Chelsea', 'Chelsea')],
        ]]],
    ];
    Http::fake(fn (HttpRequest $request) => Http::response(['events' => match (true) {
        (string) $request['dates'] !== '20261103', str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/teams') => [],
        str_contains($request->url(), '/soccer/eng.w.1/') => [$derby('w1', '2026-11-03T19:00Z')],
        default => [$derby('m1', '2026-11-03T20:00Z')],
    }]));
    $profile = sportsAedProfile(User::factory()->create(), ['sports_league_ids' => ['soccer/eng.1', 'soccer/eng.w.1']]);
    warmSportsAed($profile);
    $extractor = app(AedExtractorService::class);

    expect($extractor->resolve($profile, 'EPL 01: Arsenal vs Chelsea')->start->toIso8601ZuluString())->toBe('2026-11-03T20:00:00Z')
        ->and($extractor->resolve($profile, 'WSL 01: Arsenal Women vs Chelsea Women')->start->toIso8601ZuluString())->toBe('2026-11-03T19:00:00Z')
        ->and($extractor->resolve($profile, 'Arsenal W v Chelsea W')->start->toIso8601ZuluString())->toBe('2026-11-03T19:00:00Z');
});

it('leaves profiles alone while sports is turned off', function () {
    fakeSportsAedEspn();
    $profile = sportsAedProfile(User::factory()->create(), ['no_event_format' => '{channel}']);

    $event = app(AedExtractorService::class)->resolve($profile, 'NHL 07: Utah Mammoth vs. Toronto Maple Leafs');

    expect($event->hasTime())->toBeFalse();
    Http::assertNothingSent();
});

it('writes the matched game into the XMLTV and JSON EPG', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    Storage::disk('local')->deleteDirectory('playlist-epg-files');
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $profile = sportsAedProfile($user);
    warmSportsAed($profile);
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'stream_id' => 'nhl-07',
        'title' => 'NHL 07: Utah Mammoth vs. Toronto Maple Leafs @ 3 Nov 07:00 PM ET',
        'channel' => 7,
        'aed_profile_id' => $profile->id,
    ]);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz")->assertOk();
    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->streamedContent())))->toBeTrue();
    $xpath = new DOMXPath($document);
    $game = $xpath->query('//programme[@channel="nhl-07"][title="Utah Mammoth at Toronto Maple Leafs"]');
    expect($game)->toHaveCount(1)
        ->and($game->item(0)->getAttribute('start'))->toBe('20261104000000 +0000')
        ->and($xpath->query('icon', $game->item(0))->item(0)?->getAttribute('src'))->toContain('/logo-proxy/');

    $this->actingAs($user);
    $programmes = $this->getJson("/api/epg/playlist/{$playlist->uuid}/data")
        ->assertSuccessful()
        ->json("programmes.{$channel->id}");
    expect(collect($programmes)->firstWhere('title', 'Utah Mammoth at Toronto Maple Leafs'))
        ->not->toBeNull()
        ->toHaveKey('category', 'Sports');
});

it('serves the matched game over the mapped guide in the Xtream batch EPG the TV reads', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    Storage::fake('local');
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $auth = PlaylistAuth::create(['name' => 'TV', 'username' => 'sports-tv', 'password' => 'secret', 'enabled' => true, 'user_id' => $user->id]);
    $playlist->playlistAuths()->attach($auth->id);
    $profile = sportsAedProfile($user, ['override' => true]);
    warmSportsAed($profile);
    $epg = Epg::factory()->for($user)->create(['is_cached' => true]);
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'group_id' => null,
        'title' => 'ESPN+ 53: Utah Mammoth vs. Toronto Maple Leafs @ Nov 03 7:00PM ET',
        'epg_channel_id' => EpgChannel::factory()->for($user)->for($epg)->create(['channel_id' => 'Provider.ESPN+.053'])->id,
        'aed_profile_id' => $profile->id,
    ]);

    $listings = $this->getJson(route('xtream.api.player').'?'.http_build_query([
        'username' => 'sports-tv',
        'password' => 'secret',
        'action' => 'get_epg_batch',
        'stream_ids' => (string) $channel->id,
        'date' => '2026-11-03',
        'details' => 1,
    ]))->assertOk()->json("{$channel->id}.epg_listings");

    $game = collect($listings)->first(fn (array $listing): bool => base64_decode($listing['title']) === 'Utah Mammoth at Toronto Maple Leafs');
    expect($game)->not->toBeNull()
        ->and($game['start_timestamp'])->toBe((string) now()->parse('2026-11-04 00:00:00', 'UTC')->timestamp)
        ->and($game['category'])->toBe('Sports')
        ->and($game['icon'])->toContain('/logo-proxy/');
});

it('clears a profile\'s cached EPG only when its games change', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $profile = sportsAedProfile($user);
    Channel::factory()->for($user)->for($playlist)->create(['aed_profile_id' => $profile->id]);
    $cachePath = EpgCacheService::getPlaylistEpgCachePath($playlist, false);

    Storage::fake('local');
    Storage::disk('local')->put($cachePath, '<tv/>');
    $this->artisan('app:refresh-sports-aed-epg')->assertSuccessful();
    expect(Storage::disk('local')->exists($cachePath))->toBeFalse();

    Storage::disk('local')->put($cachePath, '<tv/>');
    $this->artisan('app:refresh-sports-aed-epg')->assertSuccessful();
    expect(Storage::disk('local')->exists($cachePath))->toBeTrue();
});

it('refreshes a profile\'s games from ESPN on demand, at most every couple of minutes', function () {
    enableSportsAed();
    fakeSportsAedEspn();
    $user = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->create();
    $profile = sportsAedProfile($user);
    $plain = AedProfile::factory()->for($user)->create();
    // The rate limiter is resolved at boot, before tests switch to the array
    // cache, so it keeps its hits in the real Redis; ids restart every run.
    $limiterKey = 'sports-aed-refresh:'.$profile->id;
    RateLimiter::clear($limiterKey);
    Channel::factory()->for($user)->for($playlist)->create(['aed_profile_id' => $profile->id]);
    $cachePath = EpgCacheService::getPlaylistEpgCachePath($playlist, false);
    Storage::fake('local');

    // Warm, with nothing changed since: the scheduled refresh keeps the file
    $this->artisan('app:refresh-sports-aed-epg')->assertSuccessful();
    Storage::disk('local')->put($cachePath, '<tv/>');
    $sent = count(Http::recorded());

    // Queued, not run while the page waits
    Livewire::test(ListAedProfiles::class)
        ->assertActionHidden(TestAction::make('refresh_games')->table($plain))
        ->callAction(TestAction::make('refresh_games')->table($profile))
        ->assertNotified('Refreshing games');
    expect(count(Http::recorded()))->toBe($sent);
    $job = Queue::pushed(RefreshSportsAedProfile::class)->sole();
    expect($job->profileId)->toBe($profile->id)->and($job->force)->toBeTrue();

    // The job refetches from ESPN and clears the guide whatever changed
    app()->call([$job, 'handle']);
    expect(Storage::disk('local')->exists($cachePath))->toBeFalse()
        ->and(count(Http::recorded()))->toBeGreaterThan($sent);

    Livewire::test(ListAedProfiles::class)
        ->callAction(TestAction::make('refresh_games')->table($profile))
        ->assertNotified('Games were just refreshed');
    expect(Queue::pushed(RefreshSportsAedProfile::class))->toHaveCount(1);

    RateLimiter::clear($limiterKey);
});

it('saves the sports fields from the AED profile form', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    Livewire::test(CreateAedProfile::class)
        ->assertSee('Define regex patterns to extract event data')
        ->fillForm([
            'name' => 'Event channels',
            'sports_matching' => true,
            'sports_league_ids' => ['hockey/nhl'],
            'sports_title_format' => '{away} @ {home}',
            'sports_event_duration_minutes' => 180,
        ])
        ->assertSee(e("Used for channels that don't match a game."), escape: false)
        ->assertDontSee('Define regex patterns to extract event data')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AedProfile::where('name', 'Event channels')->first())
        ->sports_matching->toBeTrue()
        ->sports_league_ids->toBe(['hockey/nhl'])
        ->sports_title_format->toBe('{away} @ {home}')
        ->sports_event_duration_minutes->toBe(180);
});
