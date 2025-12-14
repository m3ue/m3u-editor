<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\MediaServerIntegration;
use App\Models\PlaylistAuth;
use App\Models\Channel;
use App\Models\Series;
use App\Models\Episode;
use Illuminate\Support\Facades\Http;

echo "\n=== Media Server Xtream API Verification ===\n\n";

// Find the media server integration
$integration = MediaServerIntegration::with('playlist')->first();

if (!$integration) {
    echo "❌ No media server integration found\n";
    exit(1);
}

echo "✓ Found integration: {$integration->name} ({$integration->type})\n";
echo "  Playlist: {$integration->playlist->name} (ID: {$integration->playlist_id})\n\n";

// Get auth credentials - find PlaylistAuth that points to this playlist
$auth = PlaylistAuth::where('enabled', true)
    ->get()
    ->first(function ($pa) use ($integration) {
        $model = $pa->getAssignedModel();
        return $model && $model->id === $integration->playlist_id && get_class($model) === 'App\\Models\\Playlist';
    });

if (!$auth) {
    echo "⚠ No auth credentials found for this playlist\n";
    echo "  You can create one in the admin UI or use owner_auth method:\n";
    echo "  username={$integration->playlist->user->name}, password={$integration->playlist->uuid}\n\n";

    // Use owner auth credentials for verification
    $username = $integration->playlist->user->name;
    $password = $integration->playlist->uuid;
} else {
    echo "✓ Auth credentials: username={$auth->username}\n\n";
    $username = $auth->username;
    $password = $auth->password;
}

$baseUrl = 'http://localhost'; // Adjust if needed

// Test 1: Get VOD Categories
echo "--- Test 1: VOD Categories ---\n";
$vodCategories = Channel::where('playlist_id', $integration->playlist_id)
    ->where('is_vod', true)
    ->where('enabled', true)
    ->distinct()
    ->pluck('group_id')
    ->count();

echo "  Found {$vodCategories} VOD categories (groups)\n";

// Test 2: Get VOD Streams
echo "\n--- Test 2: VOD Streams (Movies) ---\n";
$movies = Channel::where('playlist_id', $integration->playlist_id)
    ->where('is_vod', true)
    ->where('enabled', true)
    ->limit(3)
    ->get();

echo "  Found {$movies->count()} sample movies:\n";
foreach ($movies as $movie) {
    echo "    - {$movie->name} (Year: {$movie->year}, Rating: {$movie->rating})\n";

    // Check metadata
    $hasPlot = !empty($movie->info['plot'] ?? null);
    $hasDirector = !empty($movie->info['director'] ?? null);
    $hasActors = !empty($movie->info['actors'] ?? null);
    $hasDuration = !empty($movie->info['duration'] ?? null);

    echo "      Metadata: ";
    echo ($hasPlot ? "✓ Plot " : "✗ Plot ");
    echo ($hasDirector ? "✓ Director " : "✗ Director ");
    echo ($hasActors ? "✓ Actors " : "✗ Actors ");
    echo ($hasDuration ? "✓ Duration" : "✗ Duration");
    echo "\n";
}

// Test 3: Get Series Categories
echo "\n--- Test 3: Series Categories ---\n";
$seriesCategories = Series::where('playlist_id', $integration->playlist_id)
    ->where('enabled', true)
    ->distinct()
    ->pluck('category_id')
    ->count();

echo "  Found {$seriesCategories} series categories\n";

// Test 4: Get Series
echo "\n--- Test 4: Series ---\n";
$series = Series::where('playlist_id', $integration->playlist_id)
    ->where('enabled', true)
    ->with('category')
    ->limit(3)
    ->get();

echo "  Found {$series->count()} sample series:\n";
foreach ($series as $s) {
    echo "    - {$s->name} (Category: {$s->category->name})\n";

    $hasPlot = !empty($s->plot);
    $hasGenre = !empty($s->genre);
    $hasRating = !empty($s->rating);
    $hasCover = !empty($s->cover);

    echo "      Metadata: ";
    echo ($hasPlot ? "✓ Plot " : "✗ Plot ");
    echo ($hasGenre ? "✓ Genre " : "✗ Genre ");
    echo ($hasRating ? "✓ Rating " : "✗ Rating ");
    echo ($hasCover ? "✓ Cover" : "✗ Cover");
    echo "\n";
}

