<?php

/**
 * Standalone test script for ManualScheduleBuilder (list-based approach).
 * Run inside container: docker exec m3u-editor php /var/www/html/test_schedule_builder.php
 */

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\User;
use Carbon\Carbon;

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS: {$message}\n";
    } else {
        $failed++;
        echo "  FAIL: {$message}\n";
    }
}

function assert_eq(mixed $actual, mixed $expected, string $message): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  PASS: {$message}\n";
    } else {
        $failed++;
        echo "  FAIL: {$message} (expected: {$expected}, got: {$actual})\n";
    }
}

// ── Setup ─────────────────────────────────────────────────────────────

// Find or create a test user
$user = User::first();
if (! $user) {
    echo "ERROR: No user found in database.\n";
    exit(1);
}

// Use test network id=199
$network = Network::find(199);
if (! $network) {
    echo "ERROR: Network id=199 not found.\n";
    exit(1);
}

// Find a channel to use as content
$channel = Channel::where('user_id', $user->id)->first();
if (! $channel) {
    echo "ERROR: No channel found for user.\n";
    exit(1);
}

echo "Setup: user={$user->id}, network={$network->id} ({$network->name}), channel={$channel->id}\n";
echo "Schedule type: {$network->schedule_type}, gap: {$network->schedule_gap_seconds}s\n\n";

// Save original gap so we can restore
$originalGap = $network->schedule_gap_seconds;

// ── Helper: Clean up test programmes ──────────────────────────────────

function cleanTestDay(Network $network, string $date): void
{
    $dayStart = Carbon::parse($date, 'UTC')->startOfDay();
    $dayEnd = Carbon::parse($date, 'UTC')->endOfDay();

    $network->programmes()
        ->where('start_time', '>=', $dayStart)
        ->where('start_time', '<', $dayEnd)
        ->delete();
}

// ── Helper: Create a programme for testing ────────────────────────────

function createProg(Network $network, Channel $channel, string $date, string $time, int $durationSec, int $sortOrder, ?string $pinnedTime = null): NetworkProgramme
{
    $start = Carbon::parse("{$date} {$time}", 'UTC');
    $end = $start->copy()->addSeconds($durationSec);
    $pinned = $pinnedTime ? Carbon::parse("{$date} {$pinnedTime}", 'UTC') : null;

    return NetworkProgramme::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'title' => "Test Prog ({$time})",
        'start_time' => $start,
        'end_time' => $end,
        'duration_seconds' => $durationSec,
        'sort_order' => $sortOrder,
        'pinned_start_time' => $pinned,
    ]);
}

// Use a far-future test date to avoid conflicting with real data
$testDate = '2030-01-15';

// ── Helper: Instantiate the Livewire page ──────────────────────────────

function getBuilder(Network $network): \App\Filament\Resources\Networks\Pages\ManualScheduleBuilder
{
    $builder = new \App\Filament\Resources\Networks\Pages\ManualScheduleBuilder;
    // Use reflection to set the record
    $ref = new ReflectionProperty($builder, 'record');
    $ref->setAccessible(true);
    $ref->setValue($builder, $network);

    return $builder;
}

// ══════════════════════════════════════════════════════════════════════
// TEST 1: recalculateTimes — sequential flow
// ══════════════════════════════════════════════════════════════════════
echo "TEST 1: recalculateTimes — sequential flow (no gaps, no pins)\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);  // 1hr
$p2 = createProg($network, $channel, $testDate, '05:00', 1800, 1);  // 30min, wrong time
$p3 = createProg($network, $channel, $testDate, '10:00', 7200, 2);  // 2hr, wrong time

$builder = getBuilder($network);
$ref = new ReflectionMethod($builder, 'recalculateTimes');
$ref->setAccessible(true);
$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$p1->refresh();
$p2->refresh();
$p3->refresh();

assert_eq($p1->start_time->format('H:i'), '00:00', 'P1 starts at 00:00');
assert_eq($p1->end_time->format('H:i'), '01:00', 'P1 ends at 01:00');
assert_eq($p2->start_time->format('H:i'), '01:00', 'P2 starts at 01:00');
assert_eq($p2->end_time->format('H:i'), '01:30', 'P2 ends at 01:30');
assert_eq($p3->start_time->format('H:i'), '01:30', 'P3 starts at 01:30');
assert_eq($p3->end_time->format('H:i'), '03:30', 'P3 ends at 03:30');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 2: recalculateTimes — with gap
// ══════════════════════════════════════════════════════════════════════
echo "TEST 2: recalculateTimes — with 5-minute gap\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 300]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);
$p2 = createProg($network, $channel, $testDate, '00:00', 1800, 1);

