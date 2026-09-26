<?php

use App\Enums\EpgSourceType;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('routes SchedulesDirect EPG syncs onto the dedicated single-worker queue', function () {
    Queue::fake();

    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'shared@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
    ]);

    dispatch(new ProcessEpgImport($epg, force: true));

    Queue::assertPushedOn('schedules-direct', ProcessEpgImport::class);
});

it('does not route non-SchedulesDirect EPG syncs onto the SD-only queue', function () {
    Queue::fake();

    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'source_type' => EpgSourceType::URL,
        'url' => 'https://example.com/epg.xml',
    ]);

    dispatch(new ProcessEpgImport($epg, force: true));

    Queue::assertPushed(ProcessEpgImport::class, function (ProcessEpgImport $job) {
        return $job->queue !== 'schedules-direct';
    });
});
