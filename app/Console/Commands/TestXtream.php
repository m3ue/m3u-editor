<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\XtreamService;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

class TestXtream extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:xtream-test {playlist?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to Xtream API';

    protected Playlist $playlist;

    public function handle(XtreamService $xtream)
    {
        $playlistId = $this->argument('playlist');
        if ($playlistId) {
            $playlist = Playlist::find($playlistId);
        } else {
            $playlists = Playlist::where('xtream', true)->get();
            $playlist = $this->choice('Select an Xtream enabled playlist to test:', $playlists->pluck('name')->toArray());
            $playlist = $playlists->where('name', $playlist)->first();
        }

        if (! $playlist) {
            $this->error('Playlist not found.');
            return 1;
        }
        if (! $playlist->xtream) {
            $this->error('Playlist is not Xtream enabled.');
            return 1;
        }

        $this->playlist = $playlist;
        $xtream_config = $playlist->xtream_config;

        $this->info('Xtream helper');
        $this->info('Connecting to: ' . $xtream_config['url'] . '...');

        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 5
        );
        if (! $xtream) {
            $this->error('Xtream service initialization failed.');
            return 1;
        }
        $userInfo = $xtream->authenticate();
        if (! ($userInfo['auth'] ?? false)) {
            $this->error('Authentication failed.');
            return 1;
        }

        $choice = $this->choice('Type L(ive), (A)ll (L)ive, S(eries), M(ovies), or I(nfo)', ['L', 'AL', 'S', 'M', 'I'], 0);
        match (Str::upper($choice)) {
            'L' => $this->processLive($xtream),
            'AL' => $this->processLive($xtream, true),
            'S' => $this->processSeries($xtream),
            'M' => $this->processMovies($xtream),
            'I' => $this->getInfo($xtream),
        };

        return 0;
    }

    protected function getInfo(XtreamService $xtream)
    {
        $this->info('Fetching Xtream info...');
        $info = $xtream->userInfo();
        if (empty($info)) {
            $this->error('No information available from Xtream service.');
            return;
        }

        $this->line(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Xtream info fetched successfully.');
    }

    protected function processMovies(XtreamService $xtream)
    {
        $cats = $xtream->getVodCategories();
        $map = collect($cats)->pluck('category_id', 'category_name')->toArray();
        $catName = $this->choice('Pick a Movie Category', array_keys($map));
        $catId = $map[$catName];

        $movies = $xtream->getVodStreams($catId);
        $movieMap = collect($movies)->pluck('stream_id', 'name')->toArray();
        $pick = $this->choice('Pick a movie or All', array_merge(['All'], array_keys($movieMap)));

        if ($pick === 'All') {
            $this->generateMovies($xtream, $movies, $catName);
        } else {
            $movie = $movies[array_search($pick, array_column($movies, 'name'))];
            $this->generateMovies($xtream, [$movie], $catName);
        }
    }

    protected function generateMovies(XtreamService $xtream, array $movies, string $catName)
    {
        $folder = Str::of($catName)->replace(' | ', ' - ')->trim();
        foreach ($movies as $movie) {
            $info = $xtream->getVodInfo($movie['stream_id']);
            $this->info("[Movie] {$movie['name']}");
            $this->info("[Category] {$folder}");
            $this->line(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    protected function processSeries(XtreamService $xtream)
    {
        $cats = $xtream->getSeriesCategories();
        $map = collect($cats)->pluck('category_id', 'category_name')->toArray();
        $catName = $this->choice('Pick a Series Category', array_keys($map));
        $catId = $map[$catName];

        $seriesList = $xtream->getSeries($catId);
        $seriesMap = collect($seriesList)->pluck('series_id', 'name')->toArray();
        $pick = $this->choice('Pick a series or All', array_merge(['All'], array_keys($seriesMap)));

        if ($pick === 'All') {
            $this->generateCategorySeries($xtream, $seriesList, $catName, $catId);
        } else {
            $seriesId = $seriesMap[$pick];
            $this->generateOneSeries($xtream, $seriesId, $catName, $catId);
        }
    }

    protected function processLive(XtreamService $xtream, $all = false)
    {
        if ($all) {
            $channels = $xtream->getLiveStreams();
            foreach ($channels as $channel) {
                $this->generateOneChannel($xtream, $channel, 'All', 0);
            }
            return;
        } else {
            $cats = $xtream->getLiveCategories();
            $map = collect($cats)->pluck('category_id', 'category_name')->toArray();
            $catName = $this->choice('Pick a Live Category', array_keys($map));
            $catId = $map[$catName];

            $channels = $xtream->getLiveStreams($catId);
            $channelMap = collect($channels)->pluck('stream_id', 'name')->toArray();
            $pick = $this->choice('Pick a channel or All', array_merge(['All'], array_keys($channelMap)));

            if ($pick === 'All') {
                $this->generateCategoryChannels($xtream, $channels, $catName, $catId);
            } else {
                $channel = $channels[array_search($pick, array_column($channels, 'name'))];
                $this->generateOneChannel($xtream, $channel, $catName, $catId);
            }
        }
    }

    protected function generateCategorySeries(XtreamService $xtream, array $seriesList, string $catName, int $catId)
    {
        foreach ($seriesList as $serie) {
            $this->generateOneSeries($xtream, $serie['series_id'], $catName, $catId);
        }
    }

    protected function generateOneSeries(XtreamService $xtream, string $seriesId, string $catName, int $catId)
    {
        $langIgnore = config('xtream.lang_strip');
        $detail = $xtream->getSeriesInfo($seriesId);
        $info = $detail['info'] ?? [];
        $seriesName = Str::of($info['name'])->replace($langIgnore, '')->trim();
        $catFolder = Str::of($catName)->replace(' | ', ' - ')->trim();

        $this->info("[Series] {$seriesName}");
        $this->info("[Category] {$catFolder} [{$catId}]");
        $this->line(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function generateCategoryChannels(XtreamService $xtream, array $channels, string $catName, int $catId)
    {
        foreach ($channels as $channel) {
            $this->generateOneChannel($xtream, $channel, $catName, $catId);
        }
    }

    protected function generateOneChannel(XtreamService $xtream, array $channel, string $catName, int $catId)
    {
        $langIgnore = config('xtream.lang_strip');
        $channelName = Str::of($channel['name'])->replace($langIgnore, '')->trim();
        $catFolder = Str::of($catName)->replace(' | ', ' - ')->trim();

        $this->info("[Channel] {$channelName}");
        $this->info("[Category] {$catFolder} [{$catId}]");
        $this->line(json_encode($channel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