$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$p1->refresh();
$p2->refresh();

assert_eq($p1->start_time->format('H:i'), '00:00', 'P1 starts at 00:00 (first, no gap)');
assert_eq($p1->end_time->format('H:i'), '01:00', 'P1 ends at 01:00');
assert_eq($p2->start_time->format('H:i'), '01:05', 'P2 starts at 01:05 (+ 5min gap)');
assert_eq($p2->end_time->format('H:i'), '01:35', 'P2 ends at 01:35');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 3: recalculateTimes — pinned start time
// ══════════════════════════════════════════════════════════════════════
echo "TEST 3: recalculateTimes — pinned start time\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);
$p2 = createProg($network, $channel, $testDate, '00:00', 3600, 1, '14:00');  // Pinned to 14:00

$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$p1->refresh();
$p2->refresh();

assert_eq($p1->start_time->format('H:i'), '00:00', 'P1 unpinned starts at 00:00');
assert_eq($p2->start_time->format('H:i'), '14:00', 'P2 pinned starts at 14:00');
assert_eq($p2->end_time->format('H:i'), '15:00', 'P2 pinned ends at 15:00');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 4: reorderProgrammes
// ══════════════════════════════════════════════════════════════════════
echo "TEST 4: reorderProgrammes — swap two programmes\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);   // 1hr
$p2 = createProg($network, $channel, $testDate, '01:00', 1800, 1);   // 30min

$result = $builder->reorderProgrammes([$p2->id, $p1->id], $testDate, 'UTC');

assert_true($result['success'], 'reorderProgrammes returns success');

$p1->refresh();
$p2->refresh();

assert_eq($p2->sort_order, 0, 'P2 now first (sort_order=0)');
assert_eq($p1->sort_order, 1, 'P1 now second (sort_order=1)');
assert_eq($p2->start_time->format('H:i'), '00:00', 'P2 starts at 00:00');
assert_eq($p2->end_time->format('H:i'), '00:30', 'P2 ends at 00:30');
assert_eq($p1->start_time->format('H:i'), '00:30', 'P1 starts at 00:30');
assert_eq($p1->end_time->format('H:i'), '01:30', 'P1 ends at 01:30');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 5: addProgramme — appends to end
// ══════════════════════════════════════════════════════════════════════
echo "TEST 5: addProgramme — appends to end\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);

// Call recalculate first to set correct times
$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$result = $builder->addProgramme($testDate, 'UTC', Channel::class, $channel->id, 1800);

assert_true($result['success'], 'addProgramme returns success');

$progs = NetworkProgramme::where('network_id', $network->id)
    ->where('start_time', '>=', Carbon::parse("{$testDate} 00:00", 'UTC'))
    ->where('start_time', '<', Carbon::parse("{$testDate} 23:59:59", 'UTC'))
    ->orderBy('sort_order')
    ->get();

assert_eq($progs->count(), 2, 'Two programmes exist');
assert_eq($progs[0]->sort_order, 0, 'First prog sort_order=0');
assert_eq($progs[1]->sort_order, 1, 'New prog sort_order=1');
assert_eq($progs[1]->start_time->format('H:i'), '01:00', 'New prog starts at 01:00');
assert_eq($progs[1]->end_time->format('H:i'), '01:30', 'New prog ends at 01:30');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 6: addProgramme — empty day starts at midnight
// ══════════════════════════════════════════════════════════════════════
echo "TEST 6: addProgramme — empty day starts at midnight\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$result = $builder->addProgramme($testDate, 'UTC', Channel::class, $channel->id, 3600);

$prog = NetworkProgramme::where('network_id', $network->id)
    ->where('start_time', '>=', Carbon::parse("{$testDate} 00:00", 'UTC'))
    ->where('start_time', '<', Carbon::parse("{$testDate} 23:59:59", 'UTC'))
    ->first();

assert_true($prog !== null, 'Programme created');
assert_eq($prog->start_time->format('H:i'), '00:00', 'Starts at midnight');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 7: insertAfterProgramme
// ══════════════════════════════════════════════════════════════════════
echo "TEST 7: insertAfterProgramme — inserts between two programmes\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);   // 1hr
$p2 = createProg($network, $channel, $testDate, '01:00', 3600, 1);   // 1hr

$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$result = $builder->insertAfterProgramme($p1->id, $testDate, 'UTC', Channel::class, $channel->id, 1800);

assert_true($result['success'], 'insertAfterProgramme returns success');

