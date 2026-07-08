<?php

use App\Jobs\RegenerateNetworkSchedule;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use App\Services\NetworkScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/**
 * Helper: create a Channel with a known duration.
 */
function makePinChannel(int $durationSeconds = 3600): Channel
{
    return Channel::factory()->create([
        'is_vod' => true,
        'info' => ['duration_secs' => $durationSeconds],
    ]);
}

/**
 * Helper: add a channel to a network as NetworkContent.
 *
 * @param  array<string, mixed>  $attrs
 */
function addPinContent(Network $network, Channel $channel, array $attrs = []): NetworkContent
{
    return NetworkContent::withoutEvents(function () use ($network, $channel, $attrs) {
        return NetworkContent::create(array_merge([
            'network_id' => $network->id,
            'contentable_type' => Channel::class,
            'contentable_id' => $channel->id,
            'sort_order' => 1,
            'weight' => 1,
        ], $attrs));
    });
}

it('places a pinned item at the correct day and time', function () {
    // Monday 2026-01-05 08:00 UTC
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'schedule_window_days' => 7,
        'auto_regenerate_schedule' => false,
    ]);

    // Filler content (unpinned)
    $filler = makePinChannel(1800); // 30 min
    addPinContent($network, $filler, ['sort_order' => 1]);

    // Movie pinned to Friday at 20:00
    $movie = makePinChannel(7200); // 2 hours
    addPinContent($network, $movie, [
        'sort_order' => 2,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]);

    app(NetworkScheduleService::class)->generateSchedule($network);

    // Friday 2026-01-09 20:00
    $friday8pm = Carbon::parse('2026-01-09 20:00:00');

    $pinnedProgramme = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $movie->id)
        ->first();

    expect($pinnedProgramme)->not->toBeNull()
        ->and($pinnedProgramme->start_time->toDateTimeString())->toBe($friday8pm->toDateTimeString())
        ->and($pinnedProgramme->pinned_start_time)->not->toBeNull()
        ->and($pinnedProgramme->end_time->toDateTimeString())->toBe($friday8pm->copy()->addSeconds(7200)->toDateTimeString());
});

it('excludes pinned items from normal rotation', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'schedule_window_days' => 7,
        'auto_regenerate_schedule' => false,
    ]);

    $filler = makePinChannel(1800);
    addPinContent($network, $filler, ['sort_order' => 1]);

    $pinned = makePinChannel(3600);
    addPinContent($network, $pinned, [
        'sort_order' => 2,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]);

    app(NetworkScheduleService::class)->generateSchedule($network);

    // The pinned movie should appear exactly once (its pinned slot)
    $pinnedOccurrences = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $pinned->id)
        ->count();

    expect($pinnedOccurrences)->toBe(1);

    // The filler should fill the rest (not pinned to a fixed time)
    $fillerOccurrences = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $filler->id)
        ->count();

    expect($fillerOccurrences)->toBeGreaterThan(0);
});

it('leaves a gap when filler does not fit before a pinned slot', function () {
    // Wednesday 2026-01-07 20:00 — pin is Friday 20:00 (2 days away)
    Carbon::setTestNow(Carbon::parse('2026-01-07 20:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'schedule_window_days' => 7,
        'auto_regenerate_schedule' => false,
    ]);

    // Filler is a 90-minute item
    $filler = makePinChannel(5400); // 90 min
    addPinContent($network, $filler, ['sort_order' => 1]);

    // Movie pinned to Friday at 20:00
    $movie = makePinChannel(7200);
    addPinContent($network, $movie, [
        'sort_order' => 2,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]);

    app(NetworkScheduleService::class)->generateSchedule($network);

    // Confirm no filler programme overlaps the pinned start (2026-01-09 20:00)
    $friday8pm = Carbon::parse('2026-01-09 20:00:00');

    $overlap = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $filler->id)
        ->where('start_time', '<', $friday8pm)
        ->where('end_time', '>', $friday8pm)
        ->count();

    expect($overlap)->toBe(0);

    // Pinned programme is still in place
    $pinnedProgramme = NetworkProgramme::where('network_id', $network->id)
        ->whereNotNull('pinned_start_time')
        ->first();

    expect($pinnedProgramme)->not->toBeNull()
        ->and($pinnedProgramme->start_time->toDateTimeString())->toBe($friday8pm->toDateTimeString());
});

it('skips a colliding pinned item when two share the same slot', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'schedule_window_days' => 7,
        'auto_regenerate_schedule' => false,
    ]);

    $filler = makePinChannel(1800);
    addPinContent($network, $filler, ['sort_order' => 1]);

    // Two items both pinned to Friday 20:00 — sort_order 2 wins, sort_order 3 is skipped
    $winner = makePinChannel(7200);
    addPinContent($network, $winner, [
        'sort_order' => 2,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]);

    $loser = makePinChannel(3600);
    addPinContent($network, $loser, [
        'sort_order' => 3,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]);

    app(NetworkScheduleService::class)->generateSchedule($network);

    $friday8pm = Carbon::parse('2026-01-09 20:00:00');

    // Winner placed at pin time
    $winnerProgramme = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $winner->id)
        ->whereNotNull('pinned_start_time')
        ->first();
    expect($winnerProgramme)->not->toBeNull()
        ->and($winnerProgramme->start_time->toDateTimeString())->toBe($friday8pm->toDateTimeString());

    // Loser not placed at Friday 20:00
    $loserAtPin = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $loser->id)
        ->where('start_time', $friday8pm)
        ->first();
    expect($loserAtPin)->toBeNull();
});

it('sets pinned_start_time on the programme record', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));

    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'schedule_window_days' => 7,
        'auto_regenerate_schedule' => false,
    ]);

    $filler = makePinChannel(1800);
    addPinContent($network, $filler, ['sort_order' => 1]);

    $movie = makePinChannel(3600);
    addPinContent($network, $movie, [
        'sort_order' => 2,
        'pin_day_of_week' => 'saturday',
        'pin_time_of_day' => '15:30',
    ]);

    app(NetworkScheduleService::class)->generateSchedule($network);

    $programme = NetworkProgramme::where('network_id', $network->id)
        ->where('contentable_id', $movie->id)
        ->first();

    expect($programme)->not->toBeNull()
        ->and($programme->pinned_start_time)->not->toBeNull();
});

it('regenerates schedule when pin fields are updated', function () {
    Queue::fake();

    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $channel = makePinChannel(3600);
    $nc = NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Queue::assertPushed(RegenerateNetworkSchedule::class);
    Queue::fake(); // Reset

    // Updating sort_order should NOT trigger regen (pin fields not changed)
    $nc->update(['sort_order' => 2]);
    Queue::assertNotPushed(RegenerateNetworkSchedule::class);

    // Updating a pin field SHOULD trigger regen
    $nc->update(['pin_day_of_week' => 'friday', 'pin_time_of_day' => '20:00']);
    Queue::assertPushed(RegenerateNetworkSchedule::class);
});

it('isPinned returns true only when both fields are set', function () {
    $nc = new NetworkContent([
        'pin_day_of_week' => null,
        'pin_time_of_day' => null,
    ]);
    expect($nc->isPinned())->toBeFalse();

    $nc->pin_day_of_week = 'friday';
    expect($nc->isPinned())->toBeFalse();

    $nc->pin_time_of_day = '20:00';
    expect($nc->isPinned())->toBeTrue();
});
