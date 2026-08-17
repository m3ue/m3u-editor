<?php

/**
 * Regression coverage for #1411 - `search_epg_shows` `recent_episodes` ordering
 * and cap, plus the `airing_now` field added in the follow-up round. The TV
 * client's show-detail screen puts a per-episode "Record" button directly on each
 * `recent_episodes` row (m3u-tv#204), and the "On Now" section (m3u-tv#212) reads
 * `airing_now`. Both lists are derived from the same programme group, so this
 * file covers both.
 *
 * Locks in:
 *   1. Upcoming airings appear first, soonest-first - the next actionable airing
 *      is never pushed off the end by a deep schedule.
 *   2. Past episodes appear as a tail (most-recent-past first), not interleaved
 *      with upcoming ones by raw timestamp.
 *   3. The cap is MAX_RECENT_EPISODES (40), and the *soonest* 40 are returned,
 *      not the 40 farthest in the future (which is what the old single-pass
 *      descending-by-timestamp usort would have produced).
 *   4. A show with only past episodes still returns them most-recent-past first
 *      (existing behavior, shouldn't regress).
 *   5. `airing_now` holds exactly the programmes currently in progress
 *      (start_time <= now && end_time > now), excluding null end_time. Entry
 *      shape matches `recent_episodes[]` so the TV client can parse with the
 *      same factory. Always an array, never null.
 *   6. The naive `$past[0]` implementation is wrong: the most-recently-started
 *      programme may have already ended while an earlier, longer one is still
 *      running. `airing_now` must filter $past, not take its head.
 *   7. `next_airing_at` and `recent_episodes` ordering stay correct after the
 *      `airing_now` addition (regression guard on the merged #1411 behaviour).
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
    // (same defensive pattern as XtreamDvrOwnershipTest)
    $proxy = Mockery::mock(M3uProxyService::class);
    app()->instance(M3uProxyService::class, $proxy);

    $this->makeProgramme = function (string $title, Carbon $startTime, ?Carbon $endTime = null): EpgProgramme {
        return EpgProgramme::factory()->create([
            'epg_id' => $this->epg->id,
            'epg_channel_id' => $this->epgChannel->channel_id,
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $endTime ?? $startTime->copy()->addHour(),
        ]);
    };

    // Build a second Channel <-> EpgChannel pair so tests can exercise a show
    // airing simultaneously on multiple channels (regional variants, +1
    // timeshifts), exactly the case `airing_now` is built to expose.
    $this->makeChannelPair = function (string $channelId, string $name) {
        $epgChannel = EpgChannel::factory()->create([
            'epg_id' => $this->epg->id,
            'user_id' => $this->user->id,
            'channel_id' => $channelId,
            'name' => $name,
            'display_name' => $name,
            'lang' => 'en',
        ]);
        // The controller resolves channel_name via `$ch->title ?: ($ch->name ?? '')`,
        // so set `title` (not `title_custom`) to make the value deterministic and
        // assertable. Factory defaults leave `title` null.
        $channel = Channel::factory()
            ->for($this->playlist)
            ->for($this->group)
            ->create(['enabled' => true, 'title' => $name]);
        $channel->update(['epg_channel_id' => $epgChannel->id]);

        return ['epg_channel_id' => $channelId, 'channel_id' => $channel->id, 'name' => $name];
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

    // Defensive: assert the OLD bug behavior would have produced a different
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

it('returns airing programmes in airing_now with the same entry shape as recent_episodes', function () {
    $title = 'Tonight Live';
    $now = now();

    // Started 10 minutes ago, runs for an hour from now.
    ($this->makeProgramme)($title, $now->copy()->subMinutes(10), $now->copy()->addMinutes(50));

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'tonight'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toHaveCount(1);

    // Entry shape must match recent_episodes entries - load one of each and
    // assert the key sets are equal. `subtitle` is the shared-shape guard
    // (added by #1409); without it the TV client can't parse `airing_now[]`
    // with its existing `EpgShowEpisode.fromXtream` factory.
    $recentKeys = array_keys($response->json('0.recent_episodes.0'));
    $airingKeys = array_keys($airingNow[0]);
    expect($airingKeys)->toEqual($recentKeys);
    expect($airingKeys)->toContain('subtitle');
});

it('emits an empty airing_now array when nothing is in progress, never null or absent', function () {
    $title = 'Future Show';

    ($this->makeProgramme)($title, now()->addDays(2)); // future only

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'future'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toBe([]);
    expect($airingNow)->not->toBeNull();

    // Key must be present on the result object even when empty.
    expect($response->json('0'))->toHaveKey('airing_now');
});

it('lists every channel airing the same show in airing_now with distinct channel_id/name', function () {
    $title = 'Multi Feed';

    $east = ($this->makeChannelPair)('east.example.com', 'East Feed');
    $west = ($this->makeChannelPair)('west.example.com', 'West Feed');

    $now = now();
    EpgProgramme::factory()->create([
        'epg_id' => $this->epg->id,
        'epg_channel_id' => $east['epg_channel_id'],
        'title' => $title,
        'start_time' => $now->copy()->subMinutes(5),
        'end_time' => $now->copy()->addHour(),
    ]);
    EpgProgramme::factory()->create([
        'epg_id' => $this->epg->id,
        'epg_channel_id' => $west['epg_channel_id'],
        'title' => $title,
        'start_time' => $now->copy()->subMinutes(5),
        'end_time' => $now->copy()->addHour(),
    ]);

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'multi'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toHaveCount(2);

    $channelIds = collect($airingNow)->pluck('channel_id')->all();
    expect($channelIds)->toContain($east['channel_id']);
    expect($channelIds)->toContain($west['channel_id']);

    // Each entry must carry its own channel_name too (used by the TV client
    // to label the row).
    $channelNames = collect($airingNow)->pluck('channel_name')->all();
    expect($channelNames)->toContain('East Feed');
    expect($channelNames)->toContain('West Feed');
});

it('excludes past programmes with null end_time from airing_now', function () {
    $title = 'Noend Show';

    $now = now();
    // In-progress-looking (start in past) but end_time is unknown - the
    // controller can't confirm progress, so it must exclude this.
    EpgProgramme::factory()->create([
        'epg_id' => $this->epg->id,
        'epg_channel_id' => $this->epgChannel->channel_id,
        'title' => $title,
        'start_time' => $now->copy()->subMinutes(10),
        'end_time' => null,
    ]);

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'noend'))
        ->assertOk();

    expect($response->json('0.airing_now'))->toBe([]);
});

it('excludes already-ended programmes even when most-recently-started, not just the naive $past[0] failure mode', function () {
    $title = 'Tricky Show';

    $now = now();

    // Programme 1: started earliest (-90m), still running (ends in +30m).
    // Programme 2: started most recently (-5m) but already ended (-1m ago).
    // A naive `$past[0]` implementation would return programme 2 (most-recent
    // start, which is at the head of $past). The correct filter rejects
    // programme 2 because its end_time is in the past, and returns only
    // programme 1.
    ($this->makeProgramme)($title, $now->copy()->subMinutes(90), $now->copy()->addMinutes(30));
    ($this->makeProgramme)($title, $now->copy()->subMinutes(5), $now->copy()->subMinutes(1));

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'tricky'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toHaveCount(1);

    // The single in-progress programme is the long one (-90m start), not the
    // most-recent-start (-5m) one which has ended.
    expect($airingNow[0]['start_time'])->toBe($now->copy()->subMinutes(90)->toIso8601String());
});

it('treats end_time strictly: end_time equal to now is NOT airing; start_time equal to now IS airing', function () {
    // Freeze the test clock so the controller's `Carbon::now()` returns the
    // same timestamp the test uses, making strict `>` boundary assertions
    // deterministic without flakiness from test-vs-controller clock skew.
    Carbon::setTestNow('2026-08-13 12:00:00');

    $title = 'Boundary Show';
    $now = now();

    // Programme A: end_time exactly = now. Condition is `end_time > now`, so
    // this is NOT airing (already ended at the moment of "now").
    // Start one hour before to ensure it lands in $past.
    ($this->makeProgramme)($title, $now->copy()->subHour(), $now->copy());

    // Programme B: start_time exactly = now. That's not in the future, so it
    // goes to $past, and with an end_time > now it's in progress.
    ($this->makeProgramme)($title, $now, $now->copy()->addHour());

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'boundary'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toHaveCount(1);
    expect($airingNow[0]['start_time'])->toBe($now->toIso8601String());
    expect($airingNow[0]['end_time'])->toBe($now->copy()->addHour()->toIso8601String());

    Carbon::setTestNow(); // unfreeze for any subsequent tests
});

it('leaves next_airing_at and recent_episodes ordering untouched by the airing_now addition', function () {
    $title = 'Steady Show';

    $upcomingSoon = now()->addHours(3);
    $upcomingLater = now()->addDays(2);
    $pastRecent = now()->subHours(2);

    // Insert in shuffled order to prove the sorts are doing the work.
    ($this->makeProgramme)($title, $upcomingLater);
    ($this->makeProgramme)($title, $pastRecent);
    ($this->makeProgramme)($title, $upcomingSoon);

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'steady'))
        ->assertOk();

    // next_airing_at = soonest upcoming.
    expect($response->json('0.next_airing_at'))->toBe($upcomingSoon->toIso8601String());

    // recent_episodes ordering unchanged: upcoming-soon, upcoming-later, past.
    $episodes = $response->json('0.recent_episodes');
    expect($episodes)->toHaveCount(3);
    expect($episodes[0]['start_time'])->toBe($upcomingSoon->toIso8601String());
    expect($episodes[1]['start_time'])->toBe($upcomingLater->toIso8601String());
    expect($episodes[2]['start_time'])->toBe($pastRecent->toIso8601String());

    // None are airing - all past/outside the live window.
    expect($response->json('0.airing_now'))->toBe([]);
});

it('includes a still-running programme in airing_now even when it started more than 24 hours ago', function () {
    $title = 'Marathon Show';
    $now = now();

    // Started 30 hours ago (outside the 24h lookback window) but still running
    // for another hour. The lookback cutoff on start_time must not exclude a
    // programme that is genuinely in progress right now.
    ($this->makeProgramme)($title, $now->copy()->subHours(30), $now->copy()->addHour());

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'marathon'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toHaveCount(1);
    expect($airingNow[0]['start_time'])->toBe($now->copy()->subHours(30)->toIso8601String());
});

it('deduplicates airing_now by channel when overlapping EPG data lists two in-progress programmes for the same channel', function () {
    $title = 'Overlap Show';
    $now = now();

    // Two overlapping in-progress entries for the same EPG channel (a schedule
    // correction overlap). Only one row per channel should surface in
    // airing_now, keeping the most-recently-started of the two.
    ($this->makeProgramme)($title, $now->copy()->subMinutes(30), $now->copy()->addMinutes(30));
    ($this->makeProgramme)($title, $now->copy()->subMinutes(5), $now->copy()->addMinutes(55));

    $response = $this->postJson(searchShowsUrl('credential-a', 'password-a', 'overlap'))
        ->assertOk();

    $airingNow = $response->json('0.airing_now');
    expect($airingNow)->toHaveCount(1);
    expect($airingNow[0]['start_time'])->toBe($now->copy()->subMinutes(5)->toIso8601String());
});
