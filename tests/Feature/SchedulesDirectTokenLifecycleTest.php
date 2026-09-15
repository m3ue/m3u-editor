<?php

use App\Enums\EpgSourceType;
use App\Exceptions\SchedulesDirectRateLimitException;
use App\Exceptions\SchedulesDirectTokenExpiredException;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
});

function sdEpg(array $overrides = []): Epg
{
    return Epg::factory()->create(array_merge([
        'user_id' => User::factory()->create()->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'person@example.com',
        'sd_password' => 'super-secret-password',
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_days_to_import' => 1,
    ], $overrides));
}

function fakeLineupSyncEndpoints(array $extra = []): void
{
    Http::fake(array_merge([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [['stationID' => '12345', 'channel' => '1.1']],
            'stations' => [['stationID' => '12345', 'name' => 'Test Channel', 'callsign' => 'TEST']],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            ['stationID' => '12345', 'programs' => []],
        ]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ], $extra));
}

it('persists token expiry from tokenExpires, not datetime', function () {
    $realExpiry = now()->addDay()->startOfSecond();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0,
            'token' => 'fresh-token',
            'datetime' => now()->toIso8601String(),      // server clock: must be ignored
            'tokenExpires' => $realExpiry->timestamp,     // canonical expiry
            'serverTime' => now()->timestamp,
        ]),
    ]);

    $epg = sdEpg();

    $result = (new SchedulesDirectService)->authenticateFromEpg($epg);

    // Stored expiry tracks tokenExpires (minus a small skew), never "now".
    expect($epg->fresh()->sd_token_expires_at->timestamp)
        ->toBeLessThanOrEqual($realExpiry->timestamp)
        ->toBeGreaterThan(now()->addHours(23)->timestamp);

    expect($result['expires'])->toBeGreaterThan(now()->addHours(23)->timestamp);
    expect($epg->fresh()->hasValidSchedulesDirectToken())->toBeTrue();
});

it('falls back to a 24 hour lifetime when tokenExpires is absent', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0,
            'token' => 'fresh-token',
            'datetime' => now()->toIso8601String(),
        ]),
    ]);

    $epg = sdEpg();

    (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($epg->fresh()->sd_token_expires_at->timestamp)
        ->toBeGreaterThan(now()->addHours(23)->timestamp)
        ->toBeLessThanOrEqual(now()->addDay()->timestamp);
});

it('reuses a valid stored token and issues zero /token requests during a sync', function () {
    Storage::fake('local');
    fakeLineupSyncEndpoints();

    $epg = sdEpg([
        'sd_token' => 'still-good',
        'sd_token_expires_at' => now()->addHours(6),
    ]);

    (new SchedulesDirectService)->syncEpgData($epg);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/20141201/token'));
});

it('is single-flight per account: a second authentication reuses the token another call just stored', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0,
            'token' => 'token-one',
            'tokenExpires' => now()->addDay()->timestamp,
        ]),
    ]);

    $epg = sdEpg();
    $service = new SchedulesDirectService;

    $service->authenticateFromEpg($epg);
    $service->authenticateFromEpg($epg->fresh());

    // The recheck-under-lock short-circuit means only the first call logged in.
    Http::assertSentCount(1);
});

it('enters a bounded 24 hour cooldown on TOO_MANY_LOGINS (4009)', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'response' => 'TOO_MANY_LOGINS',
            'code' => 4009,
            'message' => 'Exceeded maximum number of logins in 24 hours.',
        ], 429),
    ]);

    $epg = sdEpg();

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectRateLimitException::class);

    $cooldown = $epg->fresh()->sd_login_cooldown_until;
    expect($cooldown)->not->toBeNull();
    expect($cooldown->timestamp)
        ->toBeGreaterThan(now()->addHours(23)->timestamp)
        ->toBeLessThanOrEqual(now()->addHours(24)->addMinute()->timestamp);
});

