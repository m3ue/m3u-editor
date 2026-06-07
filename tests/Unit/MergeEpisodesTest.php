<?php

namespace Tests\Unit;

use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Jobs\MergeEpisodes;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeEpisodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            PlaylistCreated::class,
            PlaylistUpdated::class,
            PlaylistDeleted::class,
        ]);
        Notification::fake();
    }

    private function runMergeEpisodes(...$arguments): void
    {
        Episode::withoutEvents(fn () => (new MergeEpisodes(...$arguments))->handle());
    }

    #[Test]
    public function it_merges_episodes_by_episode_tmdb_id_when_present(): void
    {
        $user = User::factory()->create();
        $playlist1 = Playlist::factory()->for($user)->createQuietly();
        $playlist2 = Playlist::factory()->for($user)->createQuietly();

        $seriesA = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist1->id,
            'tmdb_id' => 111,
        ]);
        $seriesB = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist2->id,
            'tmdb_id' => 222,
        ]);

        $master = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist1->id,
            'series_id' => $seriesA->id,
            'tmdb_id' => 9001,
            'season' => 1,
            'episode_num' => 1,
        ]);
        $failover = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist2->id,
            'series_id' => $seriesB->id,
            'tmdb_id' => 9001,
            'season' => 9,
            'episode_num' => 9,
        ]);

        $this->runMergeEpisodes(
            user: $user,
            playlists: collect([
                ['playlist_failover_id' => $playlist1->id],
                ['playlist_failover_id' => $playlist2->id],
            ]),
            playlistId: $playlist1->id,
        );

        $this->assertDatabaseHas('episode_failovers', [
            'episode_id' => $master->id,
            'episode_failover_id' => $failover->id,
        ]);
    }

    #[Test]
    public function it_falls_back_to_series_tmdb_id_season_and_episode_number(): void
    {
        $user = User::factory()->create();
        $playlist1 = Playlist::factory()->for($user)->createQuietly();
        $playlist2 = Playlist::factory()->for($user)->createQuietly();

        $seriesA = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist1->id,
            'tmdb_id' => 333,
        ]);
        $seriesB = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist2->id,
            'tmdb_id' => 333,
        ]);

        $master = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist1->id,
            'series_id' => $seriesA->id,
            'tmdb_id' => null,
            'season' => 1,
            'episode_num' => 1,
        ]);
        $failover = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist2->id,
            'series_id' => $seriesB->id,
            'tmdb_id' => null,
            'season' => 1,
            'episode_num' => 1,
        ]);
        $differentEpisode = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist2->id,
            'series_id' => $seriesB->id,
            'tmdb_id' => null,
            'season' => 1,
            'episode_num' => 2,
        ]);

        $this->runMergeEpisodes(
            user: $user,
            playlists: collect([
                ['playlist_failover_id' => $playlist1->id],
                ['playlist_failover_id' => $playlist2->id],
            ]),
            playlistId: $playlist1->id,
        );

        $this->assertDatabaseHas('episode_failovers', [
            'episode_id' => $master->id,
            'episode_failover_id' => $failover->id,
        ]);
        $this->assertDatabaseMissing('episode_failovers', [
            'episode_id' => $master->id,
            'episode_failover_id' => $differentEpisode->id,
        ]);
    }

    #[Test]
    public function it_does_not_merge_by_series_tmdb_id_alone(): void
    {
        $user = User::factory()->create();
        $playlist1 = Playlist::factory()->for($user)->createQuietly();
        $playlist2 = Playlist::factory()->for($user)->createQuietly();

        $seriesA = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist1->id,
            'tmdb_id' => 444,
        ]);
        $seriesB = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist2->id,
            'tmdb_id' => 444,
        ]);

        Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist1->id,
            'series_id' => $seriesA->id,
            'tmdb_id' => null,
            'season' => 1,
            'episode_num' => 1,
        ]);
        Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist2->id,
            'series_id' => $seriesB->id,
            'tmdb_id' => null,
            'season' => 1,
            'episode_num' => 2,
        ]);

        $this->runMergeEpisodes(
            user: $user,
            playlists: collect([
                ['playlist_failover_id' => $playlist1->id],
                ['playlist_failover_id' => $playlist2->id],
            ]),
            playlistId: $playlist1->id,
        );

        $this->assertDatabaseCount('episode_failovers', 0);
    }

    #[Test]
    public function episode_model_exposes_ordered_failover_episodes(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->createQuietly();
        $series = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist->id,
            'tmdb_id' => 555,
        ]);

        $master = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'series_id' => $series->id,
        ]);
        $late = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'series_id' => $series->id,
        ]);
        $early = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'series_id' => $series->id,
        ]);

        EpisodeFailover::create([
            'user_id' => $user->id,
            'episode_id' => $master->id,
            'episode_failover_id' => $late->id,
            'sort' => 2,
        ]);
        EpisodeFailover::create([
            'user_id' => $user->id,
            'episode_id' => $master->id,
            'episode_failover_id' => $early->id,
            'sort' => 1,
        ]);

        $this->assertSame([$early->id, $late->id], $master->failoverEpisodes()->pluck('episodes.id')->all());
    }
}
