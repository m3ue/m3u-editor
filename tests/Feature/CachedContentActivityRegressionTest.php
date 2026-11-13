<?php

use App\Models\CachedContentActivityLease;
use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\CachedContentActivityService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create();
    $this->activity = app(CachedContentActivityService::class);
    $this->target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:synthetic:1',
        'variant_key' => '1080p',
    ];
});

it('does not renew an older heartbeat or overwrite its sequence', function (): void {
    $lease = $this->activity->acquirePlayback($this->user, $this->integration, (string) Str::uuid(), 3, $this->target);
    $expiry = $lease->expires_at->copy();
    $this->travel(60)->seconds();

    $this->activity->acquirePlayback($this->user, $this->integration, $lease->lease_id, 2, $this->target);

    expect($lease->fresh()->sequence)->toBe(3)
        ->and($lease->fresh()->expires_at->equalTo($expiry))->toBeTrue();
});

it('does not retarget a retried session to another canonical identity', function (): void {
    $lease = $this->activity->acquirePlayback($this->user, $this->integration, (string) Str::uuid(), 1, $this->target);

    expect(fn () => $this->activity->acquirePlayback(
        $this->user,
        $this->integration,
        $lease->lease_id,
        1,
        [...$this->target, 'canonical_id' => 'episode:synthetic:2'],
    ))->toThrow(InvalidArgumentException::class);

    expect($lease->fresh()->canonical_id)->toBe($this->target['canonical_id']);
});

it('bounds reader renewal writes by elapsed time rather than chunks', function (int $chunks): void {
    $this->freezeSecond();
    $lease = $this->activity->acquireReader($this->user, $this->integration, (string) Str::uuid(), $this->target);
    $this->travel(29)->seconds();
    DB::enableQueryLog();
    DB::flushQueryLog();

    for ($chunk = 0; $chunk < $chunks; $chunk++) {
        $this->activity->renewReader($lease);
    }
    expect(DB::getQueryLog())->toHaveCount(0);

    $this->travel(1)->seconds();
    $this->activity->renewReader($lease);
    $this->activity->renewReader($lease);
    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($lease->expires_at->equalTo(now()->addSeconds(120)))->toBeTrue();
    DB::disableQueryLog();
})->with([100, 1000, 262144]);

it('rolls back a playback lease with its enclosing transaction', function (): void {
    $leaseId = (string) Str::uuid();
    try {
        DB::transaction(function () use ($leaseId): void {
            $this->activity->acquirePlayback($this->user, $this->integration, $leaseId, 1, $this->target);
            throw new RuntimeException('synthetic rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('synthetic rollback');
    }

    expect(CachedContentActivityLease::where('lease_id', $leaseId)->exists())->toBeFalse();
});
