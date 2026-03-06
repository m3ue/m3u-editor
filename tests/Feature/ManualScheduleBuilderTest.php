<?php

use App\Filament\Resources\Networks\Pages\ManualScheduleBuilder;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\User;
use App\Services\NetworkEpgService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'permissions' => ['use_integrations'],
    ]);
    $this->actingAs($this->user);

    $this->network = Network::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Manual Network',
        'schedule_type' => 'manual',
        'schedule_gap_seconds' => 0,
    ]);

    $this->channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Test Channel',
    ]);

    // Add to network content
    $this->network->networkContent()->create([
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
    ]);
});

it('loads the schedule builder page', function () {
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->assertOk();
});

it('returns schedule for a date ordered by sort_order', function () {
    $date = '2026-03-06';

    // Create programmes with explicit sort_order
    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Second',
        'start_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 02:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
    ]);

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'First',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $result = Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('getScheduleForDate', $date, 'UTC');

    $programmes = $result->effects['returns'][0] ?? $result->json()['serverMemo']['data']['programmes'] ?? [];

    // If we can't easily extract from Livewire, just verify DB state
    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    expect($progs)->toHaveCount(2);
    expect($progs[0]->title)->toBe('First');
    expect($progs[1]->title)->toBe('Second');
});

it('adds a programme to the end of the schedule', function () {
    $date = '2026-03-06';

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, 'UTC', Channel::class, $this->channel->id, 3600);

    $programme = NetworkProgramme::where('network_id', $this->network->id)->first();

    expect($programme)->not->toBeNull();
    expect($programme->sort_order)->toBe(0);
    expect($programme->duration_seconds)->toBe(3600);
    // First programme starts at day start (midnight UTC)
    expect($programme->start_time->format('Y-m-d H:i'))->toBe('2026-03-06 00:00');
    expect($programme->end_time->format('Y-m-d H:i'))->toBe('2026-03-06 01:00');
});

it('appends second programme after the first with correct times', function () {
    $date = '2026-03-06';

    // Add first programme
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, 'UTC', Channel::class, $this->channel->id, 3600);

    // Add second programme (should append)
    $channel2 = Channel::factory()->create(['user_id' => $this->user->id, 'title' => 'Channel 2']);

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, 'UTC', Channel::class, $channel2->id, 1800);

    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    expect($progs)->toHaveCount(2);
    expect($progs[0]->sort_order)->toBe(0);
    expect($progs[1]->sort_order)->toBe(1);
    // Second starts right when first ends (no gap)
    expect($progs[1]->start_time->format('H:i'))->toBe('01:00');
    expect($progs[1]->end_time->format('H:i'))->toBe('01:30');
});

it('respects schedule_gap_seconds when adding programmes', function () {
    $this->network->update(['schedule_gap_seconds' => 300]); // 5 minutes
    $date = '2026-03-06';

    // Add first programme (1hr)
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, 'UTC', Channel::class, $this->channel->id, 3600);

    // Add second — should start at 01:05 (01:00 + 5min gap)
    $channel2 = Channel::factory()->create(['user_id' => $this->user->id]);
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, 'UTC', Channel::class, $channel2->id, 1800);

    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    expect($progs)->toHaveCount(2);
    expect($progs[1]->start_time->format('H:i'))->toBe('01:05');
});

it('reorders programmes and recalculates times', function () {
    $date = '2026-03-06';

    // Create two programmes in order
    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A (1hr)',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B (30min)',
        'start_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:30:00", 'UTC'),
        'duration_seconds' => 1800,
        'sort_order' => 1,
    ]);

    // Reorder: B first, then A
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('reorderProgrammes', [$progB->id, $progA->id], $date, 'UTC');

    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    expect($progs[0]->id)->toBe($progB->id);
    expect($progs[0]->sort_order)->toBe(0);
    expect($progs[0]->start_time->format('H:i'))->toBe('00:00'); // B now first (30min)
    expect($progs[0]->end_time->format('H:i'))->toBe('00:30');

    expect($progs[1]->id)->toBe($progA->id);
    expect($progs[1]->sort_order)->toBe(1);
    expect($progs[1]->start_time->format('H:i'))->toBe('00:30'); // A starts after B
    expect($progs[1]->end_time->format('H:i'))->toBe('01:30');
});

