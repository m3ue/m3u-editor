<?php

use App\Enums\EpgSourceType;
use App\Exceptions\SchedulesDirectAuthBlockedException;
use App\Exceptions\SchedulesDirectRateLimitException;
use App\Exceptions\SchedulesDirectTokenExpiredException;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function sdEpg(array $overrides = []): Epg
{
    return Epg::factory()->create(array_merge([
        'user_id' => User::factory()->create()->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'person@example.com',
        'sd_password' => 'super-secret-password',
        'sd_lineup_ids' => ['USA-NY12345-X'],
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

it('enters a cooldown until the next UTC day on TOO_MANY_LOGINS (4009)', function () {
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
    expect($cooldown->timestamp)->toBe(now('UTC')->addDay()->startOfDay()->timestamp);
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

    expect(fn () => $service->getLineup($epg->sd_token, $epg->sd_lineup_ids[0]))
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

function fakeAccountExpired(): void
{
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4001,
            'response' => 'ACCOUNT_EXPIRED',
            'message' => 'Account expired on 2026-05-04T16:52:36Z',
            'serverID' => '20141201.web',
            'datetime' => '2026-10-20T05:42:03Z',
            'accountExpiration' => 1777913556,
            'serverTime' => 1792474923,
        ], 400),
    ]);
}

it('blocks the account on ACCOUNT_EXPIRED (4001) and never calls /token again', function () {
    fakeAccountExpired();

    $epg = sdEpg();
    $service = new SchedulesDirectService;

    expect(fn () => $service->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectAuthBlockedException::class, 'expired on May 4, 2026');

    $fresh = $epg->fresh();
    expect($fresh->sd_auth_blocked_code)->toBe(4001);
    expect($fresh->sd_auth_blocked_message)->toContain('schedulesdirect.org');

    // A later attempt (sync, image proxy, sibling worker) is refused locally.
    expect(fn () => $service->authenticateFromEpg($fresh))
        ->toThrow(SchedulesDirectAuthBlockedException::class);
    Http::assertSentCount(1);
});

it('blocks the account on each permanent /token error', function (int $code, string $response) {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => $code, 'response' => $response, 'message' => $response], 400),
    ]);

    $epg = sdEpg();

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectAuthBlockedException::class);
    expect($epg->fresh()->sd_auth_blocked_code)->toBe($code);
})->with([
    'invalid hash' => [4002, 'INVALID_HASH'],
    'invalid user' => [4003, 'INVALID_USER'],
    'json access disabled' => [4005, 'JSON_ACCOUNT_ACCESS_DISABLED'],
    'application disabled' => [4007, 'APPLICATION_DISABLED'],
    'account inactive' => [4008, 'ACCOUNT_INACTIVE'],
]);

it('only blocks the EPGs sending the same bad credentials on INVALID_USER (4003)', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 4003, 'response' => 'INVALID_USER', 'message' => 'Invalid username or password.'], 400),
    ]);

    $userId = User::factory()->create()->id;
    $mistyped = sdEpg(['user_id' => $userId, 'sd_password' => 'typo-password']);
    $sameTypo = sdEpg(['user_id' => $userId, 'sd_password' => 'typo-password']);
    $working = sdEpg(['user_id' => $userId]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($mistyped))
        ->toThrow(SchedulesDirectAuthBlockedException::class);

    expect($mistyped->fresh()->isSchedulesDirectAuthBlocked())->toBeTrue()
        ->and($sameTypo->fresh()->isSchedulesDirectAuthBlocked())->toBeTrue()
        ->and($working->fresh()->isSchedulesDirectAuthBlocked())->toBeFalse();
});

it('waits out the 15 minute ACCOUNT_LOCKOUT (4004) instead of backing off', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 4004, 'response' => 'ACCOUNT_LOCKOUT', 'message' => 'Too many login failures. Locked for 15 minutes.'], 400),
    ]);

    $epg = sdEpg();
    $service = new SchedulesDirectService;

    try {
        $service->authenticateFromEpg($epg);
        $this->fail('Expected a rate limit exception');
    } catch (SchedulesDirectRateLimitException $e) {
        expect($e->reason)->toBe(SchedulesDirectRateLimitException::REASON_ACCOUNT_LOCKOUT);
        expect((int) round(now()->diffInMinutes($e->retryAt)))->toBe(15);
    }

    $fresh = $epg->fresh();
    expect($fresh->sd_backoff_attempts)->toBe(0);

    // No further login while the account is locked.
    expect(fn () => $service->authenticateFromEpg($fresh))->toThrow(SchedulesDirectRateLimitException::class);
    Http::assertSentCount(1);
});

