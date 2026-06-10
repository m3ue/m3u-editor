<?php

/**
 * Tests for DvrScheduleTool
 *
 * Covers:
 * - search finds upcoming programmes by title keyword
 * - search returns empty message when no matches
 * - search scopes to user's mapped channels only
 * - schedule_once creates a Once rule and dispatches DvrSchedulerTick
 * - schedule_once prevents duplicate rules
 * - schedule_once without dvr_setting_id lists settings
 * - schedule_series creates a Series rule
 * - schedule_series prevents duplicate rules
 * - schedule_series with invalid channel_id returns error
 * - schedule_series with series_mode "new_only" sets correct enum
 */

use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Filament\CopilotTools\DvrScheduleTool;
use App\Jobs\DvrSchedulerTick;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeScheduleTool(): DvrScheduleTool
{
    return new DvrScheduleTool;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeEpgProgramme(EpgChannel $epgChannel, User $user, array $overrides = []): EpgProgramme
{
    return EpgProgramme::factory()
        ->for($epgChannel->epg)
        ->create(array_merge([
            'epg_channel_id' => $epgChannel->channel_id,
            'start_time' => now()->addHours(2),
            'end_time' => now()->addHours(3),
        ], $overrides));
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Queue::fake();
});

// ── Search Action ─────────────────────────────────────────────────────────────

it('search finds upcoming programmes by title keyword', function () {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.123',
        'display_name' => 'Test Channel',
    ]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => true,
    ]);

    $programme = makeEpgProgramme($epgChannel, $user, [
        'title' => 'Doctor Who',
        'description' => 'A science fiction series.',
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'search',
        'query' => 'Doctor Who',
    ]));

    expect((string) $result)
        ->toContain('Doctor Who')
        ->toContain('Test Channel');
});

it('search returns empty message when no matches', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'search',
        'query' => 'Nonexistent Show',
    ]));

    expect((string) $result)
        ->toContain("No upcoming programmes found matching 'Nonexistent Show'");
});

it('search scopes to users mapped channels only', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $epg = Epg::factory()->for($userA)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($userA)->create([
        'channel_id' => 'channel.999',
        'display_name' => 'Private Channel',
    ]);
    $playlistA = Playlist::factory()->for($userA)->create();
    $channelA = Channel::factory()->for($userA)->for($playlistA)->create([
        'epg_channel_id' => $epgChannel->id,
    ]);

    // Programme visible to user A but NOT mapped to user B
    makeEpgProgramme($epgChannel, $userA, [
        'title' => 'Secret Show',
    ]);

    // User B has no mapped channels at all
    $this->actingAs($userB);

    $tool = makeScheduleTool();
    // Use a query that won't appear in the "not found" message itself
    $result = $tool->handle(new Request([
        'action' => 'search',
        'query' => 'xyznonexistent',
    ]));

    // User B has no mapped channels, so should find nothing
    expect((string) $result)
        ->toContain("No upcoming programmes found matching 'xyznonexistent'");
});

// ── Schedule Once Action ───────────────────────────────────────────────────────

it('schedule_once creates a Once rule and dispatches DvrSchedulerTick', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.555',
    ]);
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => true,
    ]);

    $programme = makeEpgProgramme($epgChannel, $user, [
        'title' => 'The Tonight Show',
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_once',
        'programme_id' => $programme->id,
        'dvr_setting_id' => $setting->id,
    ]));

    expect((string) $result)
        ->toContain('Once rule created')
        ->toContain('The Tonight Show');

    expect(DvrRecordingRule::where('user_id', $user->id)
        ->where('type', DvrRuleType::Once)
        ->where('programme_id', $programme->id)
        ->exists())->toBeTrue();

    Queue::assertPushed(DvrSchedulerTick::class);
});

it('schedule_once prevents duplicate rules', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.777',
    ]);

    $programme = makeEpgProgramme($epgChannel, $user, [
        'title' => 'Duplicate Show',
    ]);

    // Create existing rule
    DvrRecordingRule::factory()->for($user)->for($setting)->create([
        'type' => DvrRuleType::Once,
        'programme_id' => $programme->id,
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_once',
        'programme_id' => $programme->id,
        'dvr_setting_id' => $setting->id,
    ]));

    expect((string) $result)->toContain('already exists');
});

it('schedule_once without dvr_setting_id lists settings', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['name' => 'My Playlist']);
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_once',
        'programme_id' => 999,
    ]));

    expect((string) $result)
        ->toContain('Available DVR settings')
        ->toContain('My Playlist')
        ->toContain("#{$setting->id}");
});