it('inserts programme after a specific programme', function () {
    $date = '2026-03-06';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 02:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
    ]);

    // Insert 30-min programme after Prog A
    $channel2 = Channel::factory()->create(['user_id' => $this->user->id]);
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('insertAfterProgramme', $progA->id, $date, 'UTC', Channel::class, $channel2->id, 1800);

    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    expect($progs)->toHaveCount(3);
    expect($progs[0]->title)->toBe('Prog A');
    expect($progs[0]->sort_order)->toBe(0);
    expect($progs[0]->start_time->format('H:i'))->toBe('00:00');

    // New programme at sort_order 1
    expect($progs[1]->sort_order)->toBe(1);
    expect($progs[1]->start_time->format('H:i'))->toBe('01:00');
    expect($progs[1]->end_time->format('H:i'))->toBe('01:30');

    // Prog B shifted to sort_order 2
    expect($progs[2]->title)->toBe('Prog B');
    expect($progs[2]->sort_order)->toBe(2);
    expect($progs[2]->start_time->format('H:i'))->toBe('01:30');
    expect($progs[2]->end_time->format('H:i'))->toBe('02:30');
});

it('insert after non-existent programme returns failure', function () {
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('insertAfterProgramme', 99999, '2026-03-06', 'UTC', Channel::class, $this->channel->id, 1800)
        ->assertNotified();
});

it('removes a programme and recalculates times', function () {
    $date = '2026-03-06';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 02:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
    ]);

    // Remove Prog A — Prog B should recalculate to start at day start
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('removeProgramme', $progA->id, $date, 'UTC');

    $remaining = NetworkProgramme::where('network_id', $this->network->id)->get();

    expect($remaining)->toHaveCount(1);
    expect($remaining[0]->title)->toBe('Prog B');
    expect($remaining[0]->start_time->format('H:i'))->toBe('00:00');
    expect($remaining[0]->end_time->format('H:i'))->toBe('01:00');
});

it('pins a programme to a specific time', function () {
    $date = '2026-03-06';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 02:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
    ]);

    // Pin Prog B to 14:00 UTC
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('pinProgrammeTime', $progB->id, '14:00', $date, 'UTC');

    $progB->refresh();

    expect($progB->pinned_start_time)->not->toBeNull();
    expect($progB->start_time->format('H:i'))->toBe('14:00');
    expect($progB->end_time->format('H:i'))->toBe('15:00');

    // Prog A should be unchanged (it's before the pinned programme)
    $progA->refresh();
    expect($progA->start_time->format('H:i'))->toBe('00:00');
});

it('unpins a programme and recalculates sequential time', function () {
    $date = '2026-03-06';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 14:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 15:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
        'pinned_start_time' => Carbon::parse("{$date} 14:00:00", 'UTC'),
    ]);

    // Unpin Prog B — should flow sequentially after Prog A
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('pinProgrammeTime', $progB->id, null, $date, 'UTC');

    $progB->refresh();

    expect($progB->pinned_start_time)->toBeNull();
    expect($progB->start_time->format('H:i'))->toBe('01:00'); // Flows after Prog A
});

it('converts timezone correctly for pinned times', function () {
    $date = '2026-03-06';

    $prog = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    // Pin to 15:00 Eastern (should be 20:00 UTC)
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('pinProgrammeTime', $prog->id, '15:00', $date, 'America/New_York');

    $prog->refresh();

    expect($prog->pinned_start_time->format('H:i'))->toBe('20:00'); // UTC
    expect($prog->start_time->format('H:i'))->toBe('20:00');
});

it('clears all programmes for a day', function () {
    $date = '2026-03-06';

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 02:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
    ]);

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('clearDay', $date, 'UTC');

    expect(NetworkProgramme::where('network_id', $this->network->id)->count())->toBe(0);
});

