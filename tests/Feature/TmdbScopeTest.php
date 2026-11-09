<?php

/**
 * Tests for Channel::scopeHasMovieId / scopeMissingMovieId
 * and  Series::scopeHasSeriesId  / scopeMissingSeriesId.
 *
 * SQLite branch (dedicated columns only) — executed on every run.
 * PostgreSQL branch (JSON operators) — SQL generation is verified by temporarily
 * spoofing the active connection's driver config to 'pgsql' so the scope enters
 * the correct branch, then asserting the ::jsonb ?? operators appear in the SQL.
 * No real PostgreSQL connection is needed; toSql() builds the string only.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

// ── Channel::scopeHasMovieId — dedicated columns (SQLite) ────────────────────

describe('Channel::scopeHasMovieId — dedicated columns', function () {
    it('includes channels with a tmdb_id', function () {
        $withTmdb = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => 603, 'imdb_id' => null]);
        $without = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'imdb_id' => null]);

        $ids = Channel::query()->hasMovieId()->pluck('id');

        expect($ids)->toContain($withTmdb->id)
            ->and($ids)->not->toContain($without->id);
    });

    it('includes channels with an imdb_id', function () {
        $withImdb = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'imdb_id' => 'tt0133093']);
        $without = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'imdb_id' => null]);

        $ids = Channel::query()->hasMovieId()->pluck('id');

        expect($ids)->toContain($withImdb->id)
            ->and($ids)->not->toContain($without->id);
    });
});

// ── Channel::scopeMissingMovieId — dedicated columns (SQLite) ────────────────

describe('Channel::scopeMissingMovieId — dedicated columns', function () {
    it('excludes channels that have tmdb_id or imdb_id', function () {
        $withId = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => 603, 'imdb_id' => null]);
        $missing = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'imdb_id' => null]);

        $ids = Channel::query()->missingMovieId()->pluck('id');

        expect($ids)->toContain($missing->id)
            ->and($ids)->not->toContain($withId->id);
    });

    it('returns a channel that has neither tmdb_id nor imdb_id', function () {
        $missing = Channel::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'imdb_id' => null]);

        expect(Channel::missingMovieId()->find($missing->id))->not->toBeNull();
    });
});

// ── Series::scopeHasSeriesId — dedicated columns (SQLite) ────────────────────

describe('Series::scopeHasSeriesId — dedicated columns', function () {
    it('includes series with a tmdb_id', function () {
        $withTmdb = Series::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => 1396, 'tvdb_id' => null, 'imdb_id' => null]);
        $without = Series::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'tvdb_id' => null, 'imdb_id' => null]);

        $ids = Series::query()->hasSeriesId()->pluck('id');

        expect($ids)->toContain($withTmdb->id)
            ->and($ids)->not->toContain($without->id);
    });

    it('includes series with a tvdb_id', function () {
        $withTvdb = Series::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'tvdb_id' => 81189, 'imdb_id' => null]);
        $without = Series::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'tvdb_id' => null, 'imdb_id' => null]);

        $ids = Series::query()->hasSeriesId()->pluck('id');

        expect($ids)->toContain($withTvdb->id)
            ->and($ids)->not->toContain($without->id);
    });
});

// ── Series::scopeMissingSeriesId — dedicated columns (SQLite) ────────────────

describe('Series::scopeMissingSeriesId — dedicated columns', function () {
    it('excludes series that have any of tmdb_id / tvdb_id / imdb_id', function () {
        $withId = Series::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => 1396, 'tvdb_id' => null, 'imdb_id' => null]);
        $missing = Series::factory()->for($this->playlist)->for($this->user)
            ->create(['tmdb_id' => null, 'tvdb_id' => null, 'imdb_id' => null]);

        $ids = Series::query()->missingSeriesId()->pluck('id');

        expect($ids)->toContain($missing->id)
            ->and($ids)->not->toContain($withId->id);
    });
});

// ── PostgreSQL JSON branch — SQL generation ───────────────────────────────────
//
// The scopes check config('database.connections.<default>.driver'). We spoof it
// to 'pgsql' inline (set before, restored after) so the pgsql branch is entered
// without needing an actual PostgreSQL connection. toSql() builds the SQL string
// only — no query is executed against the database.

it('Channel::scopeHasMovieId adds info::jsonb value checks when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Channel::query()->hasMovieId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("info::jsonb->>'tmdb_id' IS NOT NULL")
        ->toContain("info::jsonb->>'tmdb' IS NOT NULL")
        ->toContain("info::jsonb->>'imdb_id' IS NOT NULL")
        ->toContain("info::jsonb->>'imdb' IS NOT NULL")
        ->not->toContain('??');
});

it('Channel::scopeHasMovieId adds movie_data::jsonb value checks when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Channel::query()->hasMovieId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("movie_data::jsonb->>'tmdb_id' IS NOT NULL")
        ->toContain("movie_data::jsonb->>'tmdb' IS NOT NULL")
        ->toContain("movie_data::jsonb->>'imdb_id' IS NOT NULL")
        ->toContain("movie_data::jsonb->>'imdb' IS NOT NULL");
});

it('Channel::scopeMissingMovieId negates JSON value checks for info and movie_data when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Channel::query()->missingMovieId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("info::jsonb->>'tmdb_id' IS NULL")
        ->toContain("info::jsonb->>'imdb_id' IS NULL")
        ->toContain("movie_data::jsonb->>'tmdb_id' IS NULL")
        ->toContain("movie_data::jsonb->>'imdb_id' IS NULL")
        ->not->toContain('??');
});

it('Channel::scopeHasMovieId and scopeMissingMovieId agree a key present with an empty value means no ID (issue #1645)', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);

    $hasSql = Channel::query()->hasMovieId()->toSql();
    $missingSql = Channel::query()->missingMovieId()->toSql();

    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    // Both scopes check the JSON *value*, not merely whether the key exists - a channel
    // with info->tmdb_id = "" or null must count as "no ID" in both directions, not just
    // excluded from "has" while also being excluded from "missing".
    expect($hasSql)->toContain("!= ''")
        ->and($missingSql)->toContain("= ''");
});

it('Series::scopeHasSeriesId adds metadata::jsonb value checks for tmdb, tvdb, and imdb when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Series::query()->hasSeriesId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("metadata::jsonb->>'tmdb_id' IS NOT NULL")
        ->toContain("metadata::jsonb->>'tmdb' IS NOT NULL")
        ->toContain("metadata::jsonb->>'tvdb_id' IS NOT NULL")
        ->toContain("metadata::jsonb->>'tvdb' IS NOT NULL")
        ->toContain("metadata::jsonb->>'imdb_id' IS NOT NULL")
        ->toContain("metadata::jsonb->>'imdb' IS NOT NULL")
        ->not->toContain('??');
});

it('Series::scopeMissingSeriesId negates JSON value checks for metadata when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Series::query()->missingSeriesId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("metadata::jsonb->>'tmdb_id' IS NULL")
        ->toContain("metadata::jsonb->>'tmdb' IS NULL")
        ->toContain("metadata::jsonb->>'tvdb_id' IS NULL")
        ->toContain("metadata::jsonb->>'tvdb' IS NULL")
        ->toContain("metadata::jsonb->>'imdb_id' IS NULL")
        ->toContain("metadata::jsonb->>'imdb' IS NULL")
        ->not->toContain('??');
});