// Test 5: Get Series Info (with episodes)
echo "\n--- Test 5: Series Episodes ---\n";
if ($series->isNotEmpty()) {
    $testSeries = $series->first();
    $seasons = $testSeries->seasons()->count();
    $episodes = Episode::where('series_id', $testSeries->id)
        ->where('enabled', true)
        ->limit(3)
        ->get();

    echo "  Series: {$testSeries->name}\n";
    echo "    Seasons: {$seasons}\n";
    echo "    Sample Episodes:\n";

    foreach ($episodes as $episode) {
        echo "      - S{$episode->season}E{$episode->episode_num}: {$episode->title}\n";

        $hasPlot = !empty($episode->plot);
        $hasCover = !empty($episode->cover);
        $hasDuration = !empty($episode->info['duration'] ?? null);
        $hasUrl = !empty($episode->url);

        echo "        Metadata: ";
        echo ($hasPlot ? "✓ Plot " : "✗ Plot ");
        echo ($hasCover ? "✓ Cover " : "✗ Cover ");
        echo ($hasDuration ? "✓ Duration " : "✗ Duration ");
        echo ($hasUrl ? "✓ URL" : "✗ URL");
        echo "\n";
    }
}

// Test 6: Check Xtream API URLs
echo "\n--- Test 6: Xtream API URL Format ---\n";
echo "  Base URL: {$baseUrl}/player_api.php\n";
echo "  Auth: username={$username}&password={$password}\n";
echo "\n  Test URLs:\n";
echo "    VOD Categories:  {$baseUrl}/player_api.php?username={$username}&password={$password}&action=get_vod_categories\n";
echo "    Series Categories: {$baseUrl}/player_api.php?username={$username}&password={$password}&action=get_series_categories\n";

if ($movies->isNotEmpty()) {
    $sampleMovie = $movies->first();
    echo "    VOD Info:        {$baseUrl}/player_api.php?username={$username}&password={$password}&action=get_vod_info&vod_id={$sampleMovie->id}\n";
    echo "    VOD Stream:      {$baseUrl}/movie/{$username}/{$password}/{$sampleMovie->id}.{$sampleMovie->container_extension}\n";
}

if ($series->isNotEmpty()) {
    $sampleSeries = $series->first();
    echo "    Series Info:     {$baseUrl}/player_api.php?username={$username}&password={$password}&action=get_series_info&series_id={$sampleSeries->id}\n";
}

if (isset($episode)) {
    echo "    Episode Stream:  {$baseUrl}/series/{$username}/{$password}/{$episode->id}.{$episode->container_extension}\n";
}

// Summary
echo "\n=== Summary ===\n";
$totalMovies = Channel::where('playlist_id', $integration->playlist_id)
    ->where('is_vod', true)
    ->where('enabled', true)
    ->count();

$totalSeries = Series::where('playlist_id', $integration->playlist_id)
    ->where('enabled', true)
    ->count();

$totalEpisodes = Episode::where('playlist_id', $integration->playlist_id)
    ->where('enabled', true)
    ->count();

echo "  Total Movies:  {$totalMovies}\n";
echo "  Total Series:  {$totalSeries}\n";
echo "  Total Episodes: {$totalEpisodes}\n";

$moviesWithMetadata = Channel::where('playlist_id', $integration->playlist_id)
    ->where('is_vod', true)
    ->where('enabled', true)
    ->whereNotNull('info')
    ->count();

$episodesWithPlot = Episode::where('playlist_id', $integration->playlist_id)
    ->where('enabled', true)
    ->whereNotNull('plot')
    ->count();

echo "\n  Movies with metadata: {$moviesWithMetadata}/{$totalMovies} (" . round(($moviesWithMetadata/$totalMovies)*100, 1) . "%)\n";
echo "  Episodes with plot: {$episodesWithPlot}/{$totalEpisodes} (" . round(($episodesWithPlot/$totalEpisodes)*100, 1) . "%)\n";

echo "\n✓ Verification complete!\n\n";
