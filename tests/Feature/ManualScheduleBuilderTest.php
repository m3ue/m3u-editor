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
    $this->user = User::factory()->create();
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
    $this->network->contents()->create([
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
    ]);
});

it('loads the schedule builder page', function () {
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->assertOk();
});

it('loads schedule for a specific date', function () {
    $date = '2026-03-06';

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('loadScheduleForDate', $date)
        ->assertSet('currentDate', $date);
});

it('adds a programme at a specific time with timezone conversion', function () {
    $date = '2026-03-06';
    $startTime = '15:30'; // 3:30 PM local (Eastern)
    $timezone = 'America/New_York';

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, $startTime, $timezone, Channel::class, $this->channel->id)
        ->assertOk();

    // Check programme stored in UTC (15:30 Eastern = 20:30 UTC)
    $programme = NetworkProgramme::where('network_id', $this->network->id)->first();
    expect($programme)->not->toBeNull();
    expect($programme->start_time->format('Y-m-d H:i:s'))->toBe('2026-03-06 20:30:00');
});

it('returns programme data in local timezone', function () {
    // Create programme in UTC
    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Test Programme',
        'start_time' => Carbon::parse('2026-03-06 20:30:00', 'UTC'),
        'end_time' => Carbon::parse('2026-03-06 21:00:00', 'UTC'),
        'duration_seconds' => 1800,
    ]);

    $timezone = 'America/New_York';

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('getScheduleForDate', '2026-03-06', $timezone)
        ->assertOk();
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
    ]);

    $service = app(NetworkEpgService::class);
    $xml = $service->generateXmltvForNetwork($this->network);

    expect($xml)->toContain('Manual Programme');
    expect($xml)->toContain('Test manual schedule programme');
});

it('cascade-bumps overlapping programmes forward', function () {
    $date = '2026-03-06';
    $timezone = 'America/New_York';

    // Add first programme at 15:00 (20:00 UTC), default 30 min duration
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, '15:00', $timezone, Channel::class, $this->channel->id);

    // Add second programme at same time — this is the anchor, so it stays at 15:00
    // and the first programme should be cascade-bumped forward to start after the second ends
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, '15:00', $timezone, Channel::class, $this->channel->id);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(2);
    // Second added programme is the anchor at 20:00 UTC (15:00 Eastern)
    // First programme gets bumped to after the anchor ends (20:30 UTC)
    expect($programmes[0]->start_time->format('H:i'))->toBe('20:00');
    expect($programmes[1]->start_time->format('H:i'))->toBe('20:30');
});

it('cascade-bumps a chain of programmes', function () {
    $date = '2026-03-06';
    $timezone = 'UTC';

    // Create three programmes back-to-back
    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 12:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    $progC = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog C',
        'start_time' => Carbon::parse("{$date} 12:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 13:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    // Now add a new 1-hour programme at 10:30 which overlaps Prog A's slot
    // This anchor sits at 10:30-11:30, so Prog A (at 10:00) is before it — NOT bumped.
    // Prog B at 11:00 overlaps anchor's end 11:30 — bumped to 11:30.
    // Prog C at 12:00 still ok after B's new end 12:30? No, 12:00 < 12:30 — bumped to 12:30.
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, '10:30', 'UTC', Channel::class, $this->channel->id, 3600);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(4);
    expect($programmes[0]->title)->toBe('Prog A');
    expect($programmes[0]->start_time->format('H:i'))->toBe('10:00'); // Untouched (before anchor)
    expect($programmes[1]->start_time->format('H:i'))->toBe('10:30'); // New anchor
    expect($programmes[2]->title)->toBe('Prog B');
    expect($programmes[2]->start_time->format('H:i'))->toBe('11:30'); // Bumped
    expect($programmes[3]->title)->toBe('Prog C');
    expect($programmes[3]->start_time->format('H:i'))->toBe('12:30'); // Cascade bumped
});

it('cascade-bumps respects schedule_gap_seconds', function () {
    $this->network->update(['schedule_gap_seconds' => 300]); // 5 minutes gap
    $date = '2026-03-06';
    $timezone = 'UTC';

    // Create a programme at 10:00-11:00
    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Existing',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    // Add new programme at 10:00 (overlaps existing)
    // Anchor at 10:00-11:00, existing bumped to 11:00 + 5min gap = 11:05
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('addProgramme', $date, '10:00', 'UTC', Channel::class, $this->channel->id, 3600);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(2);
    expect($programmes[0]->start_time->format('H:i'))->toBe('10:00'); // Anchor
    expect($programmes[1]->start_time->format('H:i'))->toBe('11:05'); // Bumped with 5min gap
});

