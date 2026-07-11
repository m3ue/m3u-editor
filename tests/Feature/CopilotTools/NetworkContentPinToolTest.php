<?php

use App\Filament\CopilotTools\NetworkContentPinTool;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

function makePinTool(): NetworkContentPinTool
{
    return new NetworkContentPinTool;
}

beforeEach(function () {
    Event::fake();
    Queue::fake();
});

it('pins content to a day and time and returns a success message', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create(['name' => 'My Network']);
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]));

    expect($result)->toContain('Pinned')
        ->toContain('Fridays at 20:00')
        ->toContain('My Network');

    expect($nc->fresh()->pin_day_of_week)->toBe('friday')
        ->and($nc->fresh()->pin_time_of_day)->toBe('20:00');
});

it('clears a pin when day and time are omitted and returns a success message', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
    ]));

    expect($result)->toContain('Cleared pin')
        ->and($result)->not->toContain('regenerated');

    expect($nc->fresh()->pin_day_of_week)->toBeNull()
        ->and($nc->fresh()->pin_time_of_day)->toBeNull();
});

it('clears a pin when day and time are passed as null', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
        'pin_day_of_week' => 'monday',
        'pin_time_of_day' => '09:00',
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => null,
        'pin_time_of_day' => null,
    ]));

    expect($result)->toContain('Cleared pin');
    expect($nc->fresh()->pin_day_of_week)->toBeNull();
});

it('rejects an invalid day of week', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'funday',
        'pin_time_of_day' => '20:00',
    ]));

    expect($result)->toContain('Error')
        ->toContain('pin_day_of_week')
        ->and($nc->fresh()->pin_day_of_week)->toBeNull();
});

it('rejects an invalid time format', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '8pm',
    ]));

    expect($result)->toContain('Error')
        ->toContain('HH:MM')
        ->and($nc->fresh()->pin_day_of_week)->toBeNull();
});

it('rejects a pin with a day but no time', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'friday',
    ]));

    expect($result)->toContain('Error')
        ->toContain('pin_time_of_day is required');
});

it('rejects a missing network_content_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([]));

    expect($result)->toContain('Error')
        ->toContain('network_content_id is required');
});

it('rejects a non-existent network_content_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => 99999,
    ]));

    expect($result)->toContain('Error')
        ->toContain('not found');
});

it('rejects a network_content_id belonging to another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $network = Network::factory()->for($userB)->create();
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($userA);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]));

    expect($result)->toContain('Error')
        ->toContain('not found or you do not have access');

    expect($nc->fresh()->pin_day_of_week)->toBeNull();
});

it('mentions automatic regeneration when auto_regenerate_schedule is enabled', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create(['auto_regenerate_schedule' => true]);
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]));

    expect($result)->toContain('regenerated automatically');
});

it('mentions manual regeneration when auto_regenerate_schedule is disabled', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create(['auto_regenerate_schedule' => false]);
    $channel = Channel::factory()->create();
    $nc = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    $this->actingAs($user);

    $result = (string) makePinTool()->handle(new Request([
        'network_content_id' => $nc->id,
        'pin_day_of_week' => 'friday',
        'pin_time_of_day' => '20:00',
    ]));

    expect($result)->toContain('manual generation')
        ->and($result)->not->toContain('automatically');
});
