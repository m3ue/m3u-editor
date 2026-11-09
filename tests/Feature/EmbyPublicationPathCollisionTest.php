<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
});

it('assigns distinct deterministic folders to different movie identities with colliding slugs', function (array $collidingTitles) {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Synthetic movies', 'type' => 'vod']);
    foreach ([...$collidingTitles, 'Unique Film'] as $index => $title) {
        Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
            'enabled' => true, 'is_vod' => true, 'title' => $title,
            'title_custom' => null, 'year' => 2026, 'tmdb_id' => 900001 + $index,
            'tvdb_id' => null, 'imdb_id' => null, 'info' => null,
            'movie_data' => null, 'url' => 'https://source.invalid/movie.mkv',
        ]);
    }
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group', 'source_identifier' => (string) $group->id,
        'source_label' => $group->name, 'collection_type' => 'movies',
        'options' => ['nfo' => true, 'versions' => true],
    ]);
    $service = app(EmbyPublicationCatalogService::class);
    $first = $service->buildMapping($mapping);
    $second = $service->buildMapping($mapping);
    $items = collect($first['items']);
    expect($items)->toHaveCount(3)
        ->and($items->pluck('relative_folder')->map('strtolower')->unique())->toHaveCount(3)
        ->and($items->pluck('base_filename')->map('strtolower')->unique())->toHaveCount(3)
        ->and($items->firstWhere('display_title', 'Unique Film')['relative_folder'])->toBe('unique-film-2026')
        ->and($first)->toBe($second);
})->with([
    'normalized titles' => [['Sample / Film', 'Sample Film']],
    'truncated titles' => [[str_repeat('a', 160).' First', str_repeat('a', 160).' Second']],
    'case-insensitive titles' => [['SAMPLE FILM', 'Sample Film']],
]);

it('keeps distinct series identities with colliding folders separate', function (array $collidingTitles) {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $category = Category::factory()->for($user)->for($playlist)->create(['name' => 'Synthetic series']);
    foreach ($collidingTitles as $index => $title) {
        Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
            'enabled' => true, 'name' => $title, 'tmdb_id' => 900101 + $index,
            'release_date' => '2026-01-01', 'metadata' => [],
        ]);
    }
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'series_category', 'source_identifier' => (string) $category->id,
        'source_label' => $category->name, 'collection_type' => 'tvshows',
    ]);
    $service = app(EmbyPublicationCatalogService::class);
    $catalog = $service->buildMapping($mapping);
    expect($catalog['items'])->toHaveCount(2)
        ->and(array_unique(array_column($catalog['items'], 'relative_folder')))->toHaveCount(2)
        ->and($catalog)->toBe($service->buildMapping($mapping));
})->with([
    'normalized titles' => [['Sample / Series', 'Sample Series']],
    'truncated titles' => [[str_repeat('a', 160).' First', str_repeat('a', 160).' Second']],
    'case-insensitive titles' => [['SAMPLE SERIES', 'Sample Series']],
]);

it('publishes every identity and variant while reserving original and generated suffix paths', function (string $mediaType) {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Synthetic', 'type' => 'vod']);
    $category = Category::factory()->for($user)->for($playlist)->create(['name' => 'Synthetic']);
    $reserved = 'sample-film-'.substr(hash('sha256', $mediaType.':tmdb:900001'), 0, 16);
    $titles = ['Sample / Film', 'Sample Film', 'Unique Film', $reserved, $reserved.'-1'];
    $sourceIds = [];
    foreach ($titles as $index => $title) {
        if ($mediaType === 'series') {
            $series = Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
                'enabled' => true, 'name' => $title, 'tmdb_id' => 900001 + $index,
                'tvdb_id' => null, 'imdb_id' => null, 'release_date' => null, 'metadata' => [],
            ]);
            $season = Season::factory()->for($user)->for($playlist)->for($category)->for($series)->createQuietly([
                'season_number' => 1,
            ]);
        }
        foreach ([720, 1080] as $height) {
            $attributes = [
                'enabled' => true, 'title' => $title, 'tmdb_id' => null,
                'url' => 'https://source.invalid/synthetic.mkv', 'container_extension' => 'mkv',
                'info' => [], 'stream_stats' => [['stream' => ['codec_type' => 'video', 'height' => $height]]],
            ];
            $source = $mediaType === 'movie'
                ? Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
                    ...$attributes, 'is_vod' => true, 'title_custom' => null,
                    'tmdb_id' => 900001 + $index, 'tvdb_id' => null, 'imdb_id' => null,
                    'year' => null, 'movie_data' => null,
                ])
                : Episode::factory()->for($user)->for($playlist)->for($series)->for($season)->createQuietly([
                    ...$attributes, 'season' => 1, 'episode_num' => 1,
                ]);
            $sourceIds[] = $source->id;
        }
    }
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby', 'enabled' => true]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => $mediaType === 'movie' ? 'vod_group' : 'series_category',
        'source_identifier' => (string) ($mediaType === 'movie' ? $group->id : $category->id),
        'source_label' => 'Synthetic', 'collection_type' => $mediaType === 'movie' ? 'movies' : 'tvshows',
        'enabled' => true, 'target_library_id' => 'synthetic-library',
        'options' => ['nfo' => true, 'versions' => true],
    ]);
    $service = app(EmbyPublicationCatalogService::class);
    $snapshot = $service->buildForUser($user, 'synthetic', 'synthetic');
    expect($snapshot['full_snapshot'])->toBeTrue()
        ->and($snapshot['mappings'])->toHaveCount(1)
        ->and($snapshot)->toBe($service->buildForUser($user, 'synthetic', 'synthetic'));
    $catalog = $snapshot['mappings'][0];
    $items = collect($catalog['items'])->keyBy('canonical_id');
    $expectedIds = array_map(fn (int $index): string => $mediaType.':tmdb:'.(900001 + $index), array_keys($titles));
    expect($items->keys()->all())->toBe($expectedIds)
        ->and($catalog['revision'])->toBe($mapping->fresh()->last_planned_revision);
    $expectedFolders = [
        $reserved.'-2',
        'sample-film-'.substr(hash('sha256', $mediaType.':tmdb:900002'), 0, 16),
        'unique-film', $reserved, $reserved.'-1',
    ];
    expect($items->pluck('relative_folder')->all())->toBe($expectedFolders)
        ->and($items->pluck('base_filename')->all())->toBe($expectedFolders);
    $paths = [];
    $publishedSourceIds = [];
    foreach ($items as $item) {
        $folder = $item['relative_folder'];
        $paths[] = $folder.($mediaType === 'movie' ? '/movie.nfo' : '/tvshow.nfo');
        if ($mediaType === 'series') {
            expect($item['episodes'])->toHaveCount(1);
        }
        $playable = $mediaType === 'movie' ? $item : $item['episodes'][0];
        if ($mediaType === 'series') {
            $folder .= '/'.$playable['relative_folder'];
            $paths[] = $folder.'/'.$playable['base_filename'].'.nfo';
        }
        expect($playable['variants'])->toHaveCount(2);
        foreach ($playable['variants'] as $variant) {
            $paths[] = $folder.'/'.$playable['base_filename'].' - '.$variant['key'].'.strm';
            $publishedSourceIds[] = $variant['preferred']['source_id'];
        }
    }
    sort($sourceIds);
    sort($publishedSourceIds);
    expect($publishedSourceIds)->toBe($sourceIds)
        ->and($paths)->toHaveCount($mediaType === 'movie' ? 15 : 20)
        ->and(array_unique(array_map('strtolower', $paths)))->toHaveCount(count($paths));
})->with(['movie', 'series']);
