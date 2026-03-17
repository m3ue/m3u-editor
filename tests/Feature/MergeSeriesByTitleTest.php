<?php

use App\Jobs\MergeSeries;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Notification::fake();
});

test('it merges series episodes by title similarity across providers', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider 1']);
    $playlist2 = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider 2']);

    // Same series from different providers
    $series1 = Series::factory()->create([
        'name' => 'DK | Breaking Bad',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
    ]);

    $series2 = Series::factory()->create([
        'name' => 'SC - Breaking Bad (2008)',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
    ]);

    $season1 = Season::factory()->create([
        'series_id' => $series1->id,
        'season_number' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
    ]);

    $season2 = Season::factory()->create([
        'series_id' => $series2->id,
        'season_number' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
    ]);

    // Matching episodes (same season + episode number)
    $ep1_s1 = Episode::factory()->create([
        'title' => 'Pilot',
        'series_id' => $series1->id,
        'season_id' => $season1->id,
        'season' => 1,
        'episode_num' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'enabled' => true,
    ]);

    $ep1_s2 = Episode::factory()->create([
        'title' => 'Pilot',
        'series_id' => $series2->id,
        'season_id' => $season2->id,
        'season' => 1,
        'episode_num' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'enabled' => true,
    ]);

    $ep2_s1 = Episode::factory()->create([
        'title' => "Cat's in the Bag...",
        'series_id' => $series1->id,
        'season_id' => $season1->id,
        'season' => 1,
        'episode_num' => 2,
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'enabled' => true,
    ]);

    $ep2_s2 = Episode::factory()->create([
        'title' => "Cat's in the Bag",
        'series_id' => $series2->id,
        'season_id' => $season2->id,
        'season' => 1,
        'episode_num' => 2,
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'enabled' => true,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    MergeSeries::dispatchSync(
        $user,
        $playlists,
        $playlist1->id,
        titleSimilarityThreshold: 80.0,
    );

    // Should have 2 episode failover entries (ep1 and ep2)
    $failovers = EpisodeFailover::where('user_id', $user->id)->get();
    expect($failovers)->toHaveCount(2);

    // Master episodes are from the preferred playlist
    expect(EpisodeFailover::where('episode_id', $ep1_s1->id)
        ->where('episode_failover_id', $ep1_s2->id)
        ->exists()
    )->toBeTrue();

    expect(EpisodeFailover::where('episode_id', $ep2_s1->id)
        ->where('episode_failover_id', $ep2_s2->id)
        ->exists()
    )->toBeTrue();
});

test('it does not merge unrelated series', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $series1 = Series::factory()->create([
        'name' => 'Breaking Bad',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
    ]);

    $series2 = Series::factory()->create([
        'name' => 'The Mandalorian',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
    ]);

    $season1 = Season::factory()->create([
        'series_id' => $series1->id,
        'season_number' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
    ]);

    $season2 = Season::factory()->create([
        'series_id' => $series2->id,
        'season_number' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
    ]);

    Episode::factory()->create([
        'series_id' => $series1->id,
        'season_id' => $season1->id,
        'season' => 1,
        'episode_num' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
    ]);

    Episode::factory()->create([
        'series_id' => $series2->id,
        'season_id' => $season2->id,
        'season' => 1,
        'episode_num' => 1,
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    MergeSeries::dispatchSync($user, $playlists, $playlist1->id, titleSimilarityThreshold: 80.0);

    expect(EpisodeFailover::where('user_id', $user->id)->count())->toBe(0);
});
