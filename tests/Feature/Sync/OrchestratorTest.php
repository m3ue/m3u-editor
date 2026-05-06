<?php

/**
 * SyncOrchestrator + SyncPlan tests for Step 4 of the sync pipeline refactor.
 *
 * Covers:
 *   - SyncPlan fluent builder (phase, parallel, type guards)
 *   - Orchestrator happy path: marks run started -> runs phases in order ->
 *     marks completed
 *   - Skipped phases (shouldRun=false) recorded as Skipped, run continues
 *   - Required phase failure halts the orchestrator and marks run Failed
 *   - Optional phase failure is recorded but run continues + completes
 *   - Context threading: each phase sees the merged context from prior phases
 *   - Constructor DI works (PluginDispatchPhase resolved with its dependency)
 */

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Contracts\BatchablePhase;
use App\Sync\Phases\AbstractPhase;
use App\Sync\Phases\AutoSyncToCustomPhase;
use App\Sync\Phases\ChannelScanPhase;
use App\Sync\Phases\FindReplaceAndSortAlphaPhase;
use App\Sync\Phases\PlexDvrSyncPhase;
use App\Sync\Phases\PluginDispatchPhase;
use App\Sync\Phases\PostProcessPhase;
use App\Sync\Phases\SeriesStrmPostProcessPhase;
use App\Sync\Phases\SeriesStrmSyncPhase;
use App\Sync\Phases\StrmPostProcessPhase;
use App\Sync\Phases\StrmSyncPhase;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\PlanStep;
use App\Sync\SyncOrchestrator;
use App\Sync\SyncPlan;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

// -----------------------------------------------------------------------------
// SyncPlan
// -----------------------------------------------------------------------------

it('builds a plan with phase steps in declaration order', function () {
    $plan = SyncPlan::make('test')
        ->phase(FakeSequentialPhaseA::class)
        ->phase(FakeSequentialPhaseB::class, required: false);

    expect($plan->name)->toBe('test');
    expect($plan->steps())->toHaveCount(2);
    expect($plan->steps()[0]->phaseClass)->toBe(FakeSequentialPhaseA::class);
    expect($plan->steps()[0]->required)->toBeTrue();
    expect($plan->steps()[0]->parallelGroup)->toBeNull();
    expect($plan->steps()[1]->required)->toBeFalse();
});

it('records a parallel group id on grouped phases', function () {
    $plan = SyncPlan::make('test')
        ->phase(FakeSequentialPhaseA::class)
        ->parallel([FakeBatchablePhaseA::class, FakeBatchablePhaseB::class]);

    $steps = $plan->steps();
    expect($steps)->toHaveCount(3);
    expect($steps[0]->parallelGroup)->toBeNull();
    expect($steps[1]->parallelGroup)->not->toBeNull();
    expect($steps[2]->parallelGroup)->toBe($steps[1]->parallelGroup);
    // Parallel phases are optional by default.
    expect($steps[1]->required)->toBeFalse();
});

