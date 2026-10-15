<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Filament\Resources\CachedContentFiles\Pages\ListCachedContentFiles;
use App\Filament\Resources\CachedContentFiles\Widgets\CachedContentStatsOverview;
use App\Models\CachedContentFile;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $gigabyte = 1024 ** 3;
    $file = fn (array $attributes, ?User $owner = null) => CachedContentFile::factory()->create([
        'user_id' => ($owner ?? $this->user)->id,
        ...$attributes,
    ]);

    $file(['status' => CachedContentFileStatus::Completed, 'content_type' => 'movie', 'file_size_bytes' => $gigabyte, 'managed_by' => CachedContentManagedBy::DynamicGroup]);
    $file(['status' => CachedContentFileStatus::Completed, 'content_type' => 'episode', 'file_size_bytes' => $gigabyte / 2]);
    $file(['status' => CachedContentFileStatus::Downloading, 'content_type' => 'movie']);
    $file(['status' => CachedContentFileStatus::Pending, 'content_type' => 'episode']);
    $file(['status' => CachedContentFileStatus::Failed, 'content_type' => 'movie']);
    // Another user's file: hidden from a non-admin, like the table.
    $file(['status' => CachedContentFileStatus::Completed, 'content_type' => 'movie', 'file_size_bytes' => $gigabyte], User::factory()->create());
});

/**
 * @return array<string, array{value: string, description: string}>
 */
function cachedContentStats(array $parameters = []): array
{
    $widget = Livewire::test(CachedContentStatsOverview::class, $parameters)->instance();

    return collect((fn (): array => $this->getStats())->call($widget))
        ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => [
            'value' => (string) $stat->getValue(),
            'description' => (string) $stat->getDescription(),
        ]])
        ->all();
}

it('shows the stats widget above the cached downloads table', function () {
    Livewire::test(ListCachedContentFiles::class)
        ->assertSeeLivewire(CachedContentStatsOverview::class);
});

it('summarizes the user\'s own cached downloads', function () {
    expect(cachedContentStats())->toBe([
        'Cached' => ['value' => '2', 'description' => '1 movies · 1 episodes'],
        'Storage Used' => ['value' => '1.5 GB', 'description' => 'Auto 1.0 GB · Manual 512.0 MB'],
        'In Progress' => ['value' => '2', 'description' => '1 downloading · 1 pending'],
        'Failed' => ['value' => '1', 'description' => 'Filter by Failed status to review them'],
    ]);
});

it('includes every user\'s downloads for an admin', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect(cachedContentStats()['Cached']['value'])->toBe('3')
        ->and(cachedContentStats()['Storage Used']['value'])->toBe('2.5 GB');
});

it('follows the table filters', function () {
    $stats = cachedContentStats(['tableFilters' => ['content_type' => ['value' => 'episode']]]);

    expect($stats['Cached'])->toBe(['value' => '1', 'description' => '0 movies · 1 episodes'])
        ->and($stats['Storage Used']['value'])->toBe('512.0 MB')
        ->and($stats['In Progress']['description'])->toBe('0 downloading · 1 pending')
        ->and($stats['Failed'])->toBe(['value' => '0', 'description' => 'Nothing has failed']);
});