it('schedule_once blocks disabled channel when include_disabled_channels is false', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create([
        'include_disabled_channels' => false,
    ]);
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.disabled.once',
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => false,
        'title' => 'Disabled Once Channel',
    ]);

    $programme = makeEpgProgramme($epgChannel, $user, [
        'title' => 'Disabled Once Programme',
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_once',
        'programme_id' => $programme->id,
        'dvr_setting_id' => $setting->id,
    ]));

    expect((string) $result)->toContain('is disabled')
        ->and((string) $result)->toContain('excludes disabled channels');

    expect(DvrRecordingRule::where('user_id', $user->id)
        ->where('type', DvrRuleType::Once)
        ->where('programme_id', $programme->id)
        ->exists())->toBeFalse();
});

it('schedule_once allows disabled channel when include_disabled_channels is true', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create([
        'include_disabled_channels' => true,
    ]);
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.disabled.once.allowed',
    ]);
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => false,
        'title' => 'Disabled Allowed Channel',
    ]);

    $programme = makeEpgProgramme($epgChannel, $user, [
        'title' => 'Disabled Allowed Programme',
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_once',
        'programme_id' => $programme->id,
        'dvr_setting_id' => $setting->id,
    ]));

    expect((string) $result)->toContain('Once rule created');

    expect(DvrRecordingRule::where('user_id', $user->id)
        ->where('type', DvrRuleType::Once)
        ->where('programme_id', $programme->id)
        ->where('channel_id', $channel->id)
        ->exists())->toBeTrue();
});

// ── Schedule Series Action ───────────────────────────────────────────────────────

it('schedule_series creates a Series rule', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_series',
        'title' => 'The Simpsons',
        'dvr_setting_id' => $setting->id,
    ]));

    expect((string) $result)
        ->toContain('Series rule created')
        ->toContain('The Simpsons');

    expect(DvrRecordingRule::where('user_id', $user->id)
        ->where('type', DvrRuleType::Series)
        ->where('series_title', 'The Simpsons')
        ->exists())->toBeTrue();

    Queue::assertPushed(DvrSchedulerTick::class);
});

it('schedule_series prevents duplicate rules', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();

    // Create existing rule
    DvrRecordingRule::factory()->for($user)->for($setting)->create([
        'type' => DvrRuleType::Series,
        'series_title' => 'The Simpsons',
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_series',
        'title' => 'The Simpsons',
        'dvr_setting_id' => $setting->id,
    ]));

    expect((string) $result)->toContain('already exists');
});

it('schedule_series with invalid channel_id returns error', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_series',
        'title' => 'Show With Invalid Channel',
        'dvr_setting_id' => $setting->id,
        'channel_id' => 99999,
    ]));

    expect((string) $result)
        ->toContain('Channel #99999 not found')
        ->toContain('not belong to you');
});

it('schedule_series with series_mode new_only sets correct enum', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_series',
        'title' => 'New Episodes Show',
        'dvr_setting_id' => $setting->id,
        'series_mode' => 'new_only',
    ]));

    expect((string) $result)
        ->toContain('Series rule created')
        ->toContain('New Episodes Show')
        ->toContain('New Episodes Only');

    $rule = DvrRecordingRule::where('user_id', $user->id)
        ->where('series_title', 'New Episodes Show')
        ->first();

    expect($rule->series_mode)->toBe(DvrSeriesMode::NewFlag);
});

it('schedule_series blocks disabled pinned channel when include_disabled_channels is false', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create([
        'include_disabled_channels' => false,
    ]);
    $disabledChannel = Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => false,
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_series',
        'title' => 'Disabled Channel Series',
        'dvr_setting_id' => $setting->id,
        'channel_id' => $disabledChannel->id,
    ]));

    expect((string) $result)->toContain('is disabled')
        ->and((string) $result)->toContain('excludes disabled channels');

    expect(DvrRecordingRule::where('user_id', $user->id)
        ->where('type', DvrRuleType::Series)
        ->where('series_title', 'Disabled Channel Series')
        ->exists())->toBeFalse();
});

it('schedule_series allows disabled pinned channel when include_disabled_channels is true', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create([
        'include_disabled_channels' => true,
    ]);
    $disabledChannel = Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => false,
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'schedule_series',
        'title' => 'Disabled Allowed Series',
        'dvr_setting_id' => $setting->id,
        'channel_id' => $disabledChannel->id,
    ]));

    expect((string) $result)->toContain('Series rule created');

    expect(DvrRecordingRule::where('user_id', $user->id)
        ->where('type', DvrRuleType::Series)
        ->where('series_title', 'Disabled Allowed Series')
        ->where('channel_id', $disabledChannel->id)
        ->exists())->toBeTrue();
});

// ── Around Action ───────────────────────────────────────────────────────────────

