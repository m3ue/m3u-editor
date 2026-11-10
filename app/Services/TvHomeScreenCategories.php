<?php

namespace App\Services;

use App\Http\Controllers\XtreamApiController;
use App\Models\PlaylistAlias;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/**
 * The categories a playlist's app sees, for picking Home screen rows. Asks
 * the Xtream API itself as that playlist, so Custom, Merged and Alias
 * playlists and Dynamic Groups come back with exactly the ids the app gets
 * from `get_*_categories`. Scoped, so a form render asks once per type.
 */
#[Scoped]
class TvHomeScreenCategories
{
    private const ACTIONS = [
        'live' => 'get_live_categories',
        'vod' => 'get_vod_categories',
        'series' => 'get_series_categories',
    ];

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /**
     * @return array<string, string> category_id => category_name
     */
    public function for(?Model $playlist, ?string $type): array
    {
        $action = self::ACTIONS[$type] ?? null;

        if ($playlist === null || $action === null) {
            return [];
        }

        return $this->cache["{$playlist->getMorphClass()}:{$playlist->getKey()}:{$type}"] ??= $this->fetch($playlist, $action);
    }

    /**
     * @return array<string, string>
     */
    private function fetch(Model $playlist, string $action): array
    {
        try {
            $response = app(XtreamApiController::class)->handleAs(
                Request::create('/player_api.php', 'GET', ['action' => $action]),
                $playlist,
                $playlist instanceof PlaylistAlias ? 'alias_auth' : 'owner_auth',
            );
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if (! $response instanceof JsonResponse || ! $response->isOk()) {
            return [];
        }

        $categories = [];

        foreach ((array) json_decode((string) $response->getContent(), true) as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = (string) ($category['category_id'] ?? '');

            // `all` is the placeholder a playlist without categories returns.
            if ($id === '' || $id === 'all') {
                continue;
            }

            $categories[$id] = (string) ($category['category_name'] ?? $id);
        }

        return $categories;
    }
}
