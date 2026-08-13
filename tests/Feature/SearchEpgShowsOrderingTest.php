<?php

/**
 * Regression coverage for #1411 — `search_epg_shows` `recent_episodes` ordering
 * and cap. The TV client's show-detail screen puts a per-episode "Record" button
 * directly on each `recent_episodes` row (m3u-tv#204), so the list is the picker
 * for which upcoming airing to schedule a recording on.
 *
 * Locks in:
 *   1. Upcoming airings appear first, soonest-first — the next actionable airing
 *      is never pushed off the end by a deep schedule.
 *   2. Past episodes appear as a tail (most-recent-past first), not interleaved
 *      with upcoming ones by raw timestamp.
 *   3. The cap is MAX_RECENT_EPISODES (40), and the *soonest* 40 are returned,
 *      not the 40 farthest in the future — which is what the old single-pass
 *      descending-by-timestamp usort would have produced.
 *   4. A show with only past episodes still returns them most-recent-past first
 *      (existing behavior, shouldn't regress).
 */

use App\Models\Channel;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::create([
        'name' => 'Credential A',
        'username' => 'credential-a',
        'password' => 'password-a',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach([$this->auth->id]);

    $this->group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for($this->group)
        ->create(['enabled' => true, 'title_custom' => 'News 24']);

    $this->dvrSetting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();

    $this->epg = Epg::factory()->for($this->user)->create();
    $this->epgChannel = EpgChannel::factory()->create([
        'epg_id' => $this->epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'news24.example.com',
        'name' => 'News 24',
        'display_name' => 'News 24',
        'lang' => 'en',
    ]);
    $this->channel->update(['epg_channel_id' => $this->epgChannel->id]);

    // search_epg_shows itself doesn't talk to the proxy today, but bind a no-op
    // mock so any future controller dependency that does won't blow up the test
    // — same defensive pattern as XtreamDvrOwnershipTest.
    $proxy = Mockery::mock(M3uProxyService::class);
    app()->instance(M3uProxyService::class, $proxy);

    $this->makeProgramme = function (string $title, Carbon $startTime): EpgProgramme {
        return EpgProgramme::factory()->create([
            'epg_id' => $this->epg->id,
            'epg_channel_id' => $this->epgChannel->channel_id,
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $startTime->copy()->addHour(),
        ]);
    };
});

function searchShowsUrl(string $username, string $password, string $q): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'search_epg_shows',
        'q' => $q,
    ]);
}

it('returns upcoming episodes in soonest-first order', function () {
    $title = 'Evening News';

    $day1 = now()->addDay();
    $day3 = now()->addDays(3);
    $day7 = now()->addDays(7);

    // Insert in reverse order to prove the sort is doing the work, not the DB.
    ($this->makeProgramme)($title, $day7);
    ($this->makeProgramme)($title, $day3);
    ($this->makeProgramme)($title, $day1);

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'news'))
        ->assertOk();

    $episodes = $response->json('0.recent_episodes');
    expect($episodes)->toHaveCount(3);
    expect($episodes[0]['start_time'])->toBe($day1->toIso8601String());
    expect($episodes[1]['start_time'])->toBe($day3->toIso8601String());
    expect($episodes[2]['start_time'])->toBe($day7->toIso8601String());
});

it('puts past episodes after upcoming ones, not interleaved by timestamp', function () {
    $title = 'Morning Show';

    $upcoming = now()->addHours(2);
    $past = now()->subHours(2); // within the 24h lookback window

    ($this->makeProgramme)($title, $upcoming);
    ($this->makeProgramme)($title, $past);

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'morning'))
        ->assertOk();

    $episodes = $response->json('0.recent_episodes');
    expect($episodes)->toHaveCount(2);
    expect($episodes[0]['start_time'])->toBe($upcoming->toIso8601String());
    expect($episodes[1]['start_time'])->toBe($past->toIso8601String());
});

it('caps recent_episodes at MAX_RECENT_EPISODES and returns the soonest ones, not the farthest', function () {
    $title = 'Daily Show';

    // 45 future episodes at +1..+45 days. Under the old single descending sort,
    // the cap would have returned the farthest 40 (+6..+45 days), which is the
    // exact bug being fixed. Under the new upcoming-first sort, the soonest 40
    // (+1..+40 days) come back.
    for ($i = 1; $i <= 45; $i++) {
        ($this->makeProgramme)($title, now()->addDays($i));
    }

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'daily'))
        ->assertOk();

    $episodes = $response->json('0.recent_episodes');
    expect($episodes)->toHaveCount(40);

    // Soonest first.
    expect($episodes[0]['start_time'])->toBe(now()->addDay()->toIso8601String());
    expect($episodes[39]['start_time'])->toBe(now()->addDays(40)->toIso8601String());

    // Defensive — assert the OLD bug behavior would have produced a different
    // last entry, so a regression to the old sort trips this assertion.
    expect($episodes[39]['start_time'])->not->toBe(now()->addDays(45)->toIso8601String());
});

it('falls back to most-recent-past when a show has no upcoming episodes', function () {
    $title = 'Old Show';

    $mostRecent = now()->subHours(2);
    $older = now()->subHours(20);

    ($this->makeProgramme)($title, $older);
    ($this->makeProgramme)($title, $mostRecent);

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'old'))
        ->assertOk();

    $episodes = $response->json('0.recent_episodes');
    expect($episodes)->toHaveCount(2);

    // Both past, most-recent-past first.
    expect($episodes[0]['start_time'])->toBe($mostRecent->toIso8601String());
    expect($episodes[1]['start_time'])->toBe($older->toIso8601String());
});
