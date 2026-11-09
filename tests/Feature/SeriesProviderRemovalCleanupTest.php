<?php

/**
 * Series the provider removed, or re-added under a new series_id, must not linger.
 *
 * Series used to be removed only together with their whole category, so a series
 * re-added with a new id left its old row behind as a duplicate. They are now
 * stamped with each sync's batch number and retired like channels are.
 */

use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Run a series sync: one chunk per category, then the completion job.
 *
 * @param  array<int, list<array<string, mixed>>>  $catalog  Provider series listing keyed by category id.
 */
function runSeriesRemovalSync(Playlist $playlist, array $catalog, string $batchNo): void
{
    // As ProcessM3uImport reads it, before this sync stamps the categories
    $previousBatchNo = Category::where('playlist_id', $playlist->id)
        ->whereNotNull('source_category_id')
        ->where('import_batch_no', '!=', $batchNo)
        ->max('import_batch_no');

    $index = 0;
    foreach ($catalog as $categoryId => $items) {
        test()->seriesFeed['items'] = $items;

        (new ProcessM3uImportSeriesChunk(
            payload: ['playlistId' => $playlist->id, 'categoryId' => $categoryId, 'categoryName' => "Category {$categoryId}"],
            batchCount: count($catalog),
            batchNo: $batchNo,
            index: $index++,
        ))->handle();
    }

    // The main import stamps the provider categories it dispatched chunks for
    Category::where('playlist_id', $playlist->id)->whereNotNull('source_category_id')->update(['import_batch_no' => $batchNo]);

    $settings = app(GeneralSettings::class);
    $settings->suppress_success_notifications = true;
    app()->instance(GeneralSettings::class, $settings);
    test()->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });

    (new ProcessM3uImportComplete(
        userId: $playlist->user_id,
        playlistId: $playlist->id,
        batchNo: $batchNo,
        start: Carbon::now()->subMinute(),
        runningLiveImport: false,
        runningVodImport: false,
        runningSeriesImport: true,
        previousSeriesBatchNo: $previousBatchNo,
    ))->handle($settings);
}

function seriesRemovalNames(Playlist $playlist): array
{
    return $playlist->series()->orderBy('source_series_id')->pluck('name', 'source_series_id')->all();
}

beforeEach(function () {
    config(['dev.disable_sync_logs' => true]);

    // Mutable provider response: a second Http::fake() would never replace the first stub
    $this->seriesFeed = new ArrayObject(['items' => []]);
    $feed = $this->seriesFeed;
    Http::fake(['xtream.test/player_api.php*' => fn () => Http::response(json_encode($feed['items']), 200)]);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'user', 'password' => 'pass'],
    ]));
});

it('removes the old row when the provider re-adds a series under a new id', function () {
    runSeriesRemovalSync($this->playlist, [1 => [
        ['series_id' => 100, 'name' => 'Show A'],
        ['series_id' => 101, 'name' => 'Show B'],
    ]], 'b1');

    runSeriesRemovalSync($this->playlist, [1 => [
        ['series_id' => 900, 'name' => 'Show A'],
        ['series_id' => 101, 'name' => 'Show B'],
    ]], 'b2');

    expect(seriesRemovalNames($this->playlist))->toBe([101 => 'Show B', 900 => 'Show A']);
});

it('removes a series the provider dropped while its category survives', function () {
    runSeriesRemovalSync($this->playlist, [1 => [
        ['series_id' => 100, 'name' => 'Show A'],
        ['series_id' => 101, 'name' => 'Show B'],
    ]], 'b1');

    runSeriesRemovalSync($this->playlist, [1 => [['series_id' => 100, 'name' => 'Show A']]], 'b2');

    expect(seriesRemovalNames($this->playlist))->toBe([100 => 'Show A']);
});

it('keeps listed series and their user state across syncs', function () {
    runSeriesRemovalSync($this->playlist, [1 => [['series_id' => 100, 'name' => 'Show A']]], 'b1');
    Series::where('source_series_id', 100)->update(['enabled' => false]);

    runSeriesRemovalSync($this->playlist, [1 => [['series_id' => 100, 'name' => 'Show A']]], 'b2');

    $series = Series::where('source_series_id', 100)->sole();
    expect($series->enabled)->toBeFalse()
        ->and($series->import_batch_no)->toBe('b2');
});