it('rejects non-BatchablePhase classes when building a parallel group', function () {
    expect(fn () => SyncPlan::make('test')->parallel([FakeSequentialPhaseA::class]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non-SyncPhase classes when building a plan', function () {
    expect(fn () => SyncPlan::make('test')->phase(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

it('skips empty parallel groups', function () {
    $plan = SyncPlan::make('test')->parallel([]);
    expect($plan->isEmpty())->toBeTrue();
});

// -----------------------------------------------------------------------------
// SyncOrchestrator happy path
// -----------------------------------------------------------------------------

it('marks run started, runs phases in order, marks completed', function () {
    FakeSequentialPhaseA::reset();
    FakeSequentialPhaseB::reset();

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->phase(FakeSequentialPhaseA::class)
        ->phase(FakeSequentialPhaseB::class);

    app(SyncOrchestrator::class)->execute($run, $plan);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->started_at)->not->toBeNull();
    expect($fresh->finished_at)->not->toBeNull();
    expect($fresh->phaseStatus(FakeSequentialPhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus(FakeSequentialPhaseB::slug()))->toBe(SyncPhaseStatus::Completed);
    expect(FakeSequentialPhaseA::$callOrder)->toBeLessThan(FakeSequentialPhaseB::$callOrder);
});

it('records skipped phases when shouldRun returns false', function () {
    FakeSkippablePhase::$shouldRun = false;
    FakeSequentialPhaseA::reset();

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->phase(FakeSkippablePhase::class)
        ->phase(FakeSequentialPhaseA::class);

    app(SyncOrchestrator::class)->execute($run, $plan);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->phaseStatus(FakeSkippablePhase::slug()))->toBe(SyncPhaseStatus::Skipped);
    expect($fresh->phaseStatus(FakeSequentialPhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phases[FakeSkippablePhase::slug()]['meta'])->toBe(['reason' => 'shouldRun returned false']);
});

it('threads context through phases', function () {
    FakeContextWriterPhase::reset();
    FakeContextReaderPhase::reset();

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->phase(FakeContextWriterPhase::class)
        ->phase(FakeContextReaderPhase::class);

    app(SyncOrchestrator::class)->execute($run, $plan, ['initial' => 'seed']);

    expect(FakeContextReaderPhase::$seenContext)->toMatchArray([
        'initial' => 'seed',
        'token' => 'from-writer',
    ]);
});

// -----------------------------------------------------------------------------
// SyncOrchestrator failure handling
// -----------------------------------------------------------------------------

it('halts and marks run failed when a required phase throws', function () {
    FakeSequentialPhaseA::reset();
    FakeFailingPhase::$message = 'bang';

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->phase(FakeFailingPhase::class) // required by default
        ->phase(FakeSequentialPhaseA::class);

    app(SyncOrchestrator::class)->execute($run, $plan);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Failed);
    expect($fresh->phaseStatus(FakeFailingPhase::slug()))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phaseStatus(FakeSequentialPhaseA::slug()))->toBe(SyncPhaseStatus::Pending);
    expect(FakeSequentialPhaseA::$callOrder)->toBe(0); // never called
    expect($fresh->errors)->not->toBeEmpty();
});

it('continues past optional phase failures and marks run completed', function () {
    FakeSequentialPhaseA::reset();
    FakeFailingPhase::$message = 'oops';

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->phase(FakeFailingPhase::class, required: false)
        ->phase(FakeSequentialPhaseA::class);

    app(SyncOrchestrator::class)->execute($run, $plan);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->phaseStatus(FakeFailingPhase::slug()))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phaseStatus(FakeSequentialPhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
});

// -----------------------------------------------------------------------------
// SyncOrchestrator parallel groups (Bus::batch)
// -----------------------------------------------------------------------------

it('dispatches a single Bus::batch with all jobs from a parallel group', function () {
    FakeBatchablePhaseA::reset();
    FakeBatchablePhaseB::reset();

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->parallel([FakeBatchablePhaseA::class, FakeBatchablePhaseB::class]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    expect(FakeBatchablePhaseA::$batchJobsCalled)->toBe(1);
    expect(FakeBatchablePhaseB::$batchJobsCalled)->toBe(1);

    Bus::assertBatched(function (PendingBatch $batch) use ($run): bool {
        return $batch->name === "sync_run:{$run->id}:parallel"
            && $batch->jobs->count() === 3;
    });
    Bus::assertBatchCount(1);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->phaseStatus(FakeBatchablePhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus(FakeBatchablePhaseB::slug()))->toBe(SyncPhaseStatus::Completed);
});

it('marks a parallel-group phase Skipped and excludes it from the batch', function () {
    FakeBatchablePhaseA::reset();
    FakeBatchableSkippablePhase::$shouldRun = false;

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->parallel([FakeBatchablePhaseA::class, FakeBatchableSkippablePhase::class]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        // Only PhaseA's 2 jobs; the skippable phase contributed nothing.
        return $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => in_array($job->tag, ['a1', 'a2'], true));
    });

    $fresh = $run->fresh();
    expect($fresh->phaseStatus(FakeBatchableSkippablePhase::slug()))->toBe(SyncPhaseStatus::Skipped);
    expect($fresh->phaseStatus(FakeBatchablePhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
});

it('skips dispatching when no parallel-group phase contributes jobs', function () {
    FakeBatchableSkippablePhase::$shouldRun = false;

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->parallel([FakeBatchableSkippablePhase::class]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertNothingBatched();
    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
});

it('continues past an optional batch-phase failure and dispatches surviving jobs', function () {
    FakeBatchablePhaseA::reset();
    FakeBatchableFailingPhase::$message = 'broken';

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->parallel([FakeBatchableFailingPhase::class, FakeBatchablePhaseA::class]);

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        return $batch->jobs->count() === 2; // only PhaseA contributed
    });

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->phaseStatus(FakeBatchableFailingPhase::slug()))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phaseStatus(FakeBatchablePhaseA::slug()))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->errors)->not->toBeEmpty();
});

it('halts and marks run failed when a required parallel phase throws', function () {
    FakeBatchablePhaseA::reset();
    FakeBatchableFailingPhase::$message = 'required boom';

    $run = SyncRun::openFor($this->playlist);
    $plan = SyncPlan::make('test')
        ->parallel(
            [FakeBatchableFailingPhase::class, FakeBatchablePhaseA::class],
            required: true,
        );

    app(SyncOrchestrator::class)->execute($run, $plan);

    Bus::assertNothingBatched();

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Failed);
    expect($fresh->phaseStatus(FakeBatchableFailingPhase::slug()))->toBe(SyncPhaseStatus::Failed);
    // PhaseA never got to contribute because the failure halted the orchestrator.
    expect(FakeBatchablePhaseA::$batchJobsCalled)->toBe(0);
});

// -----------------------------------------------------------------------------
// PlaylistPostSyncPlan factory
// -----------------------------------------------------------------------------

