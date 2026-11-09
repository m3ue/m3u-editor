<?php

/**
 * Issue #1738: Network::needsScheduleRegeneration() must leave a schedule alone while
 * its last programme ends more than 24 hours ahead. Two independent bugs made it
 * regenerate anyway: chaining latest('end_time') onto the already-ordered programmes()
 * relation appended a second order-by instead of replacing it (selecting the
 * earliest-starting programme, not the actual last-ending one), and the diffInHours()
 * call direction could return a negative hour count that's always < 24.
 */

use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00'));
});

function makeAutoRegenNetwork(): Network
{
    return Network::factory()->create([
        'schedule_type' => 'sequential',
        'auto_regenerate_schedule' => true,
        'schedule_generated_at' => now(),
    ]);
}

it('does not need regeneration when the last of several future programmes ends well beyond 24 hours', function () {
    $network = makeAutoRegenNetwork();

    // 72 sequential 1-hour programmes starting now: the earliest ends in 1 hour,
    // the actual last one ends 72 hours ahead - well past the 24-hour horizon.
    $start = now()->copy();
    for ($i = 0; $i < 72; $i++) {
        NetworkProgramme::factory()->for($network)->create([
            'start_time' => $start->copy()->addHours($i),
            'end_time' => $start->copy()->addHours($i + 1),
        ]);
    }

    expect($network->needsScheduleRegeneration())->toBeFalse();
});

it('needs regeneration when a single future programme ends well beyond 24 hours but diffInHours direction is mishandled', function () {
    $network = makeAutoRegenNetwork();

    NetworkProgramme::factory()->for($network)->create([
        'start_time' => now()->copy()->addHours(90),
        'end_time' => now()->copy()->addHours(95)->addMinutes(45),
    ]);

    expect($network->needsScheduleRegeneration())->toBeFalse();
});

it('needs regeneration when the last programme ends within the next 24 hours', function () {
    $network = makeAutoRegenNetwork();

    NetworkProgramme::factory()->for($network)->create([
        'start_time' => now()->copy()->subHour(),
        'end_time' => now()->copy()->addHours(2),
    ]);

    expect($network->needsScheduleRegeneration())->toBeTrue();
});

it('needs regeneration when the last programme has already ended', function () {
    $network = makeAutoRegenNetwork();

    NetworkProgramme::factory()->for($network)->create([
        'start_time' => now()->copy()->subHours(3),
        'end_time' => now()->copy()->subHour(),
    ]);

    expect($network->needsScheduleRegeneration())->toBeTrue();
});
