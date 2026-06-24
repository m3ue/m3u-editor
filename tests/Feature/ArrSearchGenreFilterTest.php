<?php

use App\Livewire\ArrSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('returns all results when no genre is selected', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Show A', 'genres' => ['Drama', 'Action']],
        1 => ['title' => 'Show B', 'genres' => ['Comedy']],
    ]);

    expect($component->get('filteredResults'))->toHaveCount(2);
});

it('filters results to only those matching a selected genre', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Show A', 'genres' => ['Drama', 'Action']],
        1 => ['title' => 'Show B', 'genres' => ['Comedy']],
        2 => ['title' => 'Show C', 'genres' => ['Drama']],
    ]);

    $component->call('toggleGenre', 'Drama');

    $filtered = $component->get('filteredResults');

    expect($filtered)->toHaveCount(2);
    expect(array_values($filtered)[0]['title'])->toBe('Show A');
    expect(array_values($filtered)[1]['title'])->toBe('Show C');
});

it('uses OR logic so results matching any selected genre are shown', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Drama Show', 'genres' => ['Drama']],
        1 => ['title' => 'Comedy Show', 'genres' => ['Comedy']],
        2 => ['title' => 'Sci-Fi Show', 'genres' => ['Sci-Fi']],
    ]);

    $component->call('toggleGenre', 'Drama')
        ->call('toggleGenre', 'Comedy');

    $filtered = $component->get('filteredResults');

    expect($filtered)->toHaveCount(2);
    $titles = array_column(array_values($filtered), 'title');
    expect($titles)->toContain('Drama Show');
    expect($titles)->toContain('Comedy Show');
});

it('preserves original result indices so openDetail still resolves correctly', function () {
    $component = Livewire::test(ArrSearch::class);

    // Index 0 = Action-only (will be hidden), index 1 = Drama (visible)
    $component->set('results', [
        0 => ['title' => 'Action Show', 'genres' => ['Action']],
        1 => ['title' => 'Drama Show', 'genres' => ['Drama']],
    ]);

    $component->call('toggleGenre', 'Drama');

    $filtered = $component->get('filteredResults');

    // The surviving item must still carry key 1, not key 0.
    expect(array_key_exists(1, $filtered))->toBeTrue();
    expect(array_key_exists(0, $filtered))->toBeFalse();
});

it('returns sorted unique genres from the current result set', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Show A', 'genres' => ['Sci-Fi', 'Action']],
        1 => ['title' => 'Show B', 'genres' => ['Action', 'Drama']],
        2 => ['title' => 'Show C', 'genres' => ['Comedy']],
    ]);

    expect($component->get('availableGenres'))->toBe(['Action', 'Comedy', 'Drama', 'Sci-Fi']);
});

it('toggleGenre removes a genre that is already selected', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Show A', 'genres' => ['Drama']],
    ]);

    $component->call('toggleGenre', 'Drama');
    expect($component->get('selectedGenres'))->toContain('Drama');

    $component->call('toggleGenre', 'Drama');
    expect($component->get('selectedGenres'))->not->toContain('Drama');
});

it('clears selectedGenres when clearSearch is called', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('selectedGenres', ['Drama']);
    $component->call('clearSearch');

    expect($component->get('selectedGenres'))->toBe([]);
});