it('builds the canonical post-sync plan with the expected phases', function () {
    $plan = PlaylistPostSyncPlan::build();
    $classes = collect($plan->steps())->pluck('phaseClass')->all();

    expect($plan->name)->toBe('playlist.post_sync');
    expect($classes)->toBe([
        FindReplaceAndSortAlphaPhase::class,
        StrmSyncPhase::class,
        StrmPostProcessPhase::class,
        SeriesStrmSyncPhase::class,
        SeriesStrmPostProcessPhase::class,
        ChannelScanPhase::class,
        AutoSyncToCustomPhase::class,
        PlexDvrSyncPhase::class,
        PostProcessPhase::class,
        PluginDispatchPhase::class,
    ]);
    // All steps optional — one failure shouldn't block the rest.
    expect(collect($plan->steps())->every(fn (PlanStep $s) => ! $s->required))->toBeTrue();
});

// -----------------------------------------------------------------------------
// Test phase doubles
// -----------------------------------------------------------------------------

class FakeOrchestrationCallTracker
{
    public static int $counter = 0;

    public static function next(): int
    {
        return ++self::$counter;
    }

    public static function reset(): void
    {
        self::$counter = 0;
    }
}

class FakeSequentialPhaseA extends AbstractPhase
{
    public static int $callOrder = 0;

    public static function slug(): string
    {
        return 'fake_a';
    }

    public static function reset(): void
    {
        self::$callOrder = 0;
        FakeOrchestrationCallTracker::reset();
    }

    protected function execute($run, $playlist, $context): ?array
    {
        self::$callOrder = FakeOrchestrationCallTracker::next();

        return null;
    }
}

class FakeSequentialPhaseB extends AbstractPhase
{
    public static int $callOrder = 0;

    public static function slug(): string
    {
        return 'fake_b';
    }

    public static function reset(): void
    {
        self::$callOrder = 0;
    }

    protected function execute($run, $playlist, $context): ?array
    {
        self::$callOrder = FakeOrchestrationCallTracker::next();

        return null;
    }
}

class FakeSequentialPhaseC extends AbstractPhase
{
    public static function slug(): string
    {
        return 'fake_c';
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}

class FakeSkippablePhase extends AbstractPhase
{
    public static bool $shouldRun = false;

    public static function slug(): string
    {
        return 'fake_skippable';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return self::$shouldRun;
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }
}

class FakeFailingPhase extends AbstractPhase
{
    public static string $message = 'failure';

    public static function slug(): string
    {
        return 'fake_failing';
    }

    protected function execute($run, $playlist, $context): ?array
    {
        throw new RuntimeException(self::$message);
    }
}

class FakeContextWriterPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'ctx_writer';
    }

    public static function reset(): void {}

    protected function execute($run, $playlist, $context): ?array
    {
        return ['token' => 'from-writer'];
    }
}

class FakeContextReaderPhase extends AbstractPhase
{
    /** @var array<string, mixed> */
    public static array $seenContext = [];

    public static function slug(): string
    {
        return 'ctx_reader';
    }

    public static function reset(): void
    {
        self::$seenContext = [];
    }

    protected function execute($run, $playlist, $context): ?array
    {
        self::$seenContext = $context;

        return null;
    }
}

class FakeBatchableJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $tag) {}

    public function handle(): void {}
}

class FakeBatchablePhaseA extends AbstractPhase implements BatchablePhase
{
    public static int $batchJobsCalled = 0;

    public static function slug(): string
    {
        return 'fake_batch_a';
    }

    public static function reset(): void
    {
        self::$batchJobsCalled = 0;
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }

    public function batchJobs($run, $playlist, array $context = []): array
    {
        self::$batchJobsCalled++;

        return [new FakeBatchableJob('a1'), new FakeBatchableJob('a2')];
    }
}

class FakeBatchablePhaseB extends AbstractPhase implements BatchablePhase
{
    public static int $batchJobsCalled = 0;

    public static function slug(): string
    {
        return 'fake_batch_b';
    }

    public static function reset(): void
    {
        self::$batchJobsCalled = 0;
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }

    public function batchJobs($run, $playlist, array $context = []): array
    {
        self::$batchJobsCalled++;

        return [new FakeBatchableJob('b1')];
    }
}

class FakeBatchableSkippablePhase extends AbstractPhase implements BatchablePhase
{
    public static bool $shouldRun = true;

    public static function slug(): string
    {
        return 'fake_batch_skip';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return self::$shouldRun;
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }

    public function batchJobs($run, $playlist, array $context = []): array
    {
        return [new FakeBatchableJob('skip_should_not_appear')];
    }
}

class FakeBatchableFailingPhase extends AbstractPhase implements BatchablePhase
{
    public static string $message = 'batch boom';

    public static function slug(): string
    {
        return 'fake_batch_fail';
    }

    protected function execute($run, $playlist, $context): ?array
    {
        return null;
    }

    public function batchJobs($run, $playlist, array $context = []): array
    {
        throw new RuntimeException(self::$message);
    }
}
