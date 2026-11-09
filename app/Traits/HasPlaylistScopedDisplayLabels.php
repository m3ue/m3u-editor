<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared pieces of the SourceGroup / SourceCategory display-label resolvers behind
 * the playlist and playlist alias pickers: id normalisation, the optional join that
 * appends the owning playlist's name, and the id => label map built from the selected
 * rows.
 */
trait HasPlaylistScopedDisplayLabels
{
    /**
     * The label for a provider group or category: the provider's name, with the user's
     * rename in front of it when there is one, so both stay visible in the pickers.
     */
    public static function labelWithProviderName(?string $customName, string $providerName): string
    {
        return filled($customName) && $customName !== $providerName
            ? __(':name (provider: :provider)', ['name' => $customName, 'provider' => $providerName])
            : $providerName;
    }

    /**
     * Integer ids from a scalar or list, dropping anything non-numeric.
     *
     * @param  int|array<int|string>|null  $values
     * @return array<int>
     */
    protected static function numericIds(int|array|null $values): array
    {
        return array_values(array_map(
            fn ($value): int => (int) $value,
            array_filter((array) $values, fn ($value): bool => is_numeric($value)),
        ));
    }

    /**
     * Join the owning playlist and select its name so labelsById() can append it.
     */
    protected static function selectPlaylistName(Builder $query, string $table): Builder
    {
        return $query
            ->leftJoin('playlists', 'playlists.id', '=', "{$table}.playlist_id")
            ->selectRaw('playlists.name as playlist_name');
    }

    /**
     * Rows selected as (id, provider_name[, custom_name][, playlist_name]) to an
     * id => label map, with the playlist name appended whenever the row carries one.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, string>
     */
    protected static function labelsById(Collection $rows): array
    {
        return $rows->mapWithKeys(function ($row): array {
            $label = self::labelWithProviderName($row->custom_name, $row->provider_name);

            return [$row->id => filled($row->playlist_name) ? "{$label} ({$row->playlist_name})" : $label];
        })->all();
    }
}