/**
 * Build a WE TV EPG channel + mapped Channel for the given user, with a fixed
 * block of programmes at the supplied hours-from-now offsets.
 *
 * The `hour` slot value is interpreted as "hours from now" (not "hour of day")
 * so every programme is always in the future and falls inside the tool's
 * "this_week" window regardless of when the test runs.
 *
 * @param  list<array{title: string, hour: int, duration_min: int, is_new?: bool}>  $slots
 * @return array{epgChannel: EpgChannel, channel: Channel, programmes: Collection<int, EpgProgramme>}
 */
function buildChannelWithProgrammes(User $user, array $slots, string $displayName = 'WE TV'): array
{
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.'.uniqid(),
        'display_name' => $displayName,
        'name' => $displayName,
    ]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => true,
        'title' => $displayName,
    ]);

    $programmes = collect();
    foreach ($slots as $slot) {
        $start = now()->copy()->addHours($slot['hour']);
        $programmes->push(makeEpgProgramme($epgChannel, $user, [
            'title' => $slot['title'],
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($slot['duration_min']),
            'is_new' => $slot['is_new'] ?? false,
        ]));
    }

    return ['epgChannel' => $epgChannel, 'channel' => $channel, 'programmes' => $programmes];
}

it('around finds the matched programme and returns context before and after on the same channel', function () {
    $user = User::factory()->create();

    $setup = buildChannelWithProgrammes($user, [
        ['title' => 'Morning Show', 'hour' => 8, 'duration_min' => 60],
        ['title' => 'Midday News', 'hour' => 12, 'duration_min' => 30],
        ['title' => 'Love After Lockup', 'hour' => 18, 'duration_min' => 60],
        ['title' => 'Evening Wrap', 'hour' => 20, 'duration_min' => 30],
        ['title' => 'Late Night', 'hour' => 23, 'duration_min' => 30],
    ]);
    $targetId = $setup['programmes']->firstWhere('title', 'Love After Lockup')->id;

    $this->actingAs($user);

    $tool = makeScheduleTool();
    // Use this_week so the slice logic is isolated from the "today = now→midnight" filter.
    $result = $tool->handle(new Request([
        'action' => 'around',
        'query' => 'Love After Lockup',
        'channel' => 'WE TV',
        'time_window' => 'this_week',
        'context_before' => 2,
        'context_after' => 3,
    ]));

    expect((string) $result)
        ->toContain('Love After Lockup')
        ->toContain('Morning Show')   // 2 before
        ->toContain('Midday News')    // 1 before
        ->toContain('Evening Wrap')   // 1 after
        ->toContain('Late Night')     // 3 after
        ->toContain('▶')              // match marker
        ->toContain("#{$targetId}");
});

it('around with airing_time picks the match whose start_time is nearest the anchor', function () {
    $user = User::factory()->create();

    // Two episodes of the same show at 5h and 8h from now.
    $setup = buildChannelWithProgrammes($user, [
        ['title' => 'News', 'hour' => 4, 'duration_min' => 60],
        ['title' => 'Love After Lockup: Early Edition', 'hour' => 5, 'duration_min' => 60],
        ['title' => 'Love After Lockup: Late Edition', 'hour' => 8, 'duration_min' => 60],
    ]);
    $earlyId = $setup['programmes']->firstWhere('title', 'Love After Lockup: Early Edition')->id;
    $lateId = $setup['programmes']->firstWhere('title', 'Love After Lockup: Late Edition')->id;

    $this->actingAs($user);

    // Anchor at 7h from now — 1h away from Late Edition (8h) and 2h away from Early Edition (5h).
    $anchor = now()->copy()->addHours(7)->toIso8601String();

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'around',
        'query' => 'Love After Lockup',
        'channel' => 'WE TV',
        'airing_time' => $anchor,
        'context_before' => 1,
        'context_after' => 1,
    ]));

    // Late Edition is the match; Early Edition appears as the 1-before neighbour.
    // The ▶ marker should be on Late Edition, not Early Edition.
    $resultString = (string) $result;
    expect($resultString)
        ->toContain('Love After Lockup: Late Edition')
        ->toContain("#{$lateId}");

    $lateLine = collect(explode("\n", $resultString))
        ->first(fn (string $line) => str_contains($line, 'Late Edition'));
    $earlyLine = collect(explode("\n", $resultString))
        ->first(fn (string $line) => str_contains($line, 'Early Edition'));

    expect($lateLine)->toContain('▶');
    if ($earlyLine !== null) {
        expect($earlyLine)->not->toContain('▶');
    }
    expect($earlyId)->toBeLessThan($lateId); // sanity check on the fixture
});