it('suppresses /token requests while a 4009 cooldown is active without moving the window', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'x', 'tokenExpires' => now()->addDay()->timestamp]),
    ]);

    $frozenCooldown = now()->addHours(20);
    $epg = sdEpg(['sd_login_cooldown_until' => $frozenCooldown]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectRateLimitException::class);

    // No login attempt was made, and the cooldown window did not advance.
    Http::assertNothingSent();
    expect($epg->fresh()->sd_login_cooldown_until->timestamp)->toBe($frozenCooldown->timestamp);
});

it('clears the cooldown after a later successful authentication', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0,
            'token' => 'recovered-token',
            'tokenExpires' => now()->addDay()->timestamp,
        ]),
    ]);

    // Cooldown already elapsed.
    $epg = sdEpg(['sd_login_cooldown_until' => now()->subMinute()]);

    (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($epg->fresh()->sd_login_cooldown_until)->toBeNull();
    expect($epg->fresh()->sd_token)->toBe('recovered-token');
});

it('recovers from a mid-sync TOKEN_EXPIRED (4006) with exactly one re-authentication', function () {
    Storage::fake('local');

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0,
            'token' => 'reissued-token',
            'tokenExpires' => now()->addDay()->timestamp,
        ]),
        'json.schedulesdirect.org/20141201/lineups/*' => Http::sequence()
            ->push(['code' => 4006, 'message' => 'Token has expired.'], 200)
            ->push([
                'map' => [['stationID' => '12345', 'channel' => '1.1']],
                'stations' => [['stationID' => '12345', 'name' => 'Test Channel', 'callsign' => 'TEST']],
            ], 200),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([['stationID' => '12345', 'programs' => []]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    $epg = sdEpg([
        'sd_token' => 'stale-token',
        'sd_token_expires_at' => now()->addHours(6),
    ]);

    (new SchedulesDirectService)->syncEpgData($epg);

    // Re-authenticated once against /token, then the sync completed.
    expect($epg->fresh()->sd_token)->toBe('reissued-token');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/20141201/token'));
    $tokenCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/20141201/token'))
        ->count();
    expect($tokenCalls)->toBe(1);
});

it('clears the stored token and raises a typed exception on 4006', function () {
    Storage::fake('local');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 4006, 'message' => 'Token has expired.'], 200),
    ]);

    $epg = sdEpg([
        'sd_token' => 'stale-token',
        'sd_token_expires_at' => now()->addHours(6),
    ]);

    $service = new SchedulesDirectService;
    $service->setCurrentEpg($epg);

    expect(fn () => $service->getLineup($epg->sd_token, $epg->sd_lineup_id))
        ->toThrow(SchedulesDirectTokenExpiredException::class);

    expect($epg->fresh()->sd_token)->toBeNull();
    expect($epg->fresh()->sd_token_expires_at)->toBeNull();
});

it('does not schedule the 60/120/180s resync when the sync hits the SD login limit', function () {
    Storage::fake('local');

    $epg = sdEpg([
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
        'resync_attempt' => 0,
    ]);

    // Fake the queue only after factory creation, so the EpgCreated-triggered
    // import is not what we are asserting against.
    Queue::fake();

    $this->mock(SchedulesDirectService::class, function ($mock) {
        $mock->shouldReceive('syncEpgData')
            ->once()
            ->andThrow(new SchedulesDirectRateLimitException(now()->addHours(24)));
    });

    (new ProcessEpgImport($epg, force: true))->handle(app(SchedulesDirectService::class));

    $fresh = $epg->fresh();
    expect($fresh->status->value)->toBe('failed');
    expect($fresh->resync_attempt)->toBe(0);            // no linear-backoff retry chain
    Queue::assertNotPushed(ProcessEpgImport::class);    // no self-redispatch

    // The user-facing error carries a retry time but no credentials or token.
    expect($fresh->errors)->not->toContain('super-secret-password');
    expect($fresh->errors)->toContain('login limit');
});

