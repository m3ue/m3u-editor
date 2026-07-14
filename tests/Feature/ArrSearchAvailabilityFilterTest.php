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

it('returns all results when availability is null (default)', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Movie A', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Movie B', 'existsInLibrary' => true, 'hasFile' => false, 'integrationType' => 'radarr'],
        2 => ['title' => 'Show C', 'existsInLibrary' => false, 'hasFile' => false, 'integrationType' => 'sonarr'],
    ]);

    expect($component->get('filteredResults'))->toHaveCount(3);
});

it('filters results to only available items (hasFile for Radarr)', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Movie A', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Movie B', 'existsInLibrary' => true, 'hasFile' => false, 'integrationType' => 'radarr'],
        2 => ['title' => 'Movie C', 'existsInLibrary' => false, 'integrationType' => 'radarr'],
    ]);

    $component->call('setAvailability', 'available');

    $filtered = $component->get('filteredResults');
    expect($filtered)->toHaveCount(1);
    expect(array_values($filtered)[0]['title'])->toBe('Movie A');
});

it('filters results to only available items (episodeFileCount > 0 for Sonarr)', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Show A', 'existsInLibrary' => true, 'episodeFileCount' => 5, 'totalEpisodeCount' => 10, 'integrationType' => 'sonarr'],
        1 => ['title' => 'Show B', 'existsInLibrary' => true, 'episodeFileCount' => 0, 'totalEpisodeCount' => 10, 'integrationType' => 'sonarr'],
        2 => ['title' => 'Show C', 'existsInLibrary' => false, 'integrationType' => 'sonarr'],
    ]);

    $component->call('setAvailability', 'available');

    $filtered = $component->get('filteredResults');
    expect($filtered)->toHaveCount(1);
    expect(array_values($filtered)[0]['title'])->toBe('Show A');
});

it('filters results to in-library items that have no files', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Movie A', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Movie B', 'existsInLibrary' => true, 'hasFile' => false, 'integrationType' => 'radarr'],
        2 => ['title' => 'Movie C', 'existsInLibrary' => false, 'integrationType' => 'radarr'],
        3 => ['title' => 'Show D', 'existsInLibrary' => true, 'episodeFileCount' => 0, 'integrationType' => 'sonarr'],
    ]);

    $component->call('setAvailability', 'in_library');

    $filtered = $component->get('filteredResults');
    expect($filtered)->toHaveCount(2);
    $titles = array_column(array_values($filtered), 'title');
    expect($titles)->toContain('Movie B');
    expect($titles)->toContain('Show D');
});

it('filters results to items not yet in the library', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Movie A', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Movie B', 'existsInLibrary' => false, 'integrationType' => 'radarr'],
        2 => ['title' => 'Show C', 'existsInLibrary' => false, 'integrationType' => 'sonarr'],
        3 => ['title' => 'Show D', 'existsInLibrary' => true, 'episodeFileCount' => 0, 'integrationType' => 'sonarr'],
    ]);

    $component->call('setAvailability', 'missing');

    $filtered = $component->get('filteredResults');
    expect($filtered)->toHaveCount(2);
    $titles = array_column(array_values($filtered), 'title');
    expect($titles)->toContain('Movie B');
    expect($titles)->toContain('Show C');
});

it('treats empty string as "all" when setAvailability is called', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('availability', 'available');
    expect($component->get('availability'))->toBe('available');

    $component->call('setAvailability', '');
    expect($component->get('availability'))->toBeNull();
});

it('treats null as "all" when setAvailability is called', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('availability', 'missing');
    $component->call('setAvailability', null);

    expect($component->get('availability'))->toBeNull();
});

it('combines availability filter with genre filter using AND logic', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Drama A', 'genres' => ['Drama'], 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Drama B', 'genres' => ['Drama'], 'existsInLibrary' => false, 'integrationType' => 'radarr'],
        2 => ['title' => 'Drama C', 'genres' => ['Drama'], 'existsInLibrary' => true, 'hasFile' => false, 'integrationType' => 'radarr'],
        3 => ['title' => 'Action D', 'genres' => ['Action'], 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
    ]);

    $component->call('toggleGenre', 'Drama');
    $component->call('setAvailability', 'available');

    $filtered = $component->get('filteredResults');
    expect($filtered)->toHaveCount(1);
    expect(array_values($filtered)[0]['title'])->toBe('Drama A');
});

it('preserves original result indices so openDetail still resolves correctly', function () {
    $component = Livewire::test(ArrSearch::class);

    // Index 0 = available, index 1 = missing, index 2 = in-library
    $component->set('results', [
        0 => ['title' => 'Available Item', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Missing Item', 'existsInLibrary' => false, 'integrationType' => 'radarr'],
        2 => ['title' => 'In Library Item', 'existsInLibrary' => true, 'hasFile' => false, 'integrationType' => 'radarr'],
    ]);

    $component->call('setAvailability', 'missing');

    $filtered = $component->get('filteredResults');

    expect(array_key_exists(1, $filtered))->toBeTrue();
    expect(array_key_exists(0, $filtered))->toBeFalse();
    expect(array_key_exists(2, $filtered))->toBeFalse();
});

it('counts results correctly across all availability buckets', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('results', [
        0 => ['title' => 'Avail 1', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
        1 => ['title' => 'Avail 2', 'existsInLibrary' => true, 'episodeFileCount' => 3, 'integrationType' => 'sonarr'],
        2 => ['title' => 'InLib 1', 'existsInLibrary' => true, 'hasFile' => false, 'integrationType' => 'radarr'],
        3 => ['title' => 'InLib 2', 'existsInLibrary' => true, 'episodeFileCount' => 0, 'integrationType' => 'sonarr'],
        4 => ['title' => 'Miss 1', 'existsInLibrary' => false, 'integrationType' => 'radarr'],
    ]);

    $counts = $component->get('availabilityCounts');

    expect($counts['all'])->toBe(5);
    expect($counts['available'])->toBe(2);
    expect($counts['in_library'])->toBe(2);
    expect($counts['missing'])->toBe(1);
});

it('clears availability when clearSearch is called', function () {
    $component = Livewire::test(ArrSearch::class);

    $component->set('availability', 'available');
    $component->call('clearSearch');

    expect($component->get('availability'))->toBeNull();
});

it('handles results with missing fields gracefully (defaults to missing bucket)', function () {
    $component = Livewire::test(ArrSearch::class);

    // Minimal result with no availability fields at all (should not crash)
    $component->set('results', [
        0 => ['title' => 'Bare Item', 'integrationType' => 'radarr'],
        1 => ['title' => 'With Library', 'existsInLibrary' => true, 'hasFile' => true, 'integrationType' => 'radarr'],
    ]);

    $component->call('setAvailability', 'missing');

    $filtered = $component->get('filteredResults');
    expect($filtered)->toHaveCount(1);
    expect(array_values($filtered)[0]['title'])->toBe('Bare Item');
});
