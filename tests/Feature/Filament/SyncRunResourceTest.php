<?php

/**
 * Smoke tests for the SyncRunResource (list + view) and the LatestSyncRun
 * widget on the Playlist view page. Verifies user-scoped queries via
 * HasUserFiltering trait, timeline construction from a recorded SyncRun,
 * and that read-only conventions (no create) are enforced.
 */

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Filament\Resources\Playlists\Pages\ViewPlaylist;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\Pages\ViewSyncRun;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->actingAs($this->user);
});

it('does not allow creating sync runs', function () {
    expect(SyncRunResource::canCreate())->toBeFalse();
});

it('lists sync runs scoped to the authenticated user', function () {
    $mine = SyncRun::factory()->forPlaylist($this->playlist)->create();

    $other = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($other)->createQuietly();
    $theirs = SyncRun::factory()->forPlaylist($otherPlaylist)->create();

    Livewire::test(ListSyncRuns::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('renders the view page for a sync run', function () {
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'sync',
        'status' => SyncRunStatus::Completed,
        'phases' => [
            'concurrency_guard' => [
                'status' => SyncPhaseStatus::Completed->value,
                'started_at' => now()->subMinute()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test(ViewSyncRun::class, ['record' => $run->getRouteKey()])
        ->assertOk();
});

it('builds a timeline merging planned and recorded phases', function () {
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'sync',
        'phases' => [
            'concurrency_guard' => [
                'status' => SyncPhaseStatus::Completed->value,
                'started_at' => now()->subMinute()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $rows = SyncRunResource::buildPhaseTimeline($run);

    expect($rows)->not->toBeEmpty();

    $bySlug = collect($rows)->keyBy('slug');
    expect($bySlug)->toHaveKey('concurrency_guard');
    expect($bySlug['concurrency_guard']['status'])->toBe(SyncPhaseStatus::Completed);
    expect($bySlug['concurrency_guard']['recorded'])->toBeTrue();

    // any planned step that was not recorded should default to Pending
    $pending = collect($rows)->first(fn ($r) => ! $r['recorded']);
    if ($pending) {
        expect($pending['status'])->toBe(SyncPhaseStatus::Pending);
    }
});

it('surfaces recorded phases that are not part of the plan', function () {
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'sync',
        'phases' => [
            'phantom_phase' => [
                'status' => SyncPhaseStatus::Completed->value,
            ],
        ],
    ]);

    $slugs = array_column(SyncRunResource::buildPhaseTimeline($run), 'slug');
    expect($slugs)->toContain('phantom_phase');
});

it('returns null timeline data for an unknown run kind without crashing', function () {
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'mystery',
        'phases' => [],
    ]);

    expect(SyncRunResource::buildPhaseTimeline($run))->toBe([]);
});

it('renders the playlist view page with the LatestSyncRun widget', function () {
    SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'sync',
        'status' => SyncRunStatus::Completed,
    ]);

    Livewire::test(ViewPlaylist::class, ['record' => $this->playlist->getRouteKey()])
        ->assertOk();
});

it('builds Mermaid flowchart source with status classes for recorded phases', function () {
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'sync',
        'phases' => [
            'concurrency_guard' => [
                'status' => SyncPhaseStatus::Completed->value,
            ],
        ],
    ]);

    $source = SyncRunResource::buildPhaseMermaid($run);

    expect($source)
        ->toStartWith('flowchart LR')
        ->toContain('n_concurrency_guard')
        ->toContain('classDef phase_completed')
        ->toContain('classDef phase_pending')
        ->toContain('_start([Start])')
        ->toContain('_end([End])');
});

it('returns empty mermaid source when there are no phases or plan', function () {
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create([
        'kind' => 'mystery',
        'phases' => [],
    ]);

    expect(SyncRunResource::buildPhaseMermaid($run))->toBe('');
});
