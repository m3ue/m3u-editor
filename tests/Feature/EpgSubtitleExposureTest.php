<?php

use App\Models\Channel;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for #1409 — XMLTV `<sub-title>` (the episode name,
 * distinct from the show title) is parsed into `epg_programmes.subtitle`
 * during ingestion but never surfaced to Xtream API clients. This test
 * pins the field on every endpoint that lists programmes so a future
 * refactor of `XtreamApiController` can't silently drop it again.
 *
 * Per-endpoint encoding is intentional and MUST match what each endpoint
 * already does for `title` / `description`:
 *
 *   - `search_epg_shows` → plain string (Eloquent source)
 *   - `get_short_epg`    → plain string (programme-cache source)
 *   - `get_epg_batch`    → base64-encoded (programme-cache source, encoded to
 *                           match the other batch fields)
 *
 * Don't "fix" one endpoint to match another — they have always been
 * inconsistent and downstream clients depend on each shape.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'xtream_user_'.uniqid();
    $this->password = 'xtream_pass';

    $this->auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);

    $this->playlist->playlistAuths()->attach($this->auth->id);
});

function xtreamApiUrl(string $username, string $password, string $action, array $params = []): string
{
    return route('xtream.api.player').'?'.http_build_query(array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params));
}

it('exposes the EPG programme subtitle on search_epg_shows recent_episodes (plain)', function () {
    // searchEpgShows uses `where('title', 'ilike', ...)` which is Postgres-only.
    // The test env (phpunit.xml) uses sqlite_testing, so this exercise of the
    // query path is gated to non-sqlite drivers. CI runs against Postgres.
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('searchEpgShows uses Postgres-specific `ilike`; skip on sqlite.');
    }

    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();

    $epg = Epg::factory()->for($this->user)->create();
    $epgChannel = EpgChannel::factory()
        ->for($this->user)
        ->for($epg)
        ->create(['channel_id' => 'channel.test']);

    Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->create([
            'enabled' => true,
            'epg_channel_id' => $epgChannel->id,
            'group_id' => null,
        ]);

    $start = Carbon::now()->addHour();
    $end = (clone $start)->addHour();

    EpgProgramme::factory()->create([
        'epg_channel_id' => 'channel.test',
        'title' => 'The Bear',
        'subtitle' => 'Groundhog Day',
        'start_time' => $start,
        'end_time' => $end,
    ]);

    $response = $this->postJson(xtreamApiUrl($this->username, $this->password, 'search_epg_shows', [
        'q' => 'The Bear',
    ]))->assertOk();

    $recentEpisodes = $response->json('0.recent_episodes');

    expect($recentEpisodes)->toBeArray()
        ->and($recentEpisodes)->not->toBeEmpty()
        ->and($recentEpisodes[0]['title'])->toBe('The Bear')
        ->and($recentEpisodes[0]['subtitle'])->toBe('Groundhog Day');
});

it('exposes the EPG programme subtitle on get_short_epg epg_listings (plain)', function () {
    $epg = Epg::factory()->for($this->user)->create([
        'is_cached' => true,
    ]);

    $epgChannel = EpgChannel::factory()
        ->for($this->user)
        ->for($epg)
        ->create(['channel_id' => 'channel.cache']);

    $channel = Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->create([
            'enabled' => true,
            'epg_channel_id' => $epgChannel->id,
            'group_id' => null,
        ]);

    $start = Carbon::now()->addMinutes(5);
    $end = Carbon::now()->addHour();

    seedEpgProgrammeCache($epg, [
        ['channel.cache', [
            'id' => 'prog-1',
            'title' => 'The Bear',
            'subtitle' => 'Groundhog Day',
            'desc' => 'A chef wrestles with his past.',
            'start' => $start->format('Y-m-d H:i:s'),
            'stop' => $end->format('Y-m-d H:i:s'),
            'lang' => 'en',
        ]],
    ]);

    $response = $this->getJson(xtreamApiUrl($this->username, $this->password, 'get_short_epg', [
        'stream_id' => (string) $channel->id,
    ]))->assertOk();

    expect($response->json('epg_listings.0.title'))->toBe('The Bear')
        ->and($response->json('epg_listings.0.subtitle'))->toBe('Groundhog Day')
        ->and($response->json('epg_listings.0.description'))->toBe('A chef wrestles with his past.');
});

it('exposes the EPG programme subtitle on get_epg_batch epg_listings (base64-encoded)', function () {
    $epg = Epg::factory()->for($this->user)->create([
        'is_cached' => true,
    ]);

    $epgChannel = EpgChannel::factory()
        ->for($this->user)
        ->for($epg)
        ->create(['channel_id' => 'channel.batch']);

    $channel = Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->create([
            'enabled' => true,
            'epg_channel_id' => $epgChannel->id,
            'group_id' => null,
        ]);

    $start = Carbon::now()->addMinutes(5);
    $end = Carbon::now()->addHour();

    seedEpgProgrammeCache($epg, [
        ['channel.batch', [
            'id' => 'prog-2',
            'title' => 'The Bear',
            'subtitle' => 'Groundhog Day',
            'desc' => 'A chef wrestles with his past.',
            'start' => $start->format('Y-m-d H:i:s'),
            'stop' => $end->format('Y-m-d H:i:s'),
            'lang' => 'en',
        ]],
    ]);

    $response = $this->getJson(xtreamApiUrl($this->username, $this->password, 'get_epg_batch', [
        'stream_ids' => (string) $channel->id,
    ]))->assertOk();

    $listings = $response->json((string) $channel->id.'.epg_listings');

    expect($listings)->toBeArray()
        ->and($listings)->not->toBeEmpty()
        ->and($listings[0]['title'])->toBe(base64_encode('The Bear'))
        ->and($listings[0]['subtitle'])->toBe(base64_encode('Groundhog Day'))
        ->and($listings[0]['description'])->toBe(base64_encode('A chef wrestles with his past.'));
});
