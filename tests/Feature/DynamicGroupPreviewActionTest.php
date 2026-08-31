<?php

use App\Enums\Status;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The PlaylistUpdated listener dispatches into the sync pipeline, which
    // talks to Redis. We don't want any of that during these tests.
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Bind TmdbService via a singleton so the preview's app() helper resolves
    // to a service that thinks it's configured. The constructor pulls from
    // GeneralSettings, so we set the api key on the singleton directly.
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;

    app()->instance(GeneralSettings::class, $settings);
    app()->instance(TmdbService::class, new TmdbService($settings));

    // TmdbService::waitForRateLimit() touches RateLimiter — fake it so the
    // tests don't need a live Redis connection.
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);

    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'dynamic_groups_config' => null,
    ]);

    $this->trendingVodRule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'tmdb_params' => ['time_window' => 'week'],
    ];
});

// ──────────────────────────────────────────────────────────────────────────────
// Preview data (drives the modal content view)
// ──────────────────────────────────────────────────────────────────────────────

it('lists matched playlist entries and unmatched TMDB titles for a VOD trending rule', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
                ['id' => 200, 'title' => 'Cold Movie', 'media_type' => 'movie', 'release_date' => '2024-02-02'],
            ],
        ], 200),
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '100',
        'name' => 'Hot Movie 1080p',
    ]);
    // Same tmdb_id but a different playlist — must not leak into the preview.
    $otherPlaylist = Playlist::factory()->for($this->user)->createQuietly(['status' => Status::Completed]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $otherPlaylist->id,
        'is_vod' => true,
        'tmdb_id' => '100',
        'name' => 'Other Playlist Copy',
    ]);
    // Live channel with a matching tmdb_id — VOD rules must ignore it.
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => false,
        'tmdb_id' => '100',
        'name' => 'Live Channel Copy',
    ]);

    $data = PlaylistResource::getDynamicGroupPreviewData($this->trendingVodRule, $this->playlist);

    expect($data['error'])->toBeNull()
        ->and($data['tmdbTotal'])->toBe(2)
        ->and($data['matched'])->toBe(['Hot Movie 1080p'])
        ->and($data['matchedTotal'])->toBe(1)
        ->and($data['unmatchedTotal'])->toBe(1)
        ->and($data['unmatched'][0]['title'])->toBe('Cold Movie');

    $html = view('filament.forms.dynamic-group-preview', $data)->render();

    expect($html)->toContain('Hot Movie 1080p')
        ->toContain('Cold Movie')
        ->toContain('1 entry matched');
});

it('lists matched series for a series rule and prefers name_custom for VOD', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/tv/week*' => Http::response([
            'results' => [
                ['id' => 300, 'name' => 'Hot Show', 'media_type' => 'tv', 'first_air_date' => '2024-01-01'],
            ],
        ], 200),
    ]);

    Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'tmdb_id' => '300',
        'name' => 'Hot Show Library Copy',
    ]);

    $data = PlaylistResource::getDynamicGroupPreviewData([
        'enabled' => true,
        'type' => 'series',
        'source' => 'trending',
        'name' => 'Trending Shows',
        'tmdb_params' => ['time_window' => 'week'],
    ], $this->playlist);

    expect($data['error'])->toBeNull()
        ->and($data['matched'])->toBe(['Hot Show Library Copy'])
        ->and($data['unmatchedTotal'])->toBe(0);
});

it('explains when TMDB is not configured', function () {
    $settings = app(GeneralSettings::class);
    $settings->tmdb_api_key = null;
    app()->instance(TmdbService::class, new TmdbService($settings));

    $data = PlaylistResource::getDynamicGroupPreviewData($this->trendingVodRule, $this->playlist);

    expect($data['error'])->toContain('TMDB is not configured');

    $html = view('filament.forms.dynamic-group-preview', $data)->render();
    expect($html)->toContain('TMDB is not configured');
});

it('explains when the rule is incomplete', function () {
    $data = PlaylistResource::getDynamicGroupPreviewData([
        'enabled' => true,
        'type' => 'vod',
        'source' => null,
        'name' => 'Incomplete',
    ], $this->playlist);

    expect($data['error'])->toContain('Select a content type and source first');
});

it('explains when TMDB returns no titles for the rule', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response(['results' => []], 200),
    ]);

    $data = PlaylistResource::getDynamicGroupPreviewData($this->trendingVodRule, $this->playlist);

    expect($data['error'])->toContain('TMDB returned no titles for this rule');
});

it('hints about TMDB matching when TMDB titles exist but nothing in the playlist matches', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
            ],
        ], 200),
    ]);

    $data = PlaylistResource::getDynamicGroupPreviewData($this->trendingVodRule, $this->playlist);

    expect($data['error'])->toBeNull()
        ->and($data['matchedTotal'])->toBe(0);

    $html = view('filament.forms.dynamic-group-preview', $data)->render();
    expect($html)->toContain('0 entries matched')
        ->toContain('Entries match by TMDB ID');
});

// ──────────────────────────────────────────────────────────────────────────────
// The action itself (mounts from the edit form's repeater)
// ──────────────────────────────────────────────────────────────────────────────

it('mounts the preview action from the dynamic groups repeater on the edit page', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
            ],
        ], 200),
    ]);

    $this->playlist->updateQuietly(['dynamic_groups_config' => [$this->trendingVodRule]]);

    $component = Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id]);
    $itemKey = array_key_first($component->get('data')['dynamic_groups_config']);

    $component
        ->mountAction(
            TestAction::make('preview_dynamic_group')
                ->schemaComponent('dynamic_groups_config')
                ->arguments(['item' => $itemKey]),
        )
        ->assertSuccessful();
});