it('blocks the account on an unrecognised /token error code instead of retrying it', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 4999, 'response' => 'SOMETHING_NEW', 'message' => 'Something new went wrong.'], 400),
    ]);

    $userId = User::factory()->create()->id;
    $epg = sdEpg(['user_id' => $userId]);
    $sibling = sdEpg(['user_id' => $userId]);
    $service = new SchedulesDirectService;

    expect(fn () => $service->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectAuthBlockedException::class, 'Something new went wrong. (code 4999)');

    expect($epg->fresh()->sd_auth_blocked_code)->toBe(4999)
        ->and($epg->fresh()->sd_backoff_attempts)->toBe(0)
        ->and($sibling->fresh()->isSchedulesDirectAuthBlocked())->toBeTrue();

    expect(fn () => $service->authenticateFromEpg($sibling->fresh()))->toThrow(SchedulesDirectAuthBlockedException::class);
    Http::assertSentCount(1);
});

it('backs off on a /token 4xx that carries no Schedules Direct error code', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response('<html>Forbidden</html>', 403),
    ]);

    $epg = sdEpg();

    try {
        (new SchedulesDirectService)->authenticateFromEpg($epg);
        $this->fail('Expected a rate limit exception');
    } catch (SchedulesDirectRateLimitException $e) {
        expect($e->reason)->toBe(SchedulesDirectRateLimitException::REASON_SERVER_ERROR);
    }

    expect($epg->fresh()->sd_backoff_attempts)->toBe(1)
        ->and($epg->fresh()->isSchedulesDirectAuthBlocked())->toBeFalse();
});

it('blocks every EPG on the provider account, not just the one that hit the rejection', function () {
    fakeAccountExpired();

    $userId = User::factory()->create()->id;
    $first = sdEpg(['user_id' => $userId]);
    $second = sdEpg(['user_id' => $userId]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($first))
        ->toThrow(SchedulesDirectAuthBlockedException::class);

    expect($second->fresh()->isSchedulesDirectAuthBlocked())->toBeTrue();
});

it('does not schedule any resync when the login is permanently rejected', function () {
    Storage::fake('local');

    $epg = sdEpg([
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
        'resync_attempt' => 0,
    ]);

    Queue::fake();
    fakeAccountExpired();

    (new ProcessEpgImport($epg, force: true))->handle(new SchedulesDirectService);

    $fresh = $epg->fresh();
    expect($fresh->status->value)->toBe('failed');
    expect($fresh->resync_attempt)->toBe(0);
    expect($fresh->errors)->toContain('expired on May 4, 2026');
    expect($fresh->errors)->not->toContain('super-secret-password');
    Queue::assertNotPushed(ProcessEpgImport::class);
});

it('skips blocked EPGs in the scheduled refresh', function () {
    $epg = sdEpg([
        'auto_sync' => true,
        'status' => 'failed',
        'sd_auth_blocked_code' => 4001,
        'sd_auth_blocked_message' => 'expired',
    ]);
    $epg->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

    Queue::fake();
    Artisan::call('app:refresh-epg');

    Queue::assertNotPushed(ProcessEpgImport::class);
});

it('lifts the block when the credentials change', function () {
    $epg = sdEpg(['sd_auth_blocked_code' => 4003, 'sd_auth_blocked_message' => 'bad password', 'sd_backoff_attempts' => 2]);

    $epg->update(['sd_password' => 'a-new-password']);

    $fresh = $epg->fresh();
    expect($fresh->isSchedulesDirectAuthBlocked())->toBeFalse();
    expect($fresh->sd_auth_blocked_message)->toBeNull();
    // A server-error backoff is about SD, not the credentials, so it stays.
    expect($fresh->sd_backoff_attempts)->toBe(2);
});

