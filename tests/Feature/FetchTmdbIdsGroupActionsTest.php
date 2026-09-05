<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\VodGroups\Pages\EditVodGroup;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Jobs\FetchTmdbIds;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::swap(new Repository(new ArrayStore));

    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();

    mockTmdbApiKeyForActions();
});

function mockTmdbApiKeyForActions(?string $apiKey = 'fake-api-key'): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->shouldReceive('getAttribute')->with('tmdb_api_key')->andReturn($apiKey);
    $mock->tmdb_api_key = $apiKey;
    app()->instance(GeneralSettings::class, $mock);
}

function vodGroup(User $user, Playlist $playlist, array $attributes = []): Group
{
    return Group::factory()->for($user)->for($playlist)->create(array_merge(['type' => 'vod'], $attributes));
}

function seriesCategory(User $user, Playlist $playlist, array $attributes = []): Category
{
    return Category::factory()->for($user)->for($playlist)->create($attributes);
}

it('dispatches FetchTmdbIds from the VOD group record action', function () {
    $group = vodGroup($this->user, $this->playlist, ['name' => 'Movies', 'name_internal' => 'Movies']);

    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($group), ['overwrite_existing' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('TMDB metadata fetch started');

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($group) {
        return $job->vodGroupIds === [$group->id]
            && $job->seriesCategoryIds === null
            && $job->overwriteExisting === true
            && $job->user?->is($this->user);
    });
});

it('dispatches FetchTmdbIds from the VOD group bulk action with overwrite off', function () {
    $g1 = vodGroup($this->user, $this->playlist, ['name' => 'A']);
    $g2 = vodGroup($this->user, $this->playlist, ['name' => 'B']);

    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->callTableBulkAction('fetch_tmdb_ids', [$g1, $g2], ['overwrite_existing' => false])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('TMDB metadata fetch started');

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($g1, $g2) {
        return $job->vodGroupIds !== null
            && collect($job->vodGroupIds)->sort()->values()->all() === collect([$g1->id, $g2->id])->sort()->values()->all()
            && $job->seriesCategoryIds === null
            && $job->overwriteExisting === false;
    });
});

it('dispatches FetchTmdbIds from the EditVodGroup header action', function () {
    $group = vodGroup($this->user, $this->playlist, ['name' => 'Movies', 'name_internal' => 'Movies']);

    Livewire::test(EditVodGroup::class, ['record' => $group->id])
        ->callAction('fetch_tmdb_ids', ['overwrite_existing' => false])
        ->assertHasNoActionErrors()
        ->assertNotified('TMDB metadata fetch started');

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($group) {
        return $job->vodGroupIds === [$group->id]
            && $job->seriesCategoryIds === null
            && $job->overwriteExisting === false
            && $job->user?->is($this->user);
    });
});

it('dispatches FetchTmdbIds from the series category record action', function () {
    $category = seriesCategory($this->user, $this->playlist, ['name' => 'Shows', 'name_internal' => 'Shows']);

    Livewire::test(ListCategories::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($category), ['overwrite_existing' => true])
        ->assertHasNoActionErrors()
        ->assertNotified('TMDB metadata fetch started');

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($category) {
        return $job->seriesCategoryIds === [$category->id]
            && $job->vodGroupIds === null
            && $job->overwriteExisting === true
            && $job->user?->is($this->user);
    });
});

it('dispatches FetchTmdbIds from the series category bulk action', function () {
    $c1 = seriesCategory($this->user, $this->playlist, ['name' => 'A']);
    $c2 = seriesCategory($this->user, $this->playlist, ['name' => 'B']);

    Livewire::test(ListCategories::class)
        ->loadTable()
        ->callTableBulkAction('fetch_tmdb_ids', [$c1, $c2], ['overwrite_existing' => false])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('TMDB metadata fetch started');

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($c1, $c2) {
        return $job->seriesCategoryIds !== null
            && collect($job->seriesCategoryIds)->sort()->values()->all() === collect([$c1->id, $c2->id])->sort()->values()->all()
            && $job->vodGroupIds === null
            && $job->overwriteExisting === false;
    });
});

it('dispatches FetchTmdbIds from the EditCategory header action', function () {
    $category = seriesCategory($this->user, $this->playlist, ['name' => 'Shows', 'name_internal' => 'Shows']);

    Livewire::test(EditCategory::class, ['record' => $category->id])
        ->callAction('fetch_tmdb_ids', ['overwrite_existing' => false])
        ->assertHasNoActionErrors()
        ->assertNotified('TMDB metadata fetch started');

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($category) {
        return $job->seriesCategoryIds === [$category->id]
            && $job->vodGroupIds === null
            && $job->overwriteExisting === false
            && $job->user?->is($this->user);
    });
});

it('does not dispatch FetchTmdbIds when the TMDB API key is missing', function () {
    mockTmdbApiKeyForActions(null);

    $group = vodGroup($this->user, $this->playlist, ['name' => 'Movies', 'name_internal' => 'Movies']);

    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($group), ['overwrite_existing' => false])
        ->assertNotified('TMDB API Key Required');

    Bus::assertNotDispatched(FetchTmdbIds::class);
});

it('does not show the "lookup started" notification when the TMDB API key is missing', function () {
    mockTmdbApiKeyForActions(null);

    $group = vodGroup($this->user, $this->playlist, ['name' => 'Movies', 'name_internal' => 'Movies']);

    // The guard must halt the action, otherwise Filament still fires the
    // configured success notification for a lookup that never started.
    // Filament's assertion helpers pull (and clear) session notifications on
    // read, so this negative assertion stands alone rather than chained after
    // assertNotified(), which would consume the bag first and no-op this check.
    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->callAction(TestAction::make('fetch_tmdb_ids')->table($group), ['overwrite_existing' => false])
        ->assertNotNotified('TMDB metadata fetch started');

    Bus::assertNotDispatched(FetchTmdbIds::class);
});