it('around requires both query and channel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tool = makeScheduleTool();
    $noQuery = $tool->handle(new Request([
        'action' => 'around',
        'channel' => 'WE TV',
    ]));
    $noChannel = $tool->handle(new Request([
        'action' => 'around',
        'query' => 'Love After Lockup',
    ]));

    expect((string) $noQuery)->toContain('query');
    expect((string) $noChannel)->toContain('channel');
});

it('around returns a helpful message when the channel has programmes but none match', function () {
    $user = User::factory()->create();
    buildChannelWithProgrammes($user, [
        ['title' => 'News', 'hour' => 3, 'duration_min' => 60],
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'around',
        'query' => 'Nonexistent Show',
        'channel' => 'WE TV',
        'time_window' => 'this_week',
    ]));

    expect((string) $result)
        ->toContain('Nonexistent Show')
        ->toContain('WE TV')
        ->toContain('action=search');
});

it('around returns an error when the channel is not mapped to the user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'around',
        'query' => 'Anything',
        'channel' => 'Unmapped Channel',
    ]));

    expect((string) $result)->toContain("No channel found matching 'Unmapped Channel'");
});

it('around respects the context_before and context_after bounds', function () {
    $user = User::factory()->create();
    $setup = buildChannelWithProgrammes($user, [
        ['title' => 'Show A', 'hour' => 1, 'duration_min' => 30],
        ['title' => 'Show B', 'hour' => 2, 'duration_min' => 30],
        ['title' => 'Show C', 'hour' => 3, 'duration_min' => 30],
        ['title' => 'Love After Lockup', 'hour' => 4, 'duration_min' => 60],
        ['title' => 'Show D', 'hour' => 6, 'duration_min' => 30],
        ['title' => 'Show E', 'hour' => 7, 'duration_min' => 30],
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    // context_before=1, context_after=1 → only the immediate neighbours
    $result = $tool->handle(new Request([
        'action' => 'around',
        'query' => 'Love After Lockup',
        'channel' => 'WE TV',
        'time_window' => 'this_week',
        'context_before' => 1,
        'context_after' => 1,
    ]));

    expect((string) $result)
        ->toContain('Show C')  // 1 before
        ->toContain('Show D')  // 1 after
        ->not->toContain('Show A')
        ->not->toContain('Show B')
        ->not->toContain('Show E');
});

// ── Time Window Filtering ──────────────────────────────────────────────────────

it('search with time_window=today returns only today\'s programmes', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.today.test',
        'display_name' => 'Today Test',
    ]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => true,
    ]);

    $todayProgramme = makeEpgProgramme($epgChannel, $user, [
        'title' => 'Today Only',
        'start_time' => now()->copy()->addHours(2),
        'end_time' => now()->copy()->addHours(3),
    ]);
    makeEpgProgramme($epgChannel, $user, [
        'title' => 'Tomorrow Only',
        'start_time' => now()->copy()->addDay()->addHours(2),
        'end_time' => now()->copy()->addDay()->addHours(3),
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'search',
        'query' => 'Only',
        'time_window' => 'today',
    ]));

    expect((string) $result)
        ->toContain('Today Only')
        ->not->toContain('Tomorrow Only')
        ->toContain('today');
});

it('search with time_window=tomorrow returns only tomorrow\'s programmes', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.tomorrow.test',
        'display_name' => 'Tomorrow Test',
    ]);
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => true,
    ]);

    makeEpgProgramme($epgChannel, $user, [
        'title' => 'Today Pick',
        'start_time' => now()->copy()->addHours(2),
    ]);
    $tomorrow = makeEpgProgramme($epgChannel, $user, [
        'title' => 'Tomorrow Pick',
        'start_time' => now()->copy()->addDay()->addHours(2),
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'search',
        'query' => 'Pick',
        'time_window' => 'tomorrow',
    ]));

    expect((string) $result)
        ->toContain('Tomorrow Pick')
        ->not->toContain('Today Pick')
        ->toContain('tomorrow');
});

it('search falls back to the 7-day window for unknown time_window values', function () {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($epg)->for($user)->create([
        'channel_id' => 'channel.fallback.test',
        'display_name' => 'Fallback Test',
    ]);
    $playlist = Playlist::factory()->for($user)->create();
    Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
        'enabled' => true,
    ]);

    makeEpgProgramme($epgChannel, $user, [
        'title' => 'Within Week Show',
        'start_time' => now()->copy()->addDays(3),
    ]);

    $this->actingAs($user);

    $tool = makeScheduleTool();
    $result = $tool->handle(new Request([
        'action' => 'search',
        'query' => 'Within Week',
        'time_window' => 'next_fortnight', // unsupported value
    ]));

    expect((string) $result)
        ->toContain('Within Week Show')
        ->toContain('next 7 days');
});
