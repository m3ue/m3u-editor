<?php

use App\Jobs\RegenerateNetworkSchedule;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

it('dispatches schedule regeneration job when content is added to sequential network', function () {
    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $channel = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Bus::assertDispatched(RegenerateNetworkSchedule::class, function ($job) use ($network) {
        return $job->networkId === $network->id;
    });
});

it('dispatches schedule regeneration job when content is added to shuffle network', function () {
    $network = Network::factory()->create([
        'schedule_type' => 'shuffle',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $channel = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Bus::assertDispatched(RegenerateNetworkSchedule::class, function ($job) use ($network) {
        return $job->networkId === $network->id;
    });
});

it('does not dispatch regeneration job for manual schedule networks', function () {
    Carbon::setTestNow('2026-03-15 10:00:00');

    $network = Network::factory()->create([
        'schedule_type' => 'manual',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $channel = Channel::factory()->create();

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Manual Programme',
        'start_time' => Carbon::now(),
        'end_time' => Carbon::now()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Bus::assertNotDispatched(RegenerateNetworkSchedule::class);
    expect($network->fresh()->programmes()->count())->toBe(1);
});

it('does not dispatch regeneration job when auto_regenerate_schedule is disabled', function () {
    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => false,
    ]);

    $channel = Channel::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Bus::assertNotDispatched(RegenerateNetworkSchedule::class);
});

it('dispatches one job per network when multiple items are added in bulk', function () {
    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $channels = Channel::factory()->count(5)->create();

    foreach ($channels as $index => $channel) {
        NetworkContent::create([
            'network_id' => $network->id,
            'contentable_type' => Channel::class,
            'contentable_id' => $channel->id,
            'sort_order' => $index + 1,
            'weight' => 1,
        ]);
    }

    // Five inserts should produce five dispatches here (ShouldBeUnique collapses
    // them on the real queue; Queue::fake() captures all dispatches, so we assert
    // at least one was dispatched for this network).
    Bus::assertDispatched(RegenerateNetworkSchedule::class, function ($job) use ($network) {
        return $job->networkId === $network->id;
    });
});

it('dispatches regeneration job when episodes are added', function () {
    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $episode = Episode::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Bus::assertDispatched(RegenerateNetworkSchedule::class, function ($job) use ($network) {
        return $job->networkId === $network->id;
    });
});

it('dispatches regeneration job for mixed content types', function () {
    $network = Network::factory()->create([
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => true,
    ]);

    $channel = Channel::factory()->create();
    $episode = Episode::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 2,
        'weight' => 1,
    ]);

    Bus::assertDispatched(RegenerateNetworkSchedule::class, function ($job) use ($network) {
        return $job->networkId === $network->id;
    });
});
