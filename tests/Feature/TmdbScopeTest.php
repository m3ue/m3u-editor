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

it('Channel::scopeHasMovieId adds info::jsonb operators when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Channel::query()->hasMovieId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("info::jsonb ?? 'tmdb_id'")
        ->toContain("info::jsonb ?? 'tmdb'")
        ->toContain("info::jsonb ?? 'imdb_id'")
        ->toContain("info::jsonb ?? 'imdb'");
});

it('Channel::scopeHasMovieId adds movie_data::jsonb operators when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Channel::query()->hasMovieId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("movie_data::jsonb ?? 'tmdb_id'")
        ->toContain("movie_data::jsonb ?? 'tmdb'")
        ->toContain("movie_data::jsonb ?? 'imdb_id'")
        ->toContain("movie_data::jsonb ?? 'imdb'");
});

it('Channel::scopeMissingMovieId negates JSON key checks for info and movie_data when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Channel::query()->missingMovieId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("NOT (info::jsonb ?? 'tmdb_id')")
        ->toContain("NOT (info::jsonb ?? 'imdb_id')")
        ->toContain("NOT (movie_data::jsonb ?? 'tmdb_id')")
        ->toContain("NOT (movie_data::jsonb ?? 'imdb_id')");
});

it('Series::scopeHasSeriesId adds metadata::jsonb operators for tmdb, tvdb, and imdb when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Series::query()->hasSeriesId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("metadata::jsonb ?? 'tmdb_id'")
        ->toContain("metadata::jsonb ?? 'tmdb'")
        ->toContain("metadata::jsonb ?? 'tvdb_id'")
        ->toContain("metadata::jsonb ?? 'tvdb'")
        ->toContain("metadata::jsonb ?? 'imdb_id'")
        ->toContain("metadata::jsonb ?? 'imdb'");
});

it('Series::scopeMissingSeriesId negates JSON key checks for metadata when driver is pgsql', function () {
    config(['database.connections.sqlite_testing.driver' => 'pgsql']);
    $sql = Series::query()->missingSeriesId()->toSql();
    config(['database.connections.sqlite_testing.driver' => 'sqlite']);

    expect($sql)
        ->toContain("NOT (metadata::jsonb ?? 'tmdb_id')")
        ->toContain("NOT (metadata::jsonb ?? 'tmdb')")
        ->toContain("NOT (metadata::jsonb ?? 'tvdb_id')")
        ->toContain("NOT (metadata::jsonb ?? 'tvdb')")
        ->toContain("NOT (metadata::jsonb ?? 'imdb_id')")
        ->toContain("NOT (metadata::jsonb ?? 'imdb')");
});