it('reuses a sibling EPG token for the same provider account instead of logging in', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0, 'token' => 'should-not-be-requested', 'tokenExpires' => now()->addDay()->timestamp,
        ]),
    ]);

    $userId = User::factory()->create()->id;
    $withToken = sdEpg([
        'user_id' => $userId,
        'sd_token' => 'shared-account-token',
        'sd_token_expires_at' => now()->addHours(20),
    ]);
    $withoutToken = sdEpg(['user_id' => $userId]);

    $result = (new SchedulesDirectService)->authenticateFromEpg($withoutToken);

    Http::assertNothingSent();
    expect($result['token'])->toBe('shared-account-token');
    expect($withoutToken->fresh()->sd_token)->toBe('shared-account-token');
});

it('does not borrow a token across owners even when the SD username matches', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0, 'token' => 'freshly-issued', 'tokenExpires' => now()->addDay()->timestamp,
        ]),
    ]);

    sdEpg([
        'user_id' => User::factory()->create()->id,
        'sd_token' => 'other-owner-token',
        'sd_token_expires_at' => now()->addHours(20),
    ]);
    $mine = sdEpg(['user_id' => User::factory()->create()->id]);

    $result = (new SchedulesDirectService)->authenticateFromEpg($mine);

    Http::assertSentCount(1);
    expect($result['token'])->toBe('freshly-issued');
});

it('fences every EPG on the provider account when one trips the 4009 login limit', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'response' => 'TOO_MANY_LOGINS', 'code' => 4009, 'message' => 'Exceeded maximum number of logins in 24 hours.',
        ], 429),
    ]);

    $userId = User::factory()->create()->id;
    $first = sdEpg(['user_id' => $userId]);
    $second = sdEpg(['user_id' => $userId]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($first))
        ->toThrow(SchedulesDirectRateLimitException::class);

    // The second EPG is now in cooldown without ever calling /token itself.
    expect($second->fresh()->sd_login_cooldown_until)->not->toBeNull();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'x', 'tokenExpires' => now()->addDay()->timestamp]),
    ]);
    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($second))
        ->toThrow(SchedulesDirectRateLimitException::class);
    Http::assertNothingSent();
});

it('clears the stale cooldown from sibling EPGs after a later successful login', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0, 'token' => 'recovered', 'tokenExpires' => now()->addDay()->timestamp,
        ]),
    ]);

    $userId = User::factory()->create()->id;
    // The account cooldown has elapsed on every row; the timestamps are just stale.
    $recovering = sdEpg(['user_id' => $userId, 'sd_login_cooldown_until' => now()->subMinutes(5)]);
    $sibling = sdEpg(['user_id' => $userId, 'sd_login_cooldown_until' => now()->subMinute()]);

    (new SchedulesDirectService)->authenticateFromEpg($recovering);

    expect($recovering->fresh()->sd_login_cooldown_until)->toBeNull();
    expect($sibling->fresh()->sd_login_cooldown_until)->toBeNull();
});

it('retries a retryable programs subchunk failure once', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/programs' => Http::sequence()
            ->push(['code' => 6001, 'message' => 'Temporary failure'], 503)
            ->push([], 200),
    ]);

    $responseFile = tempnam(sys_get_temp_dir(), 'sd-test-');
    $method = new ReflectionMethod(SchedulesDirectService::class, 'fetchProgramBatch');
    $response = $method->invoke(new SchedulesDirectService, 'token', [['programID' => 'SH0000000001']], $responseFile);

    expect($response->status())->toBe(200);
    expect(Http::recorded())->toHaveCount(2);
    unlink($responseFile);
});

it('does not retry a permanent programs subchunk failure', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/programs' => Http::response(['code' => 6000, 'message' => 'Permanent failure'], 400),
    ]);

    $responseFile = tempnam(sys_get_temp_dir(), 'sd-test-');
    $method = new ReflectionMethod(SchedulesDirectService::class, 'fetchProgramBatch');
    $response = $method->invoke(new SchedulesDirectService, 'token', [['programID' => 'SH0000000001']], $responseFile);

    expect($response->status())->toBe(400);
    expect(Http::recorded())->toHaveCount(1);
    unlink($responseFile);
});
