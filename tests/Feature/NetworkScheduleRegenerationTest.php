<?php

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use App\Services\NetworkScheduleService;
use Carbon\Carbon;

it('preserves a programme that starts exactly at regeneration boundary and avoids duplicates', function () {
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:00:00'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();
    $c2 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
        'sort_order' => 2,
        'weight' => 1,
    ]);

    // Create programme A 10:00-11:00 and programme B 11:00-12:00
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'A',
        'start_time' => $now->copy()->subHour(),
        'end_time' => $now->copy(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'B',
        'start_time' => $now->copy(),
        'end_time' => $now->copy()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
    ]);

    expect($network->programmes()->count())->toBe(2);

    $service = app(NetworkScheduleService::class);

    $service->generateSchedule($network);

    // Assert B still exists and no duplicate starts at now
    $programmesAtNow = $network->programmes()->where('start_time', $now)->get();
    expect($programmesAtNow->count())->toBe(1);
    expect($programmesAtNow->first()->contentable_id)->toBe($c2->id);
});

it('handles timestamp precision with microseconds at boundary', function () {
    // Test that programmes with microseconds in timestamps are properly detected
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:00:00.500000'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    // Create a programme that starts with microseconds
    $programmeStartTime = $now->copy();
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Programme',
        'start_time' => $programmeStartTime,
        'end_time' => $programmeStartTime->copy()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
    ]);

    $service = app(NetworkScheduleService::class);

    // Regenerate using a slightly different timestamp (microseconds stripped)
    $regenerateTime = Carbon::parse('2026-01-21 11:00:00');
    $service->generateSchedule($network, $regenerateTime);

    // Check if duplicate was created due to microsecond mismatch
    $programmesAtBoundary = $network->programmes()
        ->whereBetween('start_time', [
            $regenerateTime->copy()->subSecond(),
            $regenerateTime->copy()->addSecond(),
        ])
        ->get();

    expect($programmesAtBoundary->count())->toBeLessThanOrEqual(1)
        ->and($programmesAtBoundary->first()->title)->toBe('Test Programme');
});

it('handles regeneration when boundary timestamp differs by milliseconds', function () {
    // Real-world scenario: scheduled job runs at 11:00:00.001 but programme starts at 11:00:00.000
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:00:00.000000'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();
    $c2 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
        'sort_order' => 2,
        'weight' => 1,
    ]);

    // Programme starts at exact second
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Boundary Programme',
        'start_time' => $now->copy(),
        'end_time' => $now->copy()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
    ]);

    $service = app(NetworkScheduleService::class);

    // Regenerate 1ms later (simulating cron delay)
    $regenerateTime = Carbon::parse('2026-01-21 11:00:00.001000');
    $service->generateSchedule($network, $regenerateTime);

    // Should not create duplicate - the existing programme should be preserved
    $allProgrammes = $network->programmes()
        ->where('start_time', '>=', $now->copy()->subMinute())
        ->where('start_time', '<=', $now->copy()->addMinute())
        ->get();

    expect($allProgrammes->count())->toBeLessThanOrEqual(2)
        ->and($allProgrammes->where('title', 'Boundary Programme')->count())->toBe(1);
});

it('handles database timestamp truncation correctly', function () {
    // MySQL DATETIME truncates to seconds, losing microseconds
    // This tests that comparison works despite precision loss
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:00:00'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    // Create programme with precise timestamp
    $preciseTime = Carbon::parse('2026-01-21 11:00:00.999999');
    $programme = NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Programme',
        'start_time' => $preciseTime,
        'end_time' => $preciseTime->copy()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
    ]);

    // Refresh from database to get actual stored value (truncated)
    $programme->refresh();
    $storedTime = $programme->start_time;

    $service = app(NetworkScheduleService::class);

    // Regenerate using the truncated time
    $service->generateSchedule($network, $storedTime);

    // Verify no duplicate was created
    $programmesCount = $network->programmes()
        ->where('start_time', '>=', $now->copy()->subMinute())
        ->where('start_time', '<=', $now->copy()->addMinute())
        ->count();

    expect($programmesCount)->toBeLessThanOrEqual(2); // Original + 1 future programme max
});

it('preserves currently airing programme when regenerating mid-broadcast', function () {
    // Programme is currently airing (started before now, ends after now)
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:30:00'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();
    $c2 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
        'sort_order' => 2,
        'weight' => 1,
    ]);

    // Programme started at 11:00, ends at 12:00 (currently 11:30)
    $airingProgramme = NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Currently Airing',
        'start_time' => $now->copy()->subMinutes(30),
        'end_time' => $now->copy()->addMinutes(30),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
    ]);

    $service = app(NetworkScheduleService::class);
    $service->generateSchedule($network);

    // Verify the currently airing programme was NOT deleted
    $airingProgramme->refresh();
    expect($airingProgramme->exists)->toBeTrue()
        ->and($airingProgramme->title)->toBe('Currently Airing')
        ->and($airingProgramme->start_time->format('Y-m-d H:i:s'))->toBe('2026-01-21 11:00:00');
});

it('continues content sequence correctly after boundary programme', function () {
    Carbon::setTestNow($now = Carbon::parse('2026-01-21 11:00:00'));

    $network = Network::factory()->create([
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $c1 = Channel::factory()->create();
    $c2 = Channel::factory()->create();
    $c3 = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c1),
        'contentable_id' => $c1->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
        'sort_order' => 2,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($c3),
        'contentable_id' => $c3->id,
        'sort_order' => 3,
        'weight' => 1,
    ]);

    // c2 is at the boundary
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Content 2',
        'start_time' => $now->copy(),
        'end_time' => $now->copy()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($c2),
        'contentable_id' => $c2->id,
    ]);

    $service = app(NetworkScheduleService::class);
    $service->generateSchedule($network);

    // Next programme should be c3 (content continues from c2)
    $nextProgramme = $network->programmes()
        ->where('start_time', $now->copy()->addHour())
        ->first();

    expect($nextProgramme)->not->toBeNull()
        ->and($nextProgramme->contentable_id)->toBe($c3->id);
});