$progs = NetworkProgramme::where('network_id', $network->id)
    ->where('start_time', '>=', Carbon::parse("{$testDate} 00:00", 'UTC'))
    ->where('start_time', '<', Carbon::parse("{$testDate} 23:59:59", 'UTC'))
    ->orderBy('sort_order')
    ->get();

assert_eq($progs->count(), 3, 'Three programmes exist');
assert_eq($progs[0]->sort_order, 0, 'P1 sort_order=0');
assert_eq($progs[1]->sort_order, 1, 'New prog sort_order=1');
assert_eq($progs[2]->sort_order, 2, 'P2 shifted to sort_order=2');
assert_eq($progs[1]->start_time->format('H:i'), '01:00', 'New prog starts at 01:00');
assert_eq($progs[1]->end_time->format('H:i'), '01:30', 'New prog ends at 01:30');
assert_eq($progs[2]->start_time->format('H:i'), '01:30', 'P2 starts at 01:30');
assert_eq($progs[2]->end_time->format('H:i'), '02:30', 'P2 ends at 02:30');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 8: removeProgramme — recalculates remaining
// ══════════════════════════════════════════════════════════════════════
echo "TEST 8: removeProgramme — recalculates after removal\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);
$p2 = createProg($network, $channel, $testDate, '01:00', 3600, 1);

$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$result = $builder->removeProgramme($p1->id, $testDate, 'UTC');

assert_true($result['success'], 'removeProgramme returns success');

$remaining = NetworkProgramme::where('network_id', $network->id)
    ->where('start_time', '>=', Carbon::parse("{$testDate} 00:00", 'UTC'))
    ->where('start_time', '<', Carbon::parse("{$testDate} 23:59:59", 'UTC'))
    ->get();

assert_eq($remaining->count(), 1, 'One programme remains');
assert_eq($remaining[0]->start_time->format('H:i'), '00:00', 'Remaining starts at day start');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 9: pinProgrammeTime — pin and unpin
// ══════════════════════════════════════════════════════════════════════
echo "TEST 9: pinProgrammeTime — pin then unpin\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);
$p2 = createProg($network, $channel, $testDate, '01:00', 3600, 1);

$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

// Pin P2 to 14:00
$result = $builder->pinProgrammeTime($p2->id, '14:00', $testDate, 'UTC');

assert_true($result['success'], 'pinProgrammeTime returns success');

$p2->refresh();
assert_true($p2->pinned_start_time !== null, 'P2 has pinned_start_time');
assert_eq($p2->start_time->format('H:i'), '14:00', 'P2 pinned to 14:00');

// P1 unchanged
$p1->refresh();
assert_eq($p1->start_time->format('H:i'), '00:00', 'P1 unchanged at 00:00');

// Unpin P2
$result = $builder->pinProgrammeTime($p2->id, null, $testDate, 'UTC');

$p2->refresh();
assert_true($p2->pinned_start_time === null, 'P2 unpinned');
assert_eq($p2->start_time->format('H:i'), '01:00', 'P2 flows sequentially at 01:00');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 10: pinProgrammeTime with timezone
// ══════════════════════════════════════════════════════════════════════
echo "TEST 10: pinProgrammeTime — timezone conversion\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

// Create programme at 05:00 UTC (midnight Eastern on Jan 15 = 05:00 UTC)
$p1 = NetworkProgramme::create([
    'network_id' => $network->id,
    'contentable_type' => Channel::class,
    'contentable_id' => $channel->id,
    'title' => 'Tz Test',
    'start_time' => Carbon::parse("{$testDate} 05:00:00", 'UTC'),
    'end_time' => Carbon::parse("{$testDate} 06:00:00", 'UTC'),
    'duration_seconds' => 3600,
    'sort_order' => 0,
]);

// Pin to 15:00 Eastern (should be 20:00 UTC)
$result = $builder->pinProgrammeTime($p1->id, '15:00', $testDate, 'America/New_York');

$p1->refresh();
assert_eq($p1->pinned_start_time->format('H:i'), '20:00', 'Pin stored as 20:00 UTC');
assert_eq($p1->start_time->format('H:i'), '20:00', 'Start time is 20:00 UTC');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 11: clearDay
// ══════════════════════════════════════════════════════════════════════
echo "TEST 11: clearDay\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

createProg($network, $channel, $testDate, '00:00', 3600, 0);
createProg($network, $channel, $testDate, '01:00', 1800, 1);

$result = $builder->clearDay($testDate, 'UTC');

assert_true($result['success'], 'clearDay returns success');
assert_eq($result['removed'], 2, 'Removed 2 programmes');

