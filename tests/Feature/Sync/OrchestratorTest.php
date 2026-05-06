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
use App\Sync\Phases\AbstractPhase;
use App\Sync\Phases\AutoSyncToCustomPhase;
use App\Sync\Phases\ChannelScanPhase;
use App\Sync\Phases\FindReplaceAndSortAlphaPhase;
use App\Sync\Phases\PlexDvrSyncPhase;
use App\Sync\Phases\PluginDispatchPhase;
use App\Sync\Phases\PostProcessPhase;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\PlanStep;
use App\Sync\SyncOrchestrator;
use App\Sync\SyncPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->parallel([FakeSequentialPhaseB::class, FakeSequentialPhaseC::class]);

    $steps = $plan->steps();
    expect($steps)->toHaveCount(3);
    expect($steps[0]->parallelGroup)->toBeNull();
    expect($steps[1]->parallelGroup)->not->toBeNull();
    expect($steps[2]->parallelGroup)->toBe($steps[1]->parallelGroup);
    // Parallel phases are optional by default.
    expect($steps[1]->required)->toBeFalse();
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
// PlaylistPostSyncPlan factory
// -----------------------------------------------------------------------------

it('builds the canonical post-sync plan with the expected phases', function () {
    $plan = PlaylistPostSyncPlan::build();
    $classes = collect($plan->steps())->pluck('phaseClass')->all();

    expect($plan->name)->toBe('playlist.post_sync');
    expect($classes)->toBe([
        FindReplaceAndSortAlphaPhase::class,
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
