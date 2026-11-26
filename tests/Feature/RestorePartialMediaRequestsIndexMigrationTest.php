<?php

/**
 * Covers 2026_11_26_082330_restore_partial_media_requests_active_unique_index, which repairs
 * Postgres installs converted from SQLite before the conversion script replayed partial
 * indexes: media_requests_active_unique arrived as a full UNIQUE constraint (or was missing).
 */

use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->auth = PlaylistAuth::factory()->for($user)->create();
    $this->radarr = ArrIntegration::factory()->for($user)->radarr()->create();
});

function restorePartialMediaRequestsIndexMigration(): void
{
    $migration = require database_path('migrations/2026_11_26_082330_restore_partial_media_requests_active_unique_index.php');
    $migration->up();
}

function restorePartialMediaRequestsIndexCreateRequest(object $test, string $status, array $attributes = []): MediaRequest
{
    return MediaRequest::create(array_merge([
        'playlist_auth_id' => $test->auth->id,
        'arr_integration_id' => $test->radarr->id,
        'title' => 'Alien',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => $status,
        'requested_at' => now(),
    ], $attributes));
}

function restorePartialMediaRequestsIndexIsPartial(): bool
{
    return DB::table('pg_index')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
        ->where('pg_class.relname', 'media_requests_active_unique')
        ->whereNotNull('pg_index.indpred')
        ->exists();
}

function restorePartialMediaRequestsIndexHasConstraint(): bool
{
    return DB::table('pg_constraint')
        ->where('conname', 'media_requests_active_unique')
        ->exists();
}

$requiresPostgres = fn (): bool => DB::connection()->getDriverName() !== 'pgsql';

it('replaces a full unique constraint left by the SQLite conversion with the partial index', function () {
    DB::statement('DROP INDEX IF EXISTS media_requests_active_unique');
    DB::statement(
        'ALTER TABLE media_requests ADD CONSTRAINT media_requests_active_unique'
        .' UNIQUE (playlist_auth_id, arr_integration_id, external_id, request_type)'
    );

    restorePartialMediaRequestsIndexCreateRequest($this, 'completed', ['requested_at' => now()->subWeek()]);

    restorePartialMediaRequestsIndexMigration();

    expect(restorePartialMediaRequestsIndexHasConstraint())->toBeFalse()
        ->and(restorePartialMediaRequestsIndexIsPartial())->toBeTrue();

    // Re-requesting after a completed request is allowed again...
    restorePartialMediaRequestsIndexCreateRequest($this, 'pending');

    // ...but a second active request for the same item is still rejected.
    expect(fn () => DB::transaction(fn () => restorePartialMediaRequestsIndexCreateRequest($this, 'approved')))
        ->toThrow(QueryException::class);
})->skip($requiresPostgres, 'Requires PostgreSQL');

it('rejects duplicate active requests before creating a missing partial index', function () {
    DB::statement('DROP INDEX IF EXISTS media_requests_active_unique');

    $pending = restorePartialMediaRequestsIndexCreateRequest($this, 'pending', ['requested_at' => now()->subDay()]);
    $approved = restorePartialMediaRequestsIndexCreateRequest($this, 'approved', ['requested_at' => now()->subDays(2)]);

    restorePartialMediaRequestsIndexMigration();

    expect(restorePartialMediaRequestsIndexIsPartial())->toBeTrue()
        ->and($approved->fresh()->status)->toBe('approved')
        ->and($pending->fresh()->status)->toBe('rejected');
})->skip($requiresPostgres, 'Requires PostgreSQL');

it('leaves an existing partial index and its requests untouched', function () {
    $completed = restorePartialMediaRequestsIndexCreateRequest($this, 'completed');
    $pending = restorePartialMediaRequestsIndexCreateRequest($this, 'pending');

    $oidBefore = DB::table('pg_class')->where('relname', 'media_requests_active_unique')->value('oid');

    restorePartialMediaRequestsIndexMigration();

    expect(DB::table('pg_class')->where('relname', 'media_requests_active_unique')->value('oid'))->toBe($oidBefore)
        ->and($completed->fresh()->status)->toBe('completed')
        ->and($pending->fresh()->status)->toBe('pending');
})->skip($requiresPostgres, 'Requires PostgreSQL');

it('is a no-op on SQLite', function () {
    $completed = restorePartialMediaRequestsIndexCreateRequest($this, 'completed');

    restorePartialMediaRequestsIndexMigration();

    restorePartialMediaRequestsIndexCreateRequest($this, 'pending');

    expect($completed->fresh()->status)->toBe('completed')
        ->and(MediaRequest::count())->toBe(2);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'SQLite only');
