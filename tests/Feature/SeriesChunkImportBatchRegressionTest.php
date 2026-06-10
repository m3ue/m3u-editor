<?php

/**
 * Regression tests for the null last_modified false-negative in
 * ProcessM3uImportSeriesChunk.
 *
 * The existingSeriesIds collection is keyed by source_series_id with
 * last_modified as the value. A series already in the DB with
 * last_modified = NULL would cause ->get($id) to return null, making it
 * indistinguishable from a key that doesn't exist at all. The fix uses
 * ->has($id) for the existence check so null-valued entries are handled
 * correctly and those series are not redundantly pushed into the insert
 * buffer on every sync.
 */

use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function makeXtreamSeriesPlaylist(User $user): Playlist
{
    return Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.test',
            'username' => 'user',
            'password' => 'pass',
        ],
    ]));
}

function runChunk(Playlist $playlist, int $categoryId, string $categoryName, array $seriesItems, string $batchNo = 'test-batch'): void
{
    Http::fake([
        'xtream.test/player_api.php*' => Http::response(json_encode($seriesItems), 200),
    ]);

    (new ProcessM3uImportSeriesChunk(
        payload: ['playlistId' => $playlist->id, 'categoryId' => $categoryId, 'categoryName' => $categoryName],
        batchCount: 2,
        batchNo: $batchNo,
        index: 1,
    ))->handle();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = makeXtreamSeriesPlaylist($this->user);
});

it('does not re-insert a series whose last_modified is NULL on subsequent syncs', function () {
    // First sync: series inserted with no last_modified (null)
    runChunk($this->playlist, 1, 'Drama', [
        ['series_id' => 200, 'name' => 'Null Date Show'],
    ], 'batch-1');

    $this->assertDatabaseCount('series', 1);

    // Second sync: same item still has no last_modified
    runChunk($this->playlist, 1, 'Drama', [
        ['series_id' => 200, 'name' => 'Null Date Show'],
    ], 'batch-2');

    // Series must still exist exactly once (no duplicate attempt)
    $this->assertDatabaseCount('series', 1);
});

it('updates last_modified when the provider changes it', function () {
    runChunk($this->playlist, 1, 'Thriller', [
        ['series_id' => 300, 'name' => 'Ticking Clock', 'last_modified' => 1700000000],
    ]);

    $original = Series::where('source_series_id', 300)->value('last_modified');
    expect($original)->not->toBeNull();

    // Provider updates the timestamp
    runChunk($this->playlist, 1, 'Thriller', [
        ['series_id' => 300, 'name' => 'Ticking Clock', 'last_modified' => 1700000999],
    ]);

    $updated = Series::where('source_series_id', 300)->value('last_modified');
    expect($updated)->not->toBe($original);
});

it('imports new series and links them to the correct category', function () {
    runChunk($this->playlist, 1, 'Action', [
        ['series_id' => 100, 'name' => 'Show A', 'num' => 1],
        ['series_id' => 101, 'name' => 'Show B', 'num' => 2],
    ]);

    $this->assertDatabaseCount('categories', 1);
    $this->assertDatabaseCount('series', 2);

    $category = Category::first();
    expect(Series::where('category_id', $category->id)->count())->toBe(2);
});

it('skips a series category when the provider returns malformed utf-8 json', function () {
    Log::spy();

    Http::fake([
        'xtream.test/player_api.php*' => Http::response("[{\"series_id\":400,\"name\":\"Bad \xB1 Name\"}]", 200),
    ]);

    (new ProcessM3uImportSeriesChunk(
        payload: ['playlistId' => $this->playlist->id, 'categoryId' => 9, 'categoryName' => 'Bad Data'],
        batchCount: 2,
        batchNo: 'bad-utf8-batch',
        index: 1,
    ))->handle();

    $this->assertDatabaseCount('series', 0);

    Log::shouldHaveReceived('warning')
        ->with('ProcessM3uImportSeriesChunk: Malformed JSON response for series category, skipping', Mockery::on(
            fn (array $context): bool => $context['playlist_id'] === $this->playlist->id
                && $context['source_category_id'] === 9
                && $context['source_category_name'] === 'Bad Data'
                && $context['phase'] === 'series_category_streams'
        ))
        ->once();
});

it('skips a series category when the provider returns malformed json', function () {
    Log::spy();

    Http::fake([
        'xtream.test/player_api.php*' => Http::response('[}', 200),
    ]);

    (new ProcessM3uImportSeriesChunk(
        payload: ['playlistId' => $this->playlist->id, 'categoryId' => 10, 'categoryName' => 'Malformed Data'],
        batchCount: 2,
        batchNo: 'bad-json-batch',
        index: 1,
    ))->handle();

    $this->assertDatabaseCount('series', 0);

    Log::shouldHaveReceived('warning')
        ->with('ProcessM3uImportSeriesChunk: Malformed JSON response for series category, skipping', Mockery::on(
            fn (array $context): bool => $context['playlist_id'] === $this->playlist->id
                && $context['source_category_id'] === 10
                && $context['source_category_name'] === 'Malformed Data'
                && $context['phase'] === 'series_category_streams'
        ))
        ->once();
});
