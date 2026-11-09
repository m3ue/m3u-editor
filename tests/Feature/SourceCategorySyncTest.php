<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Bouquet;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

function runSyncSourceCategories(Playlist $playlist, Collection $categories): ProcessM3uImport
{
    $job = new ProcessM3uImport($playlist, force: true, isNew: false);
    (new ReflectionMethod($job, 'syncSourceCategories'))->invoke($job, $categories, $playlist);

    return $job;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'import_prefs' => ['selected_categories' => ['Comedy']],
    ]));
});

it('prunes source categories the provider no longer lists', function () {
    SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Comedy', 'source_category_id' => 10035]);
    SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Drama', 'source_category_id' => 10018]);

    runSyncSourceCategories($this->playlist, collect([
        ['category_id' => '311', 'category_name' => 'Comedy'],
        ['category_id' => '10018', 'category_name' => 'Drama'],
    ]));

    expect(SourceCategory::where('playlist_id', $this->playlist->id)->orderBy('source_category_id')->pluck('name', 'source_category_id')->all())
        ->toBe([311 => 'Comedy', 10018 => 'Drama']);
});

it('leaves other playlists alone', function () {
    $other = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    SourceCategory::create(['playlist_id' => $other->id, 'name' => 'Comedy', 'source_category_id' => 1]);

    runSyncSourceCategories($this->playlist, collect([['category_id' => 311, 'category_name' => 'Comedy']]));

    expect(SourceCategory::where('playlist_id', $other->id)->count())->toBe(1);
});

it('adds new categories and does not duplicate existing ones', function () {
    $feed = collect([['category_id' => 1, 'category_name' => 'Comedy'], ['category_id' => 2, 'category_name' => 'Drama']]);

    runSyncSourceCategories($this->playlist, $feed);
    runSyncSourceCategories($this->playlist, $feed);

    expect(SourceCategory::where('playlist_id', $this->playlist->id)->count())->toBe(2);
});

it('carries a provider rename into selections, bouquets and aliases', function () {
    SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Comedy', 'source_category_id' => 1]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_categories' => ['Comedy', 'Drama']],
    ]);
    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'uuid' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'xtream_config' => null,
        'group_filter' => ['selected_categories' => ['Comedy']],
    ]);

    $job = runSyncSourceCategories($this->playlist, collect([['category_id' => 1, 'category_name' => 'Stand-up']]));

    expect(SourceCategory::where('playlist_id', $this->playlist->id)->pluck('name')->all())->toBe(['Stand-up'])
        ->and($this->playlist->fresh()->import_prefs['selected_categories'])->toBe(['Stand-up'])
        ->and($job->selectedCategories)->toBe(['Stand-up'])
        ->and($bouquet->fresh()->group_selections['selected_categories'])->toBe(['Stand-up', 'Drama'])
        ->and($alias->fresh()->group_filter['selected_categories'])->toBe(['Stand-up']);
});
