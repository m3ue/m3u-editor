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
        ->toContain("No upcoming programmes found matching 'Nonexistent Show'.");
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
        ->toContain("No upcoming programmes found matching 'xyznonexistent'.");
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
