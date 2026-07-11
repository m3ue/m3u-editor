<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Channel;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool for semantic VOD content discovery.
 *
 * Queries VOD channels by genre, year range, numeric rating, and keyword.
 * Matches against both the IPTV group field and the TMDB genre stored in
 * info->genre, so "Animation" catches content categorised either way.
 * Designed to power curated playlist-building workflows with the AI.
 */
class VodContentSearchTool extends BaseTool
{
    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 50;

    public function description(): Stringable|string
    {
        return 'Search for VOD movies and content by genre, year range, minimum rating, and keyword. Use this when the user wants to find content for a network playlist — for example "family-friendly movies from the last 5 years" or "animated films rated above 7". Pass genres as an array (e.g. ["Animation", "Family", "Adventure"]) — the search matches against both the IPTV group field and the TMDB genre, so broad arrays give better recall. Use exclude_genres to filter out mature content (e.g. ["Horror", "Thriller", "War"]). Use year_min for temporal filters. Returns a list of matching titles with IDs — pass those IDs to NetworkContentBulkAddTool after the user confirms. If zero results are returned, the response includes available_genres from the library; use those to adjust and retry.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'genres' => $schema->array()
                ->items($schema->string())
                ->description('Genres to include. Matched against IPTV group and TMDB genre (case-insensitive, partial match). Examples: ["Animation", "Family", "Adventure", "Comedy"].'),
            'exclude_genres' => $schema->array()
                ->items($schema->string())
                ->description('Genres to exclude. Use to filter out mature content. Examples: ["Horror", "Thriller", "War", "Crime"].'),
            'year_min' => $schema->integer()
                ->description('Minimum release year (inclusive). For "recent" use current_year - 2; for "last 5 years" use current_year - 5.'),
            'year_max' => $schema->integer()
                ->description('Maximum release year (inclusive). Usually omit to include up to the present.'),
            'min_rating' => $schema->number()
                ->description('Minimum TMDB numeric rating (0–10). Use 6 for decent quality, 7 for well-rated, 8 for top picks. Omit to include all.'),
            'keyword' => $schema->string()
                ->description('Keyword to search in the movie title, plot, cast, or director. Optional — combine with genre filters for precision.'),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return (default: 30, max: 50).'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $genres = array_values(array_filter((array) ($request['genres'] ?? [])));
        $excludeGenres = array_values(array_filter((array) ($request['exclude_genres'] ?? [])));
        $yearMin = isset($request['year_min']) ? (int) $request['year_min'] : null;
        $yearMax = isset($request['year_max']) ? (int) $request['year_max'] : null;
        $minRating = isset($request['min_rating']) ? (float) $request['min_rating'] : null;
        $keyword = trim((string) ($request['keyword'] ?? ''));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($request['limit'] ?? self::DEFAULT_LIMIT)));

        $query = Channel::where('is_vod', true)
            ->where('user_id', auth()->id());

        $like = $this->likeOperator();
        $genreExpr = $this->jsonExtract('genre');
        $plotExpr = $this->jsonExtract('plot');
        $castExpr = $this->jsonExtract('cast');
        $directorExpr = $this->jsonExtract('director');

        // Genre inclusion: match against IPTV group OR TMDB info.genre.
        if (! empty($genres)) {
            $query->where(function ($q) use ($genres, $like, $genreExpr): void {
                foreach ($genres as $genre) {
                    $q->orWhere('group', $like, "%{$genre}%")
                        ->orWhereRaw("COALESCE({$genreExpr}, '') {$like} ?", ["%{$genre}%"]);
                }
            });
        }

        // Genre exclusion: exclude if either the group OR info.genre matches.
        foreach ($excludeGenres as $genre) {
            $query->where('group', "NOT {$like}", "%{$genre}%")
                ->whereRaw("COALESCE({$genreExpr}, '') NOT {$like} ?", ["%{$genre}%"]);
        }

        if ($yearMin !== null) {
            $query->where('year', '>=', $yearMin);
        }

        if ($yearMax !== null) {
            $query->where('year', '<=', $yearMax);
        }

        if ($minRating !== null) {
            $query->where('rating', '>=', $minRating);
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword, $like, $plotExpr, $castExpr, $directorExpr): void {
                $q->where('name', $like, "%{$keyword}%")
                    ->orWhereRaw("COALESCE({$plotExpr}, '') {$like} ?", ["%{$keyword}%"])
                    ->orWhereRaw("COALESCE({$castExpr}, '') {$like} ?", ["%{$keyword}%"])
                    ->orWhereRaw("COALESCE({$directorExpr}, '') {$like} ?", ["%{$keyword}%"]);
            });
        }

        $results = $query->orderByDesc('rating')
            ->orderByDesc('year')
            ->limit($limit)
            ->get(['id', 'name', 'year', 'rating', 'group', 'info']);

        if ($results->isEmpty()) {
            $availableGenres = Channel::where('is_vod', true)
                ->where('user_id', auth()->id())
                ->whereNotNull('group')
                ->distinct()
                ->orderBy('group')
                ->pluck('group')
                ->filter()
                ->values()
                ->implode(', ');

            return "No VOD content found matching the given criteria.\n\nAvailable genres in this library: {$availableGenres}\n\nTip: Adjust your genre names to match those above and retry.";
        }

        $lines = [
            "VOD Search — {$results->count()} result(s)",
            '',
        ];

        foreach ($results as $channel) {
            $genre = $channel->info['genre'] ?? $channel->group ?? 'Unknown';
            $plot = $channel->info['plot'] ?? $channel->info['description'] ?? '';
            $plotExcerpt = mb_strlen($plot) > 100 ? mb_substr($plot, 0, 97).'...' : $plot;
            $rating = $channel->rating ? number_format((float) $channel->rating, 1) : 'N/A';
            $year = $channel->year ?: 'Unknown';

            $lines[] = "ID: {$channel->id} | {$channel->name} ({$year}) | {$genre} | Rating: {$rating}";

            if ($plotExcerpt !== '') {
                $lines[] = "  └─ {$plotExcerpt}";
            }
        }

        $lines[] = '';
        $lines[] = 'Present this list to the user. Get their confirmation on which titles to add, then ask which network to add them to, and call NetworkContentBulkAddTool with the approved channel IDs.';

        return implode("\n", $lines);
    }

    /** Case-insensitive LIKE operator for the active connection. */
    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    /** JSON field extraction expression for the active connection. */
    private function jsonExtract(string $key): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "info->>'{$key}'"
            : "json_extract(info, '$.{$key}')";
    }
}
