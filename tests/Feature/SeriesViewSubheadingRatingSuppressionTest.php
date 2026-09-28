<?php

use App\Filament\GuestPanel\Resources\Series\Pages\ViewSeries as GuestViewSeries;
use App\Filament\Resources\Series\Pages\ViewSeries as AdminViewSeries;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('suppresses the admin subheading rating when vote count is below the threshold', function () {
    $series = Series::factory()->create([
        'rating' => '10',
        'metadata' => ['vote_count' => 2],
    ]);

    $page = new AdminViewSeries;
    $page->record = $series;

    expect((string) $page->getSubheading())->not->toContain('★');
});

it('shows the admin subheading rating when vote count meets the threshold', function () {
    $series = Series::factory()->create([
        'rating' => '8.5',
        'metadata' => ['vote_count' => 500],
    ]);

    $page = new AdminViewSeries;
    $page->record = $series;

    expect((string) $page->getSubheading())->toContain('★ 8.5');
});

it('suppresses the guest subheading rating when vote count is below the threshold', function () {
    $series = Series::factory()->create([
        'rating' => '10',
        'metadata' => ['vote_count' => 2],
    ]);

    $page = new GuestViewSeries;
    $page->record = $series;

    expect((string) $page->getSubheading())->not->toContain('★');
});

it('shows the guest subheading rating when vote count meets the threshold', function () {
    $series = Series::factory()->create([
        'rating' => '8.5',
        'metadata' => ['vote_count' => 500],
    ]);

    $page = new GuestViewSeries;
    $page->record = $series;

    expect((string) $page->getSubheading())->toContain('★ 8.5');
});
