<?php

/**
 * SyncOrchestrator chain-block tests for Step 6 of the sync pipeline refactor.
 *
 * Covers the new {@see SyncPlan::chain()} API + orchestrator chain handling:
 *   - Consecutive ChainablePhase steps dispatch a single Bus::chain.
 *   - Skipped chainable phases don't contribute jobs but don't break the chain.
 *   - Optional chain-phase failure during chainJobs() is logged + excluded.
 *   - Required chain-phase failure halts and marks the run failed.
 *   - Empty chain (all skipped / all returned []) dispatches nothing.
 *   - Chain context exposes contributors and job count to subsequent steps.
 *   - chain() rejects non-ChainablePhase classes at build time.
 */

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Contracts\ChainablePhase;
use App\Sync\Phases\AbstractPhase;
use App\Sync\SyncOrchestrator;
use App\Sync\SyncPlan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

// -----------------------------------------------------------------------------
// SyncPlan::chain()
// -----------------------------------------------------------------------------

it('records a chain group id on chained phases', function () {
    $plan = SyncPlan::make('test')->chain([
        FakeChainPhaseA::class,
        FakeChainPhaseB::class,
    ]);

    $steps = $plan->steps();
    expect($steps)->toHaveCount(2);
    expect($steps[0]->chainGroup)->not->toBeNull();
    expect($steps[1]->chainGroup)->toBe($steps[0]->chainGroup);
    expect($steps[0]->required)->toBeFalse();
});

it('rejects non-ChainablePhase classes in chain()', function () {
    expect(fn () => SyncPlan::make('test')->chain([FakeNonChainPhase::class]))
        ->toThrow(InvalidArgumentException::class);
});

it('skips empty chain blocks', function () {
    $plan = SyncPlan::make('test')->chain([]);
    expect($plan->isEmpty())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Orchestrator chain execution
// -----------------------------------------------------------------------------

it('dispatches a single Bus::chain assembled from contributing phases', function () {
    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')->chain([
        FakeChainPhaseA::class,
        FakeChainPhaseB::class,
    ]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertDispatchedTimes(FakeChainJobA::class, 1);
    Bus::assertChained([FakeChainJobA::class, FakeChainJobB::class]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->phaseStatus(FakeChainPhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus(FakeChainPhaseB::slug()))->toBe(SyncPhaseStatus::Completed);
});

it('skips chainable phases whose shouldRun returns false but still chains the rest', function () {
    FakeSkippableChainPhase::$shouldRun = false;

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')->chain([
        FakeChainPhaseA::class,
        FakeSkippableChainPhase::class,
        FakeChainPhaseB::class,
    ]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertChained([FakeChainJobA::class, FakeChainJobB::class]);

    $fresh = $run->fresh();
    expect($fresh->phaseStatus(FakeSkippableChainPhase::slug()))->toBe(SyncPhaseStatus::Skipped);
    expect($fresh->phaseStatus(FakeChainPhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus(FakeChainPhaseB::slug()))->toBe(SyncPhaseStatus::Completed);
});

it('dispatches nothing when no chainable phase contributes', function () {
    FakeSkippableChainPhase::$shouldRun = false;

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')->chain([
        FakeSkippableChainPhase::class,
    ]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertNothingDispatched();
    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
});

it('continues past optional chain phase failures and excludes them from the chain', function () {
    FakeFailingChainPhase::$message = 'boom';

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')->chain([
        FakeChainPhaseA::class,
        FakeFailingChainPhase::class, // required:false (chain default)
        FakeChainPhaseB::class,
    ]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    // Chain assembled with only the surviving contributors.
    Bus::assertChained([FakeChainJobA::class, FakeChainJobB::class]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->phaseStatus(FakeFailingChainPhase::slug()))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->errors)->not->toBeEmpty();
});

it('halts and marks run failed when a required chain phase throws', function () {
    FakeFailingChainPhase::$message = 'fatal';

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')->chain([
        FakeChainPhaseA::class,
        FakeFailingChainPhase::class,
        FakeChainPhaseB::class,
    ], required: true);

    app(SyncOrchestrator::class)->execute($run, $plan);

    // Required failure aborts before Bus::chain dispatches.
    Bus::assertNotDispatched(FakeChainJobA::class);
    Bus::assertNotDispatched(FakeChainJobB::class);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Failed);
    expect($fresh->phaseStatus(FakeFailingChainPhase::slug()))->toBe(SyncPhaseStatus::Failed);
});

it('threads chain context (contributors + job count) into subsequent non-chain steps', function () {
    FakeChainContextReader::$seenContext = [];

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->chain([FakeChainPhaseA::class, FakeChainPhaseB::class])
        ->phase(FakeChainContextReader::class);

    app(SyncOrchestrator::class)->execute($run, $plan);

    expect(FakeChainContextReader::$seenContext['chain_dispatched'])->toBe([
        FakeChainPhaseA::slug(),
        FakeChainPhaseB::slug(),
    ]);
    expect(FakeChainContextReader::$seenContext['chain_job_count'])->toBe(2);
});

// -----------------------------------------------------------------------------
// Test phase / job doubles
// -----------------------------------------------------------------------------

class FakeChainJobA implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void {}
}

class FakeChainJobB implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void {}
}

class FakeChainPhaseA extends AbstractPhase implements ChainablePhase
{
    public static function slug(): string
    {
        return 'fake_chain_a';
    }

    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [new FakeChainJobA];
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}

class FakeChainPhaseB extends AbstractPhase implements ChainablePhase
{
    public static function slug(): string
    {
        return 'fake_chain_b';
    }

    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [new FakeChainJobB];
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}

class FakeSkippableChainPhase extends AbstractPhase implements ChainablePhase
{
    public static bool $shouldRun = false;

    public static function slug(): string
    {
        return 'fake_chain_skip';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return self::$shouldRun;
    }

    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [new FakeChainJobA];
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}

class FakeFailingChainPhase extends AbstractPhase implements ChainablePhase
{
    public static string $message = 'failure';

    public static function slug(): string
    {
        return 'fake_chain_fail';
    }

    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        throw new RuntimeException(self::$message);
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}

class FakeChainContextReader extends AbstractPhase
{
    /** @var array<string, mixed> */
    public static array $seenContext = [];

    public static function slug(): string
    {
        return 'fake_chain_ctx_reader';
    }

    protected function execute($run, $playlist, $context): ?array
    {
        self::$seenContext = $context;

        return null;
    }
}

class FakeNonChainPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'fake_non_chain';
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}