it('moves a series the provider moved to another category instead of deleting it', function () {
    runSeriesRemovalSync($this->playlist, [
        1 => [['series_id' => 100, 'name' => 'Show A'], ['series_id' => 150, 'name' => 'Show D']],
        2 => [['series_id' => 200, 'name' => 'Show C']],
    ], 'b1');
    $original = Series::where('source_series_id', 100)->sole();
    $original->update(['enabled' => false]);

    runSeriesRemovalSync($this->playlist, [
        1 => [['series_id' => 150, 'name' => 'Show D']],
        2 => [['series_id' => 200, 'name' => 'Show C'], ['series_id' => 100, 'name' => 'Show A']],
    ], 'b2');

    $moved = Series::where('source_series_id', 100)->sole();
    expect($moved->id)->toBe($original->id)
        ->and($moved->enabled)->toBeFalse()
        ->and($moved->source_category_id)->toEqual(2)
        ->and($moved->category->source_category_id)->toEqual(2);
});

it('keeps a series listed under several categories in the first one that lists it', function () {
    $catalog = [
        1 => [['series_id' => 100, 'name' => 'Show A']],
        2 => [['series_id' => 100, 'name' => 'Show A']],
    ];

    runSeriesRemovalSync($this->playlist, $catalog, 'b1');
    runSeriesRemovalSync($this->playlist, $catalog, 'b2');

    $series = Series::where('source_series_id', 100)->sole();
    expect($series->source_category_id)->toEqual(1);
});

it('keeps every series of a category the provider returned empty', function () {
    runSeriesRemovalSync($this->playlist, [1 => [
        ['series_id' => 100, 'name' => 'Show A'],
        ['series_id' => 101, 'name' => 'Show B'],
    ]], 'b1');

    runSeriesRemovalSync($this->playlist, [1 => []], 'b2');

    expect(seriesRemovalNames($this->playlist))->toBe([100 => 'Show A', 101 => 'Show B']);
});

it('removes a dropped series that was moved into a genre or custom category', function () {
    runSeriesRemovalSync($this->playlist, [1 => [
        ['series_id' => 100, 'name' => 'Show A'],
        ['series_id' => 101, 'name' => 'Show B'],
    ]], 'b1');

    // A genre/custom category: no provider id and never stamped by the import
    $genre = Category::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Drama',
        'name_internal' => 'Drama',
    ]);
    Series::where('playlist_id', $this->playlist->id)->update(['category_id' => $genre->id]);

    runSeriesRemovalSync($this->playlist, [1 => [['series_id' => 100, 'name' => 'Show A']]], 'b2');

    expect(seriesRemovalNames($this->playlist))->toBe([100 => 'Show A'])
        ->and(Series::where('source_series_id', 100)->sole()->category_id)->toBe($genre->id);
});

describe('removed-series threshold', function () {
    beforeEach(function () {
        config(['dev.invalidate_import' => true, 'dev.invalidate_import_series_threshold' => 1]);
    });

    it('does not count series no listing returned before the previous sync', function () {
        $catalog = fn (array $ids) => [1 => array_map(fn (int $id) => ['series_id' => $id, 'name' => "Show {$id}"], $ids)];
        runSeriesRemovalSync($this->playlist, $catalog([100, 101, 102]), 'b1');

        // Left over from before series were re-stamped each sync: never listed since
        Series::whereIn('source_series_id', [101, 102])->update(['import_batch_no' => 'a0']);

        runSeriesRemovalSync($this->playlist, $catalog([100]), 'b2');

        expect(seriesRemovalNames($this->playlist))->toBe([100 => 'Show 100']);
    });

    it('still invalidates a sync that drops what the previous sync listed', function () {
        $catalog = fn (array $ids) => [1 => array_map(fn (int $id) => ['series_id' => $id, 'name' => "Show {$id}"], $ids)];
        runSeriesRemovalSync($this->playlist, $catalog([100, 101, 102]), 'b1');

        runSeriesRemovalSync($this->playlist, $catalog([100]), 'b2');

        expect(seriesRemovalNames($this->playlist))->toBe([100 => 'Show 100', 101 => 'Show 101', 102 => 'Show 102']);
    });
});

it('never removes locally created series', function () {
    runSeriesRemovalSync($this->playlist, [1 => [['series_id' => 100, 'name' => 'Show A']]], 'b1');
    $category = Category::where('playlist_id', $this->playlist->id)->sole();

    $dvr = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'import_batch_no' => 'dvr',
    ]);
    $local = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'import_batch_no' => 'manual',
        'source_series_id' => null,
    ]);

    runSeriesRemovalSync($this->playlist, [1 => [['series_id' => 100, 'name' => 'Show A']]], 'b2');

    expect(Series::find($dvr->id))->not->toBeNull()
        ->and(Series::find($local->id))->not->toBeNull();
});