it('keeps the block when unrelated fields change', function () {
    $epg = sdEpg(['sd_auth_blocked_code' => 4001, 'sd_auth_blocked_message' => 'expired']);

    $epg->update(['name' => 'Renamed']);

    expect($epg->fresh()->isSchedulesDirectAuthBlocked())->toBeTrue();
});

it('clears the block on every EPG of the account when the user retries', function () {
    $userId = User::factory()->create()->id;
    $first = sdEpg(['user_id' => $userId, 'sd_auth_blocked_code' => 4001, 'sd_auth_blocked_message' => 'expired']);
    $second = sdEpg(['user_id' => $userId, 'sd_auth_blocked_code' => 4001, 'sd_auth_blocked_message' => 'expired']);

    (new SchedulesDirectService)->clearAuthBlock($first);

    expect($first->fresh()->isSchedulesDirectAuthBlocked())->toBeFalse();
    expect($second->fresh()->isSchedulesDirectAuthBlocked())->toBeFalse();
});

it('enters a cooldown until the next UTC day on TOO_MANY_UNIQUE_IPS (4010)', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 4010, 'response' => 'TOO_MANY_UNIQUE_IPS', 'message' => 'Too many unique IPs.'], 400),
    ]);

    $epg = sdEpg();

    try {
        (new SchedulesDirectService)->authenticateFromEpg($epg);
        $this->fail('Expected a rate limit exception');
    } catch (SchedulesDirectRateLimitException $e) {
        expect($e->reason)->toBe(SchedulesDirectRateLimitException::REASON_TOO_MANY_UNIQUE_IPS);
    }

    expect($epg->fresh()->sd_login_cooldown_until->timestamp)->toBe(now('UTC')->addDay()->startOfDay()->timestamp);
});

it('backs off exponentially up to 60 minutes on /token server errors', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response('Service Unavailable', 503),
    ]);

    $epg = sdEpg();
    $service = new SchedulesDirectService;
    $observed = [];

    foreach (range(1, 8) as $attempt) {
        Epg::whereKey($epg->id)->update(['sd_login_cooldown_until' => null]);

        try {
            $service->authenticateFromEpg($epg->fresh());
        } catch (SchedulesDirectRateLimitException $e) {
            expect($e->reason)->toBe(SchedulesDirectRateLimitException::REASON_SERVER_ERROR);
            $observed[] = (int) round(now()->diffInMinutes($e->retryAt));
        }
    }

    expect($observed)->toBe([1, 2, 4, 8, 16, 32, 60, 60]);
    expect($epg->fresh()->sd_backoff_attempts)->toBe(8);
});

it('treats a /token timeout as a server error and backs off', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    $epg = sdEpg();

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectRateLimitException::class);

    expect($epg->fresh()->sd_backoff_attempts)->toBe(1);
    expect($epg->fresh()->isInSchedulesDirectLoginCooldown())->toBeTrue();
});

it('suppresses /token while a server-error backoff is active', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'x', 'tokenExpires' => now()->addDay()->timestamp]),
    ]);

    $epg = sdEpg(['sd_login_cooldown_until' => now()->addMinutes(4), 'sd_backoff_attempts' => 3]);

    try {
        (new SchedulesDirectService)->authenticateFromEpg($epg);
        $this->fail('Expected a rate limit exception');
    } catch (SchedulesDirectRateLimitException $e) {
        expect($e->reason)->toBe(SchedulesDirectRateLimitException::REASON_BACKOFF);
    }

    Http::assertNothingSent();
});

it('resets the backoff after a successful login', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'ok', 'tokenExpires' => now()->addDay()->timestamp]),
    ]);

    $epg = sdEpg(['sd_login_cooldown_until' => now()->subMinute(), 'sd_backoff_attempts' => 4]);

    (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($epg->fresh()->sd_backoff_attempts)->toBe(0);
    expect($epg->fresh()->sd_login_cooldown_until)->toBeNull();
});

