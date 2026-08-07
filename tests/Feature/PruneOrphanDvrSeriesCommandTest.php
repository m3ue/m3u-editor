<?php

declare(strict_types=1);

use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Queue::fake();
});

it('reports no orphans when the database is empty', function (): void {
    // Authored by TestEngineer
    $this->artisan('dvr:prune-orphan-series')
        ->assertExitCode(0)
        ->expectsOutput('No orphan series found.');
});

it('dry-run reports the count and listed ids/names and deletes nothing', function (): void {
    // Authored by TestEngineer
    $orphanA = Series::factory()->create(['name' => 'Orphan Alpha', 'import_batch_no' => 'dvr']);
    $orphanB = Series::factory()->create(['name' => 'Orphan Beta', 'import_batch_no' => 'dvr']);
    $nonOrphan = Series::factory()->create(['name' => 'Kept Series', 'import_batch_no' => 'dvr']);

    // Create a Season linked to nonOrphan, then an Episode linked to that Season
    $season = Season::factory()->create(['series_id' => $nonOrphan->id]);
    $episode = Episode::factory()->create([
        'series_id' => $nonOrphan->id,
        'season_id' => $season->id,
    ]);

    // Verify DB state: 2 orphans, 1 non-orphan with episode
    expect(Series::find($orphanA->id))->not->toBeNull()
        ->and(Series::find($orphanB->id))->not->toBeNull()
        ->and(Series::find($nonOrphan->id))->not->toBeNull()
        ->and(Episode::find($episode->id))->not->toBeNull()
        ->and(Episode::find($episode->id)->series_id)->toBe($nonOrphan->id)
        ->and(Series::query()->where('import_batch_no', 'dvr')->whereDoesntHave('episodes')->count())->toBe(2);

    Artisan::call('dvr:prune-orphan-series', ['--dry-run' => true]);

    // Verify output contains all expected substrings
    expect(Artisan::output())->toContain('[DRY RUN] Would delete 2 orphan series:')
        ->toContain("#{$orphanA->id}")
        ->toContain('"Orphan Alpha"')
        ->toContain("#{$orphanB->id}")
        ->toContain('"Orphan Beta"');

    // CRITICAL: dry-run must not delete anything
    expect(Series::find($orphanA->id))->not->toBeNull()
        ->and(Series::find($orphanB->id))->not->toBeNull()
        ->and(Series::find($nonOrphan->id))->not->toBeNull();
});

it('real mode deletes only zero-episode DVR series and leaves series with episodes intact', function (): void {
    // Authored by TestEngineer
    $orphanA = Series::factory()->create(['name' => 'Orphan Alpha', 'import_batch_no' => 'dvr']);
    $orphanB = Series::factory()->create(['name' => 'Orphan Beta', 'import_batch_no' => 'dvr']);
    $nonOrphan = Series::factory()->create(['name' => 'Kept Series', 'import_batch_no' => 'dvr']);

    // Create a Season linked to nonOrphan, then an Episode linked to that Season
    $season = Season::factory()->create(['series_id' => $nonOrphan->id]);
    $episode = Episode::factory()->create([
        'series_id' => $nonOrphan->id,
        'season_id' => $season->id,
    ]);

    $this->artisan('dvr:prune-orphan-series')
        ->assertExitCode(0)
        ->expectsOutput('Deleted 2 orphan series.');

    // Orphans must be gone
    expect(Series::find($orphanA->id))->toBeNull()
        ->and(Series::find($orphanB->id))->toBeNull();

    // Non-orphan and its episode must survive
    expect(Series::find($nonOrphan->id))->not->toBeNull()
        ->and(Episode::find($episode->id))->not->toBeNull();
});

it('does not delete a zero-episode series that was not created by DVR', function (): void {
    // Authored by TestEngineer — regression test for the un-scoped query that
    // matched every Series in the database regardless of origin. A normal
    // M3U/Xtream-synced series can legitimately have zero episodes for a
    // window between the Series-creation job and the episode-sync job (or
    // permanently, if the upstream provider lists no episodes), and must
    // never be touched by this DVR-specific cleanup command.
    $nonDvrOrphan = Series::factory()->create([
        'name' => 'Mid-Sync Series',
        'import_batch_no' => 'xtream-abc123',
    ]);

    $this->artisan('dvr:prune-orphan-series')
        ->assertExitCode(0)
        ->expectsOutput('No orphan series found.');

    expect(Series::find($nonDvrOrphan->id))->not->toBeNull();
});