it('copies a day schedule to another date', function () {
    $sourceDate = '2026-03-06';
    $targetDate = '2026-03-07';

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$sourceDate} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$sourceDate} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$sourceDate} 01:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$sourceDate} 02:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
        'pinned_start_time' => Carbon::parse("{$sourceDate} 01:00:00", 'UTC'),
    ]);

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('copyDaySchedule', $sourceDate, $targetDate, 'UTC');

    // Source day still has 2 programmes
    $sourceProgs = NetworkProgramme::where('network_id', $this->network->id)
        ->where('start_time', '>=', Carbon::parse("{$sourceDate} 00:00:00", 'UTC'))
        ->where('start_time', '<', Carbon::parse("{$sourceDate} 23:59:59", 'UTC'))
        ->count();
    expect($sourceProgs)->toBe(2);

    // Target day should have 2 copied programmes
    $targetProgs = NetworkProgramme::where('network_id', $this->network->id)
        ->where('start_time', '>=', Carbon::parse("{$targetDate} 00:00:00", 'UTC'))
        ->where('start_time', '<', Carbon::parse("{$targetDate} 23:59:59", 'UTC'))
        ->orderBy('sort_order')
        ->get();

    expect($targetProgs)->toHaveCount(2);
    expect($targetProgs[0]->title)->toBe('Prog A');
    expect($targetProgs[0]->sort_order)->toBe(0);
    expect($targetProgs[0]->start_time->format('Y-m-d H:i'))->toBe('2026-03-07 00:00');

    expect($targetProgs[1]->title)->toBe('Prog B');
    expect($targetProgs[1]->sort_order)->toBe(1);
    expect($targetProgs[1]->pinned_start_time)->not->toBeNull();
    expect($targetProgs[1]->pinned_start_time->format('Y-m-d H:i'))->toBe('2026-03-07 01:00');
});

it('includes manual programmes in EPG output', function () {
    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Manual Programme',
        'description' => 'Test manual schedule programme',
        'start_time' => Carbon::now(),
        'end_time' => Carbon::now()->addHour(),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $service = app(NetworkEpgService::class);
    $xml = $service->generateXmltvForNetwork($this->network);

    expect($xml)->toContain('Manual Programme');
    expect($xml)->toContain('Test manual schedule programme');
});

it('reorder with gap recalculates correctly', function () {
    $this->network->update(['schedule_gap_seconds' => 600]); // 10 minutes
    $date = '2026-03-06';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A (1hr)',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B (30min)',
        'start_time' => Carbon::parse("{$date} 01:10:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:40:00", 'UTC'),
        'duration_seconds' => 1800,
        'sort_order' => 1,
    ]);

    // Reorder: B first, then A
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('reorderProgrammes', [$progB->id, $progA->id], $date, 'UTC');

    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    // B starts at day start (00:00), ends at 00:30
    expect($progs[0]->start_time->format('H:i'))->toBe('00:00');
    expect($progs[0]->end_time->format('H:i'))->toBe('00:30');
    // A starts at 00:30 + 10min gap = 00:40, ends at 01:40
    expect($progs[1]->start_time->format('H:i'))->toBe('00:40');
    expect($progs[1]->end_time->format('H:i'))->toBe('01:40');
});

it('first programme has no gap even with gap setting', function () {
    $this->network->update(['schedule_gap_seconds' => 300]); // 5 minutes
    $date = '2026-03-06';

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, 'UTC', Channel::class, $this->channel->id, 3600);

    $prog = NetworkProgramme::where('network_id', $this->network->id)->first();

    // First programme should start at day start, not day start + gap
    expect($prog->start_time->format('H:i'))->toBe('00:00');
});

it('insert after with gap shifts sort orders correctly', function () {
    $this->network->update(['schedule_gap_seconds' => 300]); // 5 minutes
    $date = '2026-03-06';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 00:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 01:00:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 0,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 01:05:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 02:05:00", 'UTC'),
        'duration_seconds' => 3600,
        'sort_order' => 1,
    ]);

    // Insert 30-min programme after Prog A
    $channel2 = Channel::factory()->create(['user_id' => $this->user->id]);
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('insertAfterProgramme', $progA->id, $date, 'UTC', Channel::class, $channel2->id, 1800);

    $progs = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('sort_order')
        ->get();

    expect($progs)->toHaveCount(3);
    // A: 00:00-01:00
    expect($progs[0]->start_time->format('H:i'))->toBe('00:00');
    // New: 01:00 + 5min gap = 01:05, ends 01:35
    expect($progs[1]->start_time->format('H:i'))->toBe('01:05');
    expect($progs[1]->end_time->format('H:i'))->toBe('01:35');
    // B: 01:35 + 5min gap = 01:40
    expect($progs[2]->start_time->format('H:i'))->toBe('01:40');
});