it('backs off on a data endpoint server error and retries the sync when the backoff ends', function () {
    Storage::fake('local');

    $epg = sdEpg([
        'sd_token' => 'still-good',
        'sd_token_expires_at' => now()->addHours(6),
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
        'resync_attempt' => 0,
    ]);

    Queue::fake();
    NotificationFacade::fake();
    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response('Bad Gateway', 502),
    ]);

    (new ProcessEpgImport($epg, force: true))->handle(new SchedulesDirectService);

    $fresh = $epg->fresh();
    expect($fresh->status->value)->toBe('failed');
    expect($fresh->sd_backoff_attempts)->toBe(1);
    expect($fresh->resync_attempt)->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/20141201/token'));
    Queue::assertPushed(ProcessEpgImport::class, 1);

    // A server error is a real failure, so it keeps the danger notification.
    NotificationFacade::assertSentTo($fresh->user, DatabaseNotification::class, fn (DatabaseNotification $notification) => ($notification->data['status'] ?? null) === 'danger');
});

it('reuses a cached token for repeated raw-credential logins', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'form-token', 'tokenExpires' => now()->addDay()->timestamp]),
    ]);

    $service = new SchedulesDirectService;
    $service->authenticate('person@example.com', 'super-secret-password');
    $result = $service->authenticate('person@example.com', 'super-secret-password');

    expect($result['token'])->toBe('form-token');
    Http::assertSentCount(1);
});

it('reports permanent rejections from raw-credential logins with an actionable message', function () {
    fakeAccountExpired();

    expect(fn () => (new SchedulesDirectService)->authenticate('person@example.com', 'super-secret-password'))
        ->toThrow(SchedulesDirectAuthBlockedException::class, 'Renew it at schedulesdirect.org');
});

it('backs off instead of blocking when SD is offline for maintenance (3000)', function (int $status) {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 3000, 'response' => 'SERVICE_OFFLINE', 'message' => 'Server offline for maintenance.'], $status),
    ]);

    $epg = sdEpg();

    try {
        (new SchedulesDirectService)->authenticateFromEpg($epg);
        $this->fail('Expected a rate limit exception');
    } catch (SchedulesDirectRateLimitException $e) {
        expect($e->reason)->toBe(SchedulesDirectRateLimitException::REASON_SERVER_ERROR);
    }

    $fresh = $epg->fresh();
    expect($fresh->isSchedulesDirectAuthBlocked())->toBeFalse()
        ->and($fresh->sd_backoff_attempts)->toBe(1);
})->with(['HTTP 200' => [200], 'HTTP 400' => [400]]);

it('backs off a data endpoint that reports SERVICE_OFFLINE in a 200 body', function () {
    Storage::fake('local');
    fakeLineupSyncEndpoints([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 3000, 'response' => 'SERVICE_OFFLINE', 'message' => 'Server offline for maintenance.']),
    ]);

    $epg = sdEpg(['sd_token' => 'still-good', 'sd_token_expires_at' => now()->addHours(6)]);

    expect(fn () => (new SchedulesDirectService)->syncEpgData($epg))
        ->toThrow(SchedulesDirectRateLimitException::class);
    expect($epg->fresh()->sd_backoff_attempts)->toBe(1);
});

it('names the active cooldown correctly on suppressed attempts', function (array $state, string $reason) {
    Http::fake();

    $epg = sdEpg($state);

    try {
        (new SchedulesDirectService)->authenticateFromEpg($epg);
        $this->fail('Expected a rate limit exception');
    } catch (SchedulesDirectRateLimitException $e) {
        expect($e->reason)->toBe($reason);
    }

    Http::assertNothingSent();
})->with([
    'daily login limit' => [fn () => ['sd_login_cooldown_until' => now('UTC')->addDay()->startOfDay()->setTimezone(config('app.timezone'))], SchedulesDirectRateLimitException::REASON_TOO_MANY_LOGINS],
    'account lockout' => [fn () => ['sd_login_cooldown_until' => now()->addMinutes(10)], SchedulesDirectRateLimitException::REASON_ACCOUNT_LOCKOUT],
    'server backoff' => [fn () => ['sd_login_cooldown_until' => now()->addMinutes(4), 'sd_backoff_attempts' => 3], SchedulesDirectRateLimitException::REASON_BACKOFF],
]);