it('cascade-bumps on programme move (updateProgramme)', function () {
    $date = '2026-03-06';
    $timezone = 'UTC';

    // Create two programmes: A at 10:00-11:00, B at 12:00-13:00 (with a gap between)
    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 12:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 13:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    // Move Prog A to 11:30 — now overlaps with Prog B (11:30-12:30 vs 12:00-13:00)
    // Prog B should be bumped to 12:30
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('updateProgramme', $progA->id, $date, '11:30', 'UTC');

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(2);
    expect($programmes[0]->title)->toBe('Prog A');
    expect($programmes[0]->start_time->format('H:i'))->toBe('11:30'); // Moved
    expect($programmes[1]->title)->toBe('Prog B');
    expect($programmes[1]->start_time->format('H:i'))->toBe('12:30'); // Bumped
});

it('appends programme after the last programme of the day', function () {
    $date = '2026-03-06';
    $timezone = 'UTC';

    // Create two programmes
    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'First',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Second',
        'start_time' => Carbon::parse("{$date} 12:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 13:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    // Append — should place after "Second" ends at 13:00
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('appendProgramme', $date, $timezone, Channel::class, $this->channel->id, 1800);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(3);
    expect($programmes[2]->start_time->format('H:i'))->toBe('13:00');
    expect($programmes[2]->end_time->format('H:i'))->toBe('13:30');
});

it('appends programme at midnight on an empty day', function () {
    $date = '2026-03-06';
    $timezone = 'UTC';

    // No programmes exist for this day
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('appendProgramme', $date, $timezone, Channel::class, $this->channel->id, 1800);

    $programme = NetworkProgramme::where('network_id', $this->network->id)->first();

    expect($programme)->not->toBeNull();
    expect($programme->start_time->format('Y-m-d H:i'))->toBe('2026-03-06 00:00');
});

it('appends programme respects schedule_gap_seconds', function () {
    $this->network->update(['schedule_gap_seconds' => 300]); // 5 min gap
    $date = '2026-03-06';
    $timezone = 'UTC';

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Existing',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('appendProgramme', $date, $timezone, Channel::class, $this->channel->id, 1800);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(2);
    // Should be at 11:05 (11:00 end + 5 min gap)
    expect($programmes[1]->start_time->format('H:i'))->toBe('11:05');
});

it('inserts programme after a specific programme', function () {
    $date = '2026-03-06';
    $timezone = 'UTC';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 12:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    // Insert after Prog A — should go at 11:00, bumping Prog B to 12:00
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('insertAfterProgramme', $progA->id, $date, $timezone, Channel::class, $this->channel->id, 3600);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(3);
    expect($programmes[0]->title)->toBe('Prog A');
    expect($programmes[0]->start_time->format('H:i'))->toBe('10:00'); // Untouched
    expect($programmes[1]->start_time->format('H:i'))->toBe('11:00'); // New (inserted after A)
    expect($programmes[2]->title)->toBe('Prog B');
    expect($programmes[2]->start_time->format('H:i'))->toBe('12:00'); // Bumped
});

it('inserts after programme respects gap and cascade bumps', function () {
    $this->network->update(['schedule_gap_seconds' => 600]); // 10 min gap
    $date = '2026-03-06';
    $timezone = 'UTC';

    $progA = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog A',
        'start_time' => Carbon::parse("{$date} 10:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    $progB = NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'title' => 'Prog B',
        'start_time' => Carbon::parse("{$date} 11:00:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 12:00:00", 'UTC'),
        'duration_seconds' => 3600,
    ]);

    // Insert 30-min programme after Prog A with 10-min gap
    // Should start at 11:10 (11:00 + 10min gap), end at 11:40
    // Prog B at 11:00 overlaps, so bumped to 11:40 + 10min gap = 11:50
    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('insertAfterProgramme', $progA->id, $date, $timezone, Channel::class, $this->channel->id, 1800);

    $programmes = NetworkProgramme::where('network_id', $this->network->id)
        ->orderBy('start_time')
        ->get();

    expect($programmes)->toHaveCount(3);
    expect($programmes[0]->title)->toBe('Prog A');
    expect($programmes[0]->start_time->format('H:i'))->toBe('10:00');
    expect($programmes[1]->start_time->format('H:i'))->toBe('11:10'); // After A + 10min gap
    expect($programmes[2]->title)->toBe('Prog B');
    expect($programmes[2]->start_time->format('H:i'))->toBe('11:50'); // After new + 10min gap
});

it('insert after non-existent programme returns failure', function () {
    $date = '2026-03-06';

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('insertAfterProgramme', 99999, $date, 'UTC', Channel::class, $this->channel->id, 1800)
        ->assertNotified();
});

it('clears programmes for a specific day', function () {
    $date = '2026-03-06';

    NetworkProgramme::create([
        'network_id' => $this->network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $this->channel->id,
        'start_time' => Carbon::parse("{$date} 20:30:00", 'UTC'),
        'end_time' => Carbon::parse("{$date} 21:00:00", 'UTC'),
        'duration_seconds' => 1800,
    ]);

    Livewire::test(ManualScheduleBuilder::class, ['record' => $this->network->id])
        ->call('clearDay', $date, 'America/New_York')
        ->assertOk();

    $count = NetworkProgramme::where('network_id', $this->network->id)->count();
    expect($count)->toBe(0);
});
