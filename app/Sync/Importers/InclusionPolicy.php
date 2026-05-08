<?php

namespace App\Sync\Importers;

use App\Models\Playlist;

/**
 * Stateless value object encapsulating the "should this group/category be
 * included in the import?" rules previously inlined in ProcessM3uImport.
 *
 * Rules (per content type — channel / vod / series):
 *   1. If the name matches an entry in the "selected" list verbatim, include it.
 *   2. Otherwise, walk the "prefixes" list. When useRegex is false this is a
 *      simple str_starts_with check. When true the pattern is treated as a
 *      regex body and wrapped in `/.../u` (existing `/` chars are escaped).
 */
final class InclusionPolicy
{
    /**
     * @param  array<int, string>  $selectedGroups
     * @param  array<int, string>  $includedGroupPrefixes
     * @param  array<int, string>  $selectedVodGroups
     * @param  array<int, string>  $includedVodGroupPrefixes
     * @param  array<int, string>  $selectedCategories
     * @param  array<int, string>  $includedCategoryPrefixes
     */
    public function __construct(
        public readonly bool $useRegex,
        public readonly array $selectedGroups,
        public readonly array $includedGroupPrefixes,
        public readonly array $selectedVodGroups,
        public readonly array $includedVodGroupPrefixes,
        public readonly array $selectedCategories,
        public readonly array $includedCategoryPrefixes,
    ) {}

    /**
     * Build a policy from a Playlist's import_prefs.
     */
    public static function fromPlaylist(Playlist $playlist): self
    {
        $prefs = $playlist->import_prefs ?? [];

        return new self(
            useRegex: $prefs['use_regex'] ?? false,
            selectedGroups: $prefs['selected_groups'] ?? [],
            includedGroupPrefixes: $prefs['included_group_prefixes'] ?? [],
            selectedVodGroups: $prefs['selected_vod_groups'] ?? [],
            includedVodGroupPrefixes: $prefs['included_vod_group_prefixes'] ?? [],
            selectedCategories: $prefs['selected_categories'] ?? [],
            includedCategoryPrefixes: $prefs['included_category_prefixes'] ?? [],
        );
    }

    public function shouldIncludeChannel(string $groupName): bool
    {
        return $this->matches($groupName, $this->selectedGroups, $this->includedGroupPrefixes);
    }

    public function shouldIncludeVod(string $groupName): bool
    {
        return $this->matches($groupName, $this->selectedVodGroups, $this->includedVodGroupPrefixes);
    }

    public function shouldIncludeSeries(string $categoryName): bool
    {
        return $this->matches($categoryName, $this->selectedCategories, $this->includedCategoryPrefixes);
    }

    /**
     * @param  array<int, string>  $selected
     * @param  array<int, string>  $prefixes
     */
    private function matches(string $name, array $selected, array $prefixes): bool
    {
        if (in_array($name, $selected, true)) {
            return true;
        }

        foreach ($prefixes as $pattern) {
            if ($this->useRegex) {
                $delimiter = '/';
                $escapedPattern = str_replace($delimiter, '\\'.$delimiter, $pattern);
                $finalPattern = $delimiter.$escapedPattern.$delimiter.'u';
                try {
                    if (preg_match($finalPattern, $name) === 1) {
                        return true;
                    }
                } catch (\ValueError $e) {
                    // Invalid regex pattern — skip silently rather than crashing the import.
                }
            } elseif (str_starts_with($name, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
