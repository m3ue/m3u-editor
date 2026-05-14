<?php

/**
 * Tests for DvrOverviewTool
 *
 * Covers:
 * - Status view returns currently recording, upcoming, and recent failures
 * - Rules view returns active recording rules
 * - Capacity view returns concurrent slots and disk usage
 * - Recent view returns latest recordings with optional status filter
 * - Invalid status filter returns helpful error
 * - All views are scoped to the authenticated user
 */

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Filament\CopilotTools\DvrOverviewTool;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Tools\Request;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeTool(): DvrOverviewTool
{
    return new DvrOverviewTool;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeDvrRecording(User $user, DvrSetting $setting, Channel $channel, array $overrides = []): DvrRecording
{
    return DvrRecording::factory()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create(array_merge([
            'channel_id' => $channel->id,
        ], $overrides));
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Queue::fake();
});

// ── Status View ───────────────────────────────────────────────────────────────

it('shows currently recording and upcoming in status view', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Recording,
        'title' => 'Live Event',
        'scheduled_start' => now()->subHour(),
        'scheduled_end' => now()->addHour(),
    ]);

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Scheduled,
        'title' => 'Future Show',
        'scheduled_start' => now()->addHour(),
        'scheduled_end' => now()->addHours(2),
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'status',
        'limit' => 10,
    ]));

    expect((string) $result)
        ->toContain('Live Event')
        ->toContain('Future Show')
        ->toContain('[recording]')
        ->toContain('[scheduled]');
});

it('shows recent failures in status view', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Failed,
        'title' => 'Broken Recording',
        'error_message' => 'Network timeout',
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'status',
        'limit' => 10,
    ]));

    expect((string) $result)
        ->toContain('Broken Recording')
        ->toContain('Network timeout');
});

it('scopes status view to authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $playlistA = Playlist::factory()->for($userA)->create();
    $settingA = DvrSetting::factory()->for($userA)->for($playlistA)->create();
    $channelA = Channel::factory()->for($userA)->for($playlistA)->create();

    $playlistB = Playlist::factory()->for($userB)->create();
    $settingB = DvrSetting::factory()->for($userB)->for($playlistB)->create();
    $channelB = Channel::factory()->for($userB)->for($playlistB)->create();

    makeDvrRecording($userA, $settingA, $channelA, [
        'status' => DvrRecordingStatus::Recording,
        'title' => 'User A Show',
    ]);

    makeDvrRecording($userB, $settingB, $channelB, [
        'status' => DvrRecordingStatus::Recording,
        'title' => 'User B Show',
    ]);

    $this->actingAs($userA);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'status',
        'limit' => 10,
    ]));

    expect((string) $result)
        ->toContain('User A Show')
        ->not->toContain('User B Show');
});

// ── Rules View ────────────────────────────────────────────────────────────────

it('shows active recording rules', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    DvrRecordingRule::factory()->for($user)->for($setting)->create([
        'type' => DvrRuleType::Series,
        'series_title' => 'Test Series',
        'channel_id' => $channel->id,
        'enabled' => true,
        'priority' => 5,
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'rules',
        'limit' => 10,
    ]));

    expect((string) $result)
        ->toContain('Test Series')
        ->toContain('Series')
        ->toContain('priority: 5');
});

it('shows no rules message when none exist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'rules',
        'limit' => 10,
    ]));

    expect((string) $result)->toContain('No active recording rules found');
});

// ── Capacity View ─────────────────────────────────────────────────────────────

it('shows capacity overview with disk usage', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create([
        'max_concurrent_recordings' => 3,
        'global_disk_quota_gb' => 100,
    ]);
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Recording,
        'file_size_bytes' => 2_147_483_648, // 2 GB
    ]);

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Completed,
        'file_size_bytes' => 1_073_741_824, // 1 GB
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'capacity',
    ]));

    expect((string) $result)
        ->toContain('Concurrent: 1 / 3 active')
        ->toContain('Disk: 3 GB / 100 GB')
        ->toContain('(3%)');
});

it('shows capacity without quota when none set', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create([
        'global_disk_quota_gb' => null,
    ]);
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Completed,
        'file_size_bytes' => 1_073_741_824,
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'capacity',
    ]));

    expect((string) $result)->toContain('no quota set');
});

// ── Recent View ───────────────────────────────────────────────────────────────

it('shows recent recordings in recent view', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Completed,
        'title' => 'Old Movie',
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'recent',
        'limit' => 10,
    ]));

    expect((string) $result)->toContain('Old Movie');
});

it('filters recent view by status', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Failed,
        'title' => 'Failed Show',
    ]);

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Completed,
        'title' => 'Completed Show',
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'recent',
        'limit' => 10,
        'status_filter' => 'failed',
    ]));

    expect((string) $result)
        ->toContain('Failed Show')
        ->not->toContain('Completed Show');
});

it('returns error for invalid status filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([
        'view' => 'recent',
        'status_filter' => 'invalid_status',
    ]));

    expect((string) $result)->toContain('Invalid status_filter');
});

// ── Defaults ──────────────────────────────────────────────────────────────────

it('defaults to status view when no view specified', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->for($user)->for($playlist)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create();

    makeDvrRecording($user, $setting, $channel, [
        'status' => DvrRecordingStatus::Recording,
        'title' => 'Default Show',
    ]);

    $this->actingAs($user);

    $tool = makeTool();
    $result = $tool->handle(new Request([]));

    expect((string) $result)
        ->toContain('DVR Status Overview')
        ->toContain('Default Show');
});
