<?php

use App\Models\Network;
use App\Models\User;

test('invalid schedule_type is normalized to sequential on create', function () {
    $network = Network::factory()->for(User::factory())->create([
        'schedule_type' => 'weighted',
    ]);

    expect($network->schedule_type)->toBe('sequential');
});

test('valid schedule_type values are left untouched on create', function (string $scheduleType) {
    $network = Network::factory()->for(User::factory())->create([
        'schedule_type' => $scheduleType,
    ]);

    expect($network->schedule_type)->toBe($scheduleType);
})->with(['sequential', 'shuffle', 'manual']);