$remaining = NetworkProgramme::where('network_id', $network->id)
    ->where('start_time', '>=', Carbon::parse("{$testDate} 00:00", 'UTC'))
    ->where('start_time', '<', Carbon::parse("{$testDate} 23:59:59", 'UTC'))
    ->count();

assert_eq($remaining, 0, 'No programmes remain');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 12: copyDaySchedule
// ══════════════════════════════════════════════════════════════════════
echo "TEST 12: copyDaySchedule — copies programmes with sort_order and pins\n";
$sourceDate = '2030-01-15';
$targetDate = '2030-01-16';
cleanTestDay($network, $sourceDate);
cleanTestDay($network, $targetDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $sourceDate, '00:00', 3600, 0);
$p2 = createProg($network, $channel, $sourceDate, '01:00', 3600, 1, '01:00');

$ref->invoke($builder, $network, $sourceDate, new DateTimeZone('UTC'));

$result = $builder->copyDaySchedule($sourceDate, $targetDate, 'UTC');

assert_true($result['success'], 'copyDaySchedule returns success');
assert_eq($result['copied'], 2, 'Copied 2 programmes');

$targetProgs = NetworkProgramme::where('network_id', $network->id)
    ->where('start_time', '>=', Carbon::parse("{$targetDate} 00:00", 'UTC'))
    ->where('start_time', '<', Carbon::parse("{$targetDate} 23:59:59", 'UTC'))
    ->orderBy('sort_order')
    ->get();

assert_eq($targetProgs->count(), 2, 'Target has 2 programmes');
assert_eq($targetProgs[0]->sort_order, 0, 'Copy #1 sort_order=0');
assert_eq($targetProgs[0]->start_time->format('Y-m-d H:i'), '2030-01-16 00:00', 'Copy #1 start shifted');
assert_eq($targetProgs[1]->sort_order, 1, 'Copy #2 sort_order=1');
assert_true($targetProgs[1]->pinned_start_time !== null, 'Copy #2 pin preserved');
assert_eq($targetProgs[1]->pinned_start_time->format('Y-m-d H:i'), '2030-01-16 01:00', 'Copy #2 pin shifted');

// Clean up target
cleanTestDay($network, $targetDate);
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 13: getScheduleForDate — returns formatted response
// ══════════════════════════════════════════════════════════════════════
echo "TEST 13: getScheduleForDate — returns formatted response\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 0]);

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);
$p2 = createProg($network, $channel, $testDate, '01:00', 1800, 1, '01:00');

$ref->invoke($builder, $network, $testDate, new DateTimeZone('UTC'));

$schedule = $builder->getScheduleForDate($testDate, 'UTC');

assert_eq(count($schedule), 2, 'Returns 2 programmes');
assert_eq($schedule[0]['sort_order'], 0, 'First has sort_order=0');
assert_eq($schedule[0]['start_hour'], 0, 'First starts at hour 0');
assert_eq($schedule[0]['end_hour'], 1, 'First ends at hour 1');
assert_eq($schedule[1]['is_pinned'], true, 'Second is pinned');
assert_eq($schedule[1]['pinned_start_time'], '01:00', 'Second pinned at 01:00');
echo "\n";

// ══════════════════════════════════════════════════════════════════════
// TEST 14: Reorder with gap
// ══════════════════════════════════════════════════════════════════════
echo "TEST 14: reorderProgrammes with gap_seconds=600\n";
cleanTestDay($network, $testDate);
$network->update(['schedule_gap_seconds' => 600]); // 10 min gap

$p1 = createProg($network, $channel, $testDate, '00:00', 3600, 0);  // 1hr
$p2 = createProg($network, $channel, $testDate, '01:10', 1800, 1);  // 30min

$result = $builder->reorderProgrammes([$p2->id, $p1->id], $testDate, 'UTC');

$p1->refresh();
$p2->refresh();

// P2 first: 00:00-00:30, P1 second: 00:30 + 10min = 00:40-01:40
assert_eq($p2->start_time->format('H:i'), '00:00', 'P2 starts at 00:00');
assert_eq($p2->end_time->format('H:i'), '00:30', 'P2 ends at 00:30');
assert_eq($p1->start_time->format('H:i'), '00:40', 'P1 starts at 00:40 (+ 10min gap)');
assert_eq($p1->end_time->format('H:i'), '01:40', 'P1 ends at 01:40');
echo "\n";

// ── Cleanup ───────────────────────────────────────────────────────────

cleanTestDay($network, $testDate);
cleanTestDay($network, '2030-01-16');
$network->update(['schedule_gap_seconds' => $originalGap]);

echo "═══════════════════════════════════════════════\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "═══════════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
