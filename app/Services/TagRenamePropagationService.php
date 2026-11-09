<?php

namespace App\Services;

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\PlaylistAlias;
use Spatie\Tags\Tag;

/**
 * Custom-playlist group/category tags: propagate renames into bouquets and alias
 * group filters, which store tag names and would otherwise silently stop
 * matching - the same treatment the provider-rename pass in ProcessM3uImport
 * gives standard playlists (issue #1391). Invoked from the Tag::updated model
 * event listener in AppServiceProvider.
 */
class TagRenamePropagationService
{
    public static function handle(Tag $tag): void
    {
        if (! $tag->wasChanged('name') || ! $tag->type) {
            return;
        }

        $oldName = json_decode($tag->getRawOriginal('name') ?? '', true)['en'] ?? null;
        $newName = $tag->getTranslation('name', 'en');
        if ($oldName === null || $oldName === '' || $newName === null || $newName === '' || $oldName === $newName) {
            return;
        }

        $isCategory = str_ends_with($tag->type, '-category');
        $uuid = $isCategory ? substr($tag->type, 0, -strlen('-category')) : $tag->type;
        $customPlaylist = CustomPlaylist::where('uuid', $uuid)->first();
        if (! $customPlaylist) {
            return;
        }

        // Group tags are shared across live and VOD; category tags map to series.
        $keys = $isCategory ? ['selected_categories'] : ['selected_groups', 'selected_vod_groups'];

        $replaceName = function (array $names) use ($oldName, $newName): array {
            return array_values(array_unique(
                array_map(fn (string $name): string => $name === $oldName ? $newName : $name, $names)
            ));
        };

        $rewrite = function (array $lists) use ($keys, $oldName, $replaceName): array {
            foreach ($keys as $key) {
                $current = $lists[$key] ?? [];
                if (in_array($oldName, $current, true)) {
                    $lists[$key] = $replaceName($current);
                }
            }

            return $lists;
        };

        Bouquet::where('custom_playlist_id', $customPlaylist->id)
            ->cursor()
            ->each(function (Bouquet $bouquet) use ($rewrite): void {
                $updated = $rewrite($bouquet->group_selections ?? []);
                if ($updated !== ($bouquet->group_selections ?? [])) {
                    $bouquet->update(['group_selections' => $updated]);
                }
            });

        // Custom-playlist aliases also carry a manual live-group sort order
        // (fed by the same custom-variant picker as selected_groups), which
        // goes stale the same way - but only group tags have a live-group
        // sort order to rewrite; category tags don't touch it.
        PlaylistAlias::where('custom_playlist_id', $customPlaylist->id)
            ->cursor()
            ->each(function (PlaylistAlias $alias) use ($rewrite, $replaceName, $isCategory, $oldName): void {
                $filter = $rewrite($alias->group_filter ?? []);

                if (! $isCategory) {
                    $order = $filter['live_group_order'] ?? [];
                    if (in_array($oldName, $order, true)) {
                        $filter['live_group_order'] = $replaceName($order);
                    }
                }

                if ($filter !== ($alias->group_filter ?? [])) {
                    $alias->updateQuietly(['group_filter' => $filter]);
                    // updateQuietly() skips the ::updating hook that clears the EPG
                    // cache on target-FK changes, but a rewritten manual filter is
                    // just as stale as one the user edited by hand - clear it here.
                    EpgCacheService::clearPlaylistEpgCacheFile($alias);
                }
            });
    }
}
