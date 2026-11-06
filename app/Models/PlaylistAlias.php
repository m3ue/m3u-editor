<?php

namespace App\Models;

use App\Enums\BouquetKind;
use App\Enums\PlaylistChannelId;
use App\Jobs\UpdateXtreamStats;
use App\Pivots\BouquetPlaylistAlias;
use App\Pivots\MergedPlaylistPivot;
use App\Traits\HasDefaultAuth;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Tags\Tag;

class PlaylistAlias extends Model
{
    use HasDefaultAuth;
    use HasFactory;
    use ShortUrlTrait;

    /** @var array<int>|null Memoised source_category_id list for the series() filter. */
    public ?array $resolvedCategoryIds = null;

    /** @var array<string, array<string, int>> Memoised custom playlist tag name => id maps, keyed by tag type. */
    private array $resolvedCustomTagIds = [];

    /** @var array<int>|null Memoised source_category_id list for the series() block list. */
    private ?array $resolvedBlockedCategoryIds = null;

    /** @var array{allow: array<string, array<int, mixed>>, block: array<string, array<int, mixed>>}|null Memoised raw selections of the attached bouquets, per kind and key. */
    private ?array $bouquetEntries = null;

    /** @var array<string, array<int, array<int>>> Memoised playlist id => source_category_id lists for the merged series() filter, keyed by selection. */
    private array $resolvedSourceCategoryIds = [];

    /**
     * The group_filter / bouquet group_selections key holding the selection for each
     * content type: provider names, or {playlist_id, name} pairs on merged aliases.
     *
     * @var array<string, string>
     */
    public const CONTENT_FILTER_KEYS = [
        'live' => 'selected_groups',
        'vod' => 'selected_vod_groups',
        'series' => 'selected_categories',
    ];

    protected $casts = [
        'xtream_config' => 'array',
        'inherit_dns_failover' => 'boolean',
        'group_filter' => 'array',
        'proxy_options' => 'array',
        'enable_proxy' => 'boolean',
        'priority' => 'integer',
        'expires_at' => 'datetime',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'hdhr_enabled' => 'boolean',
        'm3u_enabled' => 'boolean',
        'xapi_enabled' => 'boolean',
        'xmltv_enabled' => 'boolean',
    ];

    /**
     * Get the xtream_config attribute as a normalized array of configs.
     */
    protected function xtreamConfig(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if ($value === null || $value === '') {
                    return [];
                }

                $raw = json_decode($value, true);

                // Legacy format: single config object stored as array with 'url' key.
                if (is_array($raw) && array_key_exists('url', $raw)) {
                    return [$raw];
                }

                // New format: list of configs.
                if (is_array($raw)) {
                    $configs = [];
                    foreach ($raw as $index => $item) {
                        if (is_array($item) && ! empty($item['url'])) {
                            $configs[] = $item;
                        }
                    }

                    return $configs;
                }

                return [];
            },
        );
    }

    /**
     * The attached bouquets, reusing an eager-loaded relation when there is one and
     * querying without caching the relation on the model otherwise (the results are
     * memoised by the callers below either way).
     *
     * @return Collection<int, Bouquet>
     */
    private function attachedBouquets(): Collection
    {
        return $this->relationLoaded('bouquets') ? $this->bouquets : $this->bouquets()->get();
    }

    /**
     * The raw selections of every attached bouquet, per kind (allow / block) and
     * selection key, memoised per instance.
     *
     * NEVER surface this through a group_filter attribute accessor/cast: the
     * Filament alias form binds group_filter.* state paths directly, and an
     * attribute-level union would be hydrated into the form and persisted into
     * the manual filter on save (spec R1).
     *
     * @return array{allow: array<string, array<int, mixed>>, block: array<string, array<int, mixed>>}
     */
    private function bouquetEntries(): array
    {
        if ($this->bouquetEntries !== null) {
            return $this->bouquetEntries;
        }

        $entries = ['allow' => [], 'block' => []];

        // Orphaned aliases (no target) have no bouquet support - zero queries.
        if (! $this->playlist_id && ! $this->custom_playlist_id && ! $this->merged_playlist_id) {
            return $this->bouquetEntries = $entries;
        }

        foreach ($this->attachedBouquets() as $bouquet) {
            $kind = $bouquet->kind === BouquetKind::Block ? 'block' : 'allow';
            foreach ((array) $bouquet->group_selections as $key => $selection) {
                if (is_array($selection) && $selection !== []) {
                    $entries[$kind][$key] = array_merge($entries[$kind][$key] ?? [], array_values($selection));
                }
            }
        }

        return $this->bouquetEntries = $entries;
    }

    /**
     * Merge the selections for one content type from group_filter / group_selections
     * arrays into the shape its matcher expects: names, or {playlist_id, name} pairs
     * on merged aliases.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, mixed>
     */
    private function selectionSet(string $type, array $sources): array
    {
        $key = self::CONTENT_FILTER_KEYS[$type];
        $entries = [];
        foreach ($sources as $source) {
            $entries = array_merge($entries, (array) ($source[$key] ?? []));
        }

        return $this->merged_playlist_id ? self::selectionPairs($entries) : self::selectionNames($entries);
    }

    /**
     * Whether a group_filter / group_selections array holds any selection for a type.
     * Checked on the raw stored value, so a malformed selection still restricts (and
     * fails closed) instead of silently allowing everything.
     *
     * @param  array<string, mixed>  $source
     */
    private function hasStoredSelection(string $type, array $source): bool
    {
        return ! empty($source[self::CONTENT_FILTER_KEYS[$type]]);
    }

    /**
     * The group_filter flag that turns the alias's own picks for a content type into
     * a hidden list ("everything except") instead of its allow-list.
     */
    public static function hideSelectionKey(string $type): string
    {
        return 'hide_'.self::CONTENT_FILTER_KEYS[$type];
    }

    /**
     * Whether the alias's own picks for a type are hidden rather than allowed.
     */
    public function hidesSelection(string $type): bool
    {
        return (bool) ($this->group_filter[self::hideSelectionKey($type)] ?? false);
    }

    /**
     * The allow-list for a type: the alias's own picks (unless it hides them) plus its
     * allow bouquets'.
     *
     * @return array<int, mixed>
     */
    private function allowSet(string $type): array
    {
        return $this->selectionSet($type, $this->hidesSelection($type)
            ? [$this->bouquetEntries()['allow']]
            : [$this->group_filter ?? [], $this->bouquetEntries()['allow']]);
    }

    /**
     * What is hidden for a type whatever else allows it: the block bouquets' selections,
     * plus the alias's own picks when it hides them.
     *
     * @return array<int, mixed>
     */
    private function blockSet(string $type): array
    {
        return $this->selectionSet($type, $this->hidesSelection($type)
            ? [$this->bouquetEntries()['block'], $this->group_filter ?? []]
            : [$this->bouquetEntries()['block']]);
    }

    /**
     * Whether a type is limited to an allow-list: an allow bouquet, or the alias itself
     * when it doesn't hide its picks, picks something for it. Nothing picked means
     * everything is allowed.
     */
    private function hasAllowList(string $type): bool
    {
        return (! $this->hidesSelection($type) && $this->hasStoredSelection($type, $this->group_filter ?? []))
            || $this->hasStoredSelection($type, $this->bouquetEntries()['allow']);
    }

    /**
     * Whether this alias delivers less than everything for a content type.
     */
    public function isContentFiltered(string $type): bool
    {
        return $this->hasAllowList($type) || $this->blockSet($type) !== [];
    }

    /**
     * Restrict a channels ('live' / 'vod') or series query to what this alias delivers
     * for that content type: the allow-list, minus everything a block bouquet hides.
     *
     * The one place alias filtering is decided. The channels() and series() relations,
     * the Xtream category lists and the guest panel all apply it, so they can't drift.
     */
    public function applyContentFilter($query, string $type): void
    {
        if ($this->hasAllowList($type)) {
            $allow = $this->allowSet($type);
            $query->where(fn ($query) => $this->matchContent($query, $type, $allow, isAllowList: true));
        }

        $block = $this->blockSet($type);
        if ($block !== []) {
            $query->whereNot(fn ($query) => $this->matchContent($query, $type, $block, isAllowList: false));
        }
    }

    /**
     * Match channels or series against a selection set with the rules of the alias's
     * target type. The matchers keep every comparison NULL-guarded, so the condition is
     * strictly true or false and a block list can negate it without dropping rows whose
     * group or category is unset.
     *
     * @param  array<int, mixed>  $set
     */
    private function matchContent($query, string $type, array $set, bool $isAllowList): void
    {
        $isSeries = $type === 'series';

        if ($this->merged_playlist_id) {
            $isSeries
                ? $this->constrainSeriesToSourceCategories($query, $set)
                : $this->constrainChannelsToSourceGroups($query, $set, includeUngroupedCustom: $isAllowList);

            return;
        }

        if ($this->custom_playlist_id) {
            $isSeries
                ? $this->constrainSeriesToCustomCategories($query, $set)
                : $this->constrainChannelsToCustomGroups($query, $set);

            return;
        }

        $isSeries
            ? $this->constrainSeriesToPlaylistCategories($query, $set, $isAllowList)
            : $this->constrainChannelsToPlaylistGroups($query, $set, includeUngroupedCustom: $isAllowList);
    }

    /**
     * Get the allowed live group names for this alias: its own picks unioned with
     * every attached allow bouquet's. Empty means no allow-list, not that nothing is
     * hidden: block bouquets are checked through isContentFiltered().
     *
     * @return array<string>
     */
    public function getAllowedLiveGroupNames(): array
    {
        return $this->allowedNamesFor('live');
    }

    /**
     * Get the allowed VOD group names for this alias (see getAllowedLiveGroupNames()).
     *
     * @return array<string>
     */
    public function getAllowedVodGroupNames(): array
    {
        return $this->allowedNamesFor('vod');
    }

    /**
     * Get the allowed series category names for this alias (see getAllowedLiveGroupNames()).
     *
     * @return array<string>
     */
    public function getAllowedCategoryNames(): array
    {
        return $this->allowedNamesFor('series');
    }

    /**
     * Source-scoped live group allow-list for merged-playlist aliases: one
     * {playlist_id, name} per allowed provider group.
     *
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public function getAllowedLiveGroupSelections(): array
    {
        return $this->allowedPairsFor('live');
    }

    /**
     * Source-scoped VOD group allow-list for merged-playlist aliases.
     *
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public function getAllowedVodGroupSelections(): array
    {
        return $this->allowedPairsFor('vod');
    }

    /**
     * Source-scoped series category allow-list for merged-playlist aliases.
     *
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public function getAllowedCategorySelections(): array
    {
        return $this->allowedPairsFor('series');
    }

    /**
     * @return array<string>
     */
    private function allowedNamesFor(string $type): array
    {
        return self::selectionNames($this->allowSet($type));
    }

    /**
     * @return array<int, array{playlist_id: int, name: string}>
     */
    private function allowedPairsFor(string $type): array
    {
        return self::selectionPairs($this->allowSet($type));
    }

    /**
     * Distinct names from a group_filter selection stored either as bare names
     * (standard and custom playlist aliases) or as {playlist_id, name} pairs
     * (merged playlist aliases).
     *
     * @return array<string>
     */
    public static function selectionNames(mixed $selection): array
    {
        if (! is_array($selection)) {
            return [];
        }

        $names = [];
        foreach ($selection as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : $item;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Well-formed {playlist_id, name} pairs from a merged alias selection. Bare
     * names carry no source playlist and are dropped.
     *
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public static function selectionPairs(mixed $selection): array
    {
        if (! is_array($selection)) {
            return [];
        }

        $pairs = [];
        foreach ($selection as $item) {
            if (! is_array($item)) {
                continue;
            }
            $playlistId = $item['playlist_id'] ?? null;
            $name = $item['name'] ?? null;
            if (! is_numeric($playlistId) || ! is_string($name) || $name === '') {
                continue;
            }
            $pair = ['playlist_id' => (int) $playlistId, 'name' => $name];
            $pairs[self::selectionToken($pair)] = $pair;
        }

        return array_values($pairs);
    }

    /**
     * Stable identity of a {playlist_id, name} pair, so selections can be compared
     * and diffed without nested loops.
     *
     * @param  array{playlist_id: int|string, name: string}  $pair
     */
    public static function selectionToken(array $pair): string
    {
        return ((int) $pair['playlist_id']).':'.$pair['name'];
    }

    /**
     * @param  array<int, array{playlist_id: int, name: string}>  $pairs
     * @return array<int, array<string>> playlist id => allowed names
     */
    private static function selectionNamesByPlaylist(array $pairs): array
    {
        $byPlaylist = [];
        foreach ($pairs as $pair) {
            $byPlaylist[$pair['playlist_id']][] = $pair['name'];
        }

        return $byPlaylist;
    }

    /**
     * Whether this alias restricts its live channels, from its own filter or any
     * attached bouquet.
     */
    public function hasLiveGroupFilter(): bool
    {
        return $this->isContentFiltered('live');
    }

    /**
     * Whether this alias restricts its VOD channels.
     */
    public function hasVodGroupFilter(): bool
    {
        return $this->isContentFiltered('vod');
    }

    /**
     * Whether this alias restricts its series.
     */
    public function hasCategoryFilter(): bool
    {
        return $this->isContentFiltered('series');
    }

    /**
     * Whether this alias has any group/category filter applied, from its manual
     * selection or any attached bouquet.
     */
    public function hasGroupFilter(): bool
    {
        return $this->hasLiveGroupFilter() || $this->hasVodGroupFilter() || $this->hasCategoryFilter();
    }

    /**
     * The custom live group order for this alias (internal group names, in order).
     *
     * @return array<string>
     */
    public function getLiveGroupSortOrder(): array
    {
        return $this->group_filter['live_group_order'] ?? [];
    }

    /**
     * Whether this alias should deliver its live groups in a custom order rather
     * than inheriting the source playlist's group ordering.
     */
    public function hasCustomLiveGroupSort(): bool
    {
        return ! empty($this->group_filter['sort_live_groups_custom'])
            && ! empty($this->group_filter['live_group_order']);
    }

    public function getPrimaryXtreamConfig(): ?array
    {
        return $this->xtream_config[0] ?? null;
    }

    public function findXtreamConfigByUrl(?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        // Normalize URL for comparison
        $needle = rtrim(strtolower((string) $url), '/');

        foreach ($this->xtream_config as $cfg) {
            // Normalize config URL
            $cfgUrl = rtrim((string) strtolower($cfg['url'] ?? ''), '/');

            // If URLs match, return this config
            if ($cfgUrl !== '' && $cfgUrl === $needle) {
                return $cfg;
            }
        }

        // No matching config found
        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    public function vodStreamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'vod_stream_profile_id');
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function mergedPlaylist(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class);
    }

    /**
     * Determine whether this alias auth credential is expired.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Get the effective playlist (either the main playlist or custom playlist)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        // Load relationships if not already loaded
        if (! $this->relationLoaded('playlist') && $this->playlist_id) {
            $this->load('playlist');
        }

        if (! $this->relationLoaded('customPlaylist') && $this->custom_playlist_id) {
            $this->load('customPlaylist');
        }

        if (! $this->relationLoaded('mergedPlaylist') && $this->merged_playlist_id) {
            $this->load('mergedPlaylist');
        }

        return $this->playlist ?? $this->customPlaylist ?? $this->mergedPlaylist;
    }

    /**
     * Check if this alias/playlist supports xtream
     */
    public function getXtreamAttribute(): bool
    {
        return ! empty($this->xtream_config);
    }

    /**
     * Get EPG settings
     */
    public function getAutoChannelIncrementAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? $effectivePlaylist->auto_channel_increment : false;
    }

    public function getDummyEpgLengthAttribute(): int
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (int) ($effectivePlaylist->dummy_epg_length ?? 120) : 120;
    }

    public function getIdChannelByAttribute(): PlaylistChannelId
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? $effectivePlaylist->id_channel_by : PlaylistChannelId::ChannelId;
    }

    public function getIncludeVodInM3uAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (bool) $effectivePlaylist->include_vod_in_m3u : false;
    }

    public function getIncludeSeriesInM3uAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (bool) $effectivePlaylist->include_series_in_m3u : false;
    }

    public function getChannelStartAttribute(): int
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (int) ($effectivePlaylist->channel_start ?? 1) : 1;
    }

    public function getForceChannelNumberingAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (bool) $effectivePlaylist->force_channel_numbering : false;
    }

    public function getSortByChannelNumberAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (bool) $effectivePlaylist->sort_by_channel_number : false;
    }

    public function getDummyEpgAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (bool) $effectivePlaylist->dummy_epg : false;
    }

    public function getDummyEpgCategoryAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (bool) $effectivePlaylist->dummy_epg_category : false;
    }

    public function getDummyEpgFallbackOrderAttribute(): array
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? ($effectivePlaylist->dummy_epg_fallback_order ?? []) : [];
    }

    public function getDummyEpgDaysAttribute(): int
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (int) ($effectivePlaylist->dummy_epg_days ?? 5) : 5;
    }

    /**
     * Inherit the DVR setting from the effective playlist (Playlist or CustomPlaylist).
     */
    public function getDvrSettingAttribute()
    {
        return $this->getEffectivePlaylist()?->dvrSetting;
    }

    /**
     * Inherit the content-request setting from the effective playlist (Playlist or CustomPlaylist).
     */
    public function getRequestSettingAttribute()
    {
        return $this->getEffectivePlaylist()?->requestSetting;
    }

    /**
     * Inherit the AIOStreams integration from the effective playlist (Playlist or CustomPlaylist).
     */
    public function getAiostreamsIntegrationAttribute()
    {
        return $this->getEffectivePlaylist()?->aiostreamsIntegration;
    }

    /**
     * Get groups through the effective playlist
     */
    public function groups()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        $relation = $effectivePlaylist->groups();

        if ($this->merged_playlist_id) {
            // A merged alias only exposes the source groups it allows, so a filtered-out
            // group never surfaces as an empty Xtream category. Each content type is
            // scoped independently: an unfiltered type is left alone, while an allow-list
            // with no valid pairs fails closed for that type.
            $filtered = ['live' => $this->hasLiveGroupFilter(), 'vod' => $this->hasVodGroupFilter()];

            if ($filtered['live'] || $filtered['vod']) {
                $relation->where(function ($query) use ($filtered): void {
                    foreach ($filtered as $type => $isFiltered) {
                        $query->orWhere(function ($query) use ($type, $isFiltered): void {
                            $query->where('groups.type', $type);
                            if ($isFiltered) {
                                $this->applyMergedGroupFilter($query, $type);
                            }
                        });
                    }
                });
            }
        }

        return $relation;
    }

    public function groupTags()
    {
        // Merged playlists have no per-playlist custom tags; only custom playlists do.
        if ($this->merged_playlist_id) {
            return collect();
        }

        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->groupTags();
    }

    /**
     * Get categories through the effective playlist
     */
    public function categories()
    {
        // MergedPlaylist exposes groups but not a categories() relationship.
        if ($this->merged_playlist_id) {
            return collect();
        }

        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->categories();
    }

    public function categoryTags()
    {
        if ($this->merged_playlist_id) {
            return collect();
        }

        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->categoryTags();
    }

    public function channels(): BelongsToMany|HasManyThrough
    {
        if ($this->merged_playlist_id) {
            // Merged playlists pull their channels through the merged_playlist_playlist
            // pivot, honouring the per-source include_live / include_vod toggles. This
            // mirrors MergedPlaylist::channels() with the local key pointed at the alias.
            $relation = $this->hasManyThrough(
                Channel::class,
                MergedPlaylistPivot::class,
                'merged_playlist_id', // Foreign key on merged_playlist_playlist
                'playlist_id', // Foreign key on Channel table
                'merged_playlist_id', // Local key on PlaylistAlias table
                'playlist_id' // Local key on merged_playlist_playlist
            )->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->where('channels.is_vod', false)->where('merged_playlist_playlist.include_live', true);
                })->orWhere(function ($q): void {
                    $q->where('channels.is_vod', true)->where('merged_playlist_playlist.include_vod', true);
                });
            })->where(MergedPlaylist::onlyUnmatchedChannels());

            // The alias filter is stored as {playlist_id, name} pairs so a group can be
            // allowed from one source without also allowing a same-named group from
            // another. Matching mirrors the standard branch below, scoped per source.
            $this->applyChannelFilters($relation);

            return $relation;
        }

        if ($this->custom_playlist_id) {
            $relation = $this->belongsToMany(Channel::class, 'channel_custom_playlist', 'custom_playlist_id', 'channel_id', 'custom_playlist_id', 'id')
                ->withPivot(['channel_number', 'channel_minor']);

            // Custom playlists group their channels with per-playlist tags rather than
            // provider groups, so the filter is matched against the tag the channel carries
            // (or its provider group name when untagged, which is what the client is shown).
            $this->applyChannelFilters($relation);

            return $relation;
        }

        $relation = $this->hasManyThrough(
            Channel::class,
            Playlist::class,
            'id', // Foreign key on Playlist table
            'playlist_id', // Foreign key on Channel table
            'playlist_id', // Local key on PlaylistAlias table
            'id'  // Local key on Playlist table
        );

        // Apply group filter at the relationship level so it propagates to every caller
        // (Xtream API, M3U generation, EPG, counts, etc.) without duplication.
        $this->applyChannelFilters($relation);

        return $relation;
    }

    public function series(): BelongsToMany|HasManyThrough
    {
        if ($this->merged_playlist_id) {
            $relation = $this->hasManyThrough(
                Series::class,
                MergedPlaylistPivot::class,
                'merged_playlist_id', // Foreign key on merged_playlist_playlist
                'playlist_id', // Foreign key on Series table
                'merged_playlist_id', // Local key on PlaylistAlias table
                'playlist_id' // Local key on merged_playlist_playlist
            )->where('merged_playlist_playlist.include_series', true)
                ->where(MergedPlaylist::onlyUnmatchedSeries('series'));

            if ($this->hasCategoryFilter()) {
                $relation->where(fn ($query) => $this->applyContentFilter($query, 'series'));
            }

            return $relation;
        }

        if ($this->custom_playlist_id) {
            $relation = $this->belongsToMany(Series::class, 'series_custom_playlist', 'custom_playlist_id', 'series_id', 'custom_playlist_id', 'id');

            if ($this->hasCategoryFilter()) {
                $relation->where(fn ($query) => $this->applyContentFilter($query, 'series'));
            }

            return $relation;
        }

        $relation = $this->hasManyThrough(
            Series::class,
            Playlist::class,
            'id', // Foreign key on Playlist table
            'playlist_id', // Foreign key on Series table
            'playlist_id', // Local key on PlaylistAlias table
            'id'  // Local key on Playlist table
        );

        // Provider categories are matched via source_category_id, the provider-stable
        // integer ID, and the playlist's own categories via category_id (see
        // constrainSeriesToPlaylistCategories()).
        if ($this->hasCategoryFilter()) {
            $relation->where(fn ($query) => $this->applyContentFilter($query, 'series'));
        }

        return $relation;
    }

    /**
     * Apply the live and VOD filters to a channels relation, each scoped to its own
     * content type so restricting one never touches the other.
     */
    private function applyChannelFilters($relation): void
    {
        foreach (['live' => false, 'vod' => true] as $type => $isVod) {
            if (! $this->isContentFiltered($type)) {
                continue;
            }

            $relation->where(function ($query) use ($type, $isVod): void {
                $query->where('channels.is_vod', ! $isVod)
                    ->orWhere(fn ($query) => $this->applyContentFilter($query, $type));
            });
        }
    }

    /**
     * Restrict a merged alias's groups query for one type to the groups its filter
     * delivers, matched on the provider-supplied name_internal within each source.
     */
    private function applyMergedGroupFilter($query, string $type): void
    {
        if ($this->hasAllowList($type)) {
            $allow = $this->allowSet($type);
            $query->where(fn ($query) => $this->constrainGroupsToSourceGroups($query, $allow));
        }

        $block = $this->blockSet($type);
        if ($block !== []) {
            $query->whereNot(fn ($query) => $this->constrainGroupsToSourceGroups($query, $block));
        }
    }

    /**
     * Restrict a channels query to a standard alias selection: channels whose provider
     * group (group_internal) is a selected provider group, or that sit in a selected
     * custom group (matched on the custom group's name_internal).
     *
     * group_internal is the provider-supplied name updated on every sync and is never
     * overridden by the user, unlike the user-facing group name. Custom channels
     * (is_custom = true) never have group_internal set, so they are compared on
     * channels.group (the user-assigned display name) instead. Custom channels with no
     * group pass an allow-list, because they cannot be meaningfully filtered by a group
     * name, but a block list never catches them.
     *
     * @param  array<int, string>  $names
     */
    private function constrainChannelsToPlaylistGroups($query, array $names, bool $includeUngroupedCustom): void
    {
        if ($names === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($query) use ($names, $includeUngroupedCustom): void {
            $query->orWhere(fn ($query) => $query->whereNotNull('channels.group_internal')->whereIn('channels.group_internal', $names))
                ->orWhere(fn ($query) => $query->where('channels.is_custom', true)->whereNotNull('channels.group')->whereIn('channels.group', $names))
                // Custom groups are matched on the group a channel sits in, since the
                // provider group of a channel moved into one never names it.
                ->orWhere(fn ($query) => $query->whereNotNull('channels.group_id')->whereIn('channels.group_id', $this->customGroupIds($names)));
            if ($includeUngroupedCustom) {
                $query->orWhere(fn ($query) => $query->where('channels.is_custom', true)->whereNull('channels.group'));
            }
        });
    }

    /**
     * Restrict a series query to a standard alias selection: series in a selected
     * provider category, matched on source_category_id (the provider-stable id that
     * survives user renames of the Category record).
     *
     * @param  array<int, string>  $names
     */
    private function constrainSeriesToPlaylistCategories($query, array $names, bool $isAllowList): void
    {
        if ($names === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $resolve = fn (): array => SourceCategory::where('playlist_id', $this->playlist_id)
            ->whereIn('name', $names)
            ->pluck('source_category_id')
            ->all();
        $sourceCategoryIds = $isAllowList
            ? ($this->resolvedCategoryIds ??= $resolve())
            : ($this->resolvedBlockedCategoryIds ??= $resolve());

        $query->where(function ($query) use ($sourceCategoryIds, $names): void {
            if ($sourceCategoryIds !== []) {
                $query->orWhere(fn ($query) => $query->whereNotNull('series.source_category_id')->whereIn('series.source_category_id', $sourceCategoryIds));
            }
            // Categories the provider did not supply are matched on the category a series sits in.
            $query->orWhere(fn ($query) => $query->whereNotNull('series.category_id')->whereIn('series.category_id', $this->customCategoryIds($names)));
        });
    }

    /**
     * The ids of the playlist's custom groups named by name_internal, plus the groups
     * folded into a merged one, so selecting a merged group selects what it holds. A
     * custom group named like a provider group is never matched, so a provider pick
     * can't widen to it (see Group::scopeNonProvider()).
     *
     * @param  array<int, string>  $names
     */
    private function customGroupIds(array $names): Builder
    {
        $named = fn () => Group::query()->select('id')
            ->where('playlist_id', $this->playlist_id)
            ->where('custom', true)
            ->nonProvider()
            ->whereIn('name_internal', $names);

        return Group::query()->select('id')
            ->where('playlist_id', $this->playlist_id)
            ->where(fn ($query) => $query->whereIn('id', $named())->orWhereIn('parent_id', $named()));
    }

    /**
     * The ids of the playlist's non-provider categories named by name_internal, plus
     * the categories folded into a merged one.
     *
     * @param  array<int, string>  $names
     */
    private function customCategoryIds(array $names): Builder
    {
        $named = fn () => Category::query()->select('id')
            ->where('playlist_id', $this->playlist_id)
            ->nonProvider()
            ->whereIn('name_internal', $names);

        return Category::query()->select('id')
            ->where('playlist_id', $this->playlist_id)
            ->where(fn ($query) => $query->whereIn('id', $named())->orWhereIn('parent_id', $named()));
    }

    /**
     * Restrict a channels query to the channels whose effective custom playlist group is
     * in the given names: the custom group tag assigned to the channel, or — for channels
     * with no such tag — the provider group name they fall back to in the M3U and Xtream
     * output.
     *
     * @param  array<string>  $allowedNames
     */
    public function constrainChannelsToCustomGroups($query, array $allowedNames): void
    {
        $tagType = $this->customPlaylistTagType();
        $tagIds = $this->resolveCustomTagIds($tagType, $allowedNames);

        $query->where(function ($query) use ($tagIds, $allowedNames, $tagType): void {
            $query->whereExists(function ($query) use ($tagIds): void {
                $query->selectRaw('1')
                    ->from('taggables')
                    ->whereColumn('taggables.taggable_id', 'channels.id')
                    ->where('taggables.taggable_type', Channel::class)
                    ->whereIn('taggables.tag_id', $tagIds);
            })->orWhere(function ($query) use ($allowedNames, $tagType): void {
                $query->whereNotExists(function ($query) use ($tagType): void {
                    $query->selectRaw('1')
                        ->from('taggables')
                        ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                        ->whereColumn('taggables.taggable_id', 'channels.id')
                        ->where('taggables.taggable_type', Channel::class)
                        ->where('tags.type', $tagType);
                })->whereNotNull('channels.group')->whereIn('channels.group', $allowedNames);
            });
        });
    }

    /**
     * Restrict a series query to the series whose effective custom playlist category is
     * in the given names, using the same tag-then-provider-category fallback as the
     * channel filter.
     *
     * @param  array<string>  $allowedNames
     */
    public function constrainSeriesToCustomCategories($query, array $allowedNames): void
    {
        $tagType = $this->customPlaylistTagType().'-category';
        $tagIds = $this->resolveCustomTagIds($tagType, $allowedNames);

        $query->where(function ($query) use ($tagIds, $allowedNames, $tagType): void {
            $query->whereExists(function ($query) use ($tagIds): void {
                $query->selectRaw('1')
                    ->from('taggables')
                    ->whereColumn('taggables.taggable_id', 'series.id')
                    ->where('taggables.taggable_type', Series::class)
                    ->whereIn('taggables.tag_id', $tagIds);
            })->orWhere(function ($query) use ($allowedNames, $tagType): void {
                $query->whereNotExists(function ($query) use ($tagType): void {
                    $query->selectRaw('1')
                        ->from('taggables')
                        ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                        ->whereColumn('taggables.taggable_id', 'series.id')
                        ->where('taggables.taggable_type', Series::class)
                        ->where('tags.type', $tagType);
                })->whereExists(function ($query) use ($allowedNames): void {
                    $query->selectRaw('1')
                        ->from('categories')
                        ->whereColumn('categories.id', 'series.category_id')
                        ->whereIn('categories.name', $allowedNames);
                });
            });
        });
    }

    /**
     * Restrict a channels query to the source-scoped groups of a merged alias selection:
     * for each source playlist, channels whose provider group (group_internal) is in
     * that playlist's names. Custom channels follow the same fallback as the standard
     * alias filter: matched on their user-assigned group name, and, for an allow-list,
     * passed through when ungrouped.
     *
     * @param  array<int, array{playlist_id: int, name: string}>  $selections
     */
    public function constrainChannelsToSourceGroups($query, array $selections, bool $includeUngroupedCustom = true): void
    {
        $namesByPlaylist = self::selectionNamesByPlaylist($selections);

        if (empty($namesByPlaylist)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($query) use ($namesByPlaylist, $includeUngroupedCustom): void {
            foreach ($namesByPlaylist as $playlistId => $names) {
                $query->orWhere(function ($query) use ($playlistId, $names, $includeUngroupedCustom): void {
                    $query->where('channels.playlist_id', $playlistId)
                        ->where(function ($query) use ($names, $includeUngroupedCustom): void {
                            $query->where(fn ($query) => $query->whereNotNull('channels.group_internal')->whereIn('channels.group_internal', $names))
                                ->orWhere(fn ($query) => $query->where('channels.is_custom', true)->whereNotNull('channels.group')->whereIn('channels.group', $names));
                            if ($includeUngroupedCustom) {
                                $query->orWhere(fn ($query) => $query->where('channels.is_custom', true)->whereNull('channels.group'));
                            }
                        });
                });
            }
        });
    }

    /**
     * Restrict a groups query to the source-scoped groups of a merged alias selection,
     * matched on the provider-supplied name_internal within each source playlist.
     *
     * @param  array<int, array{playlist_id: int, name: string}>  $selections
     */
    public function constrainGroupsToSourceGroups($query, array $selections): void
    {
        $namesByPlaylist = self::selectionNamesByPlaylist($selections);

        if (empty($namesByPlaylist)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($query) use ($namesByPlaylist): void {
            foreach ($namesByPlaylist as $playlistId => $names) {
                $query->orWhere(function ($query) use ($playlistId, $names): void {
                    $query->where('groups.playlist_id', $playlistId)
                        ->whereNotNull('groups.name_internal')
                        ->whereIn('groups.name_internal', $names);
                });
            }
        });
    }

    /**
     * Restrict a series query to the source-scoped categories of a merged alias
     * selection. Names are resolved to each source playlist's own source_category_id so
     * one provider's numeric category id can never match another provider's.
     *
     * @param  array<int, array{playlist_id: int, name: string}>  $selections
     */
    public function constrainSeriesToSourceCategories($query, array $selections): void
    {
        $idsByPlaylist = $this->resolveSourceCategoryIds($selections);

        if (empty($idsByPlaylist)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($query) use ($idsByPlaylist): void {
            foreach ($idsByPlaylist as $playlistId => $sourceCategoryIds) {
                $query->orWhere(function ($query) use ($playlistId, $sourceCategoryIds): void {
                    $query->where('series.playlist_id', $playlistId)
                        ->whereNotNull('series.source_category_id')
                        ->whereIn('series.source_category_id', $sourceCategoryIds);
                });
            }
        });
    }

    /**
     * Resolve {playlist_id, name} category selections to playlist id => source
     * category ids, memoised per selection because the filter is applied per query.
     *
     * @param  array<int, array{playlist_id: int, name: string}>  $selections
     * @return array<int, array<int>>
     */
    private function resolveSourceCategoryIds(array $selections): array
    {
        $namesByPlaylist = self::selectionNamesByPlaylist($selections);
        $memoKey = serialize($namesByPlaylist);

        if (array_key_exists($memoKey, $this->resolvedSourceCategoryIds)) {
            return $this->resolvedSourceCategoryIds[$memoKey];
        }

        if (empty($namesByPlaylist)) {
            return $this->resolvedSourceCategoryIds[$memoKey] = [];
        }

        return $this->resolvedSourceCategoryIds[$memoKey] = SourceCategory::query()
            ->where(function ($query) use ($namesByPlaylist): void {
                foreach ($namesByPlaylist as $playlistId => $names) {
                    $query->orWhere(function ($query) use ($playlistId, $names): void {
                        $query->where('playlist_id', $playlistId)->whereIn('name', $names);
                    });
                }
            })
            ->get(['playlist_id', 'source_category_id'])
            ->groupBy('playlist_id')
            ->map(fn ($rows) => $rows->pluck('source_category_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->all();
    }

    /**
     * The Spatie tag type that scopes group tags to this alias's custom playlist.
     */
    private function customPlaylistTagType(): ?string
    {
        if (! $this->custom_playlist_id) {
            return null;
        }

        if (! $this->relationLoaded('customPlaylist')) {
            $this->load('customPlaylist');
        }

        return $this->customPlaylist?->uuid;
    }

    /**
     * Resolve tag display names to tag IDs.
     *
     * Tag names are translatable JSON, so they are matched in PHP rather than in SQL to
     * keep the lookup locale-aware and portable across the supported database drivers. The
     * name => id map for a tag type is memoised because the filter is applied per query.
     *
     * @param  array<string>  $names
     * @return array<int>
     */
    private function resolveCustomTagIds(?string $tagType, array $names): array
    {
        if (! $tagType) {
            return [];
        }

        $this->resolvedCustomTagIds[$tagType] ??= Tag::where('type', $tagType)
            ->select(['id', 'name'])
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [(string) $tag->getAttributeValue('name') => $tag->id])
            ->all();

        return array_values(array_intersect_key($this->resolvedCustomTagIds[$tagType], array_flip($names)));
    }

    public function enabled_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function enabled_series(): BelongsToMany|HasManyThrough
    {
        return $this->series()
            ->where('enabled', true);
    }

    public function live_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): BelongsToMany|HasManyThrough
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): BelongsToMany|HasManyThrough
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function enableProxy(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    // Check playlist user has access to proxy features
                    if (! $this->user?->canUseProxy()) {
                        return false;
                    }
                }

                return $value;
            }
        );
    }

    public function liveCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->live_channels()->count()
        );
    }

    public function vodCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->vod_channels()->count()
        );
    }

    public function seriesCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->series()->count()
        );
    }

    /**
     * Get the alias credentials (username/password) as an object instead of array.
     *
     * This normalises the alias authentication format so that controllers and
     * services can safely access:
     *
     *      $auth->username
     *      $auth->password
     *
     * regardless of whether the credentials originally came from PlaylistAlias
     * (array) or PlaylistAuth (Eloquent model / object).
     *
     * @return object|null
     */
    public function getAuthObjectAttribute()
    {
        // If explicit alias-level credentials exist, always prefer them.
        if ($this->username && $this->password) {
            return (object) [
                'username' => $this->username,
                'password' => $this->password,
            ];
        }

        return null;
    }

    /**
     * Fetch the Xtream status for this alias
     */
    public function xtreamStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $key = "a:{$attributes['id']}:xtream_status";
                $cached = Cache::get($key);
                if ($cached !== null) {
                    return $cached;
                }

                // Dispatch job to update in background if not cached/cache expired
                UpdateXtreamStats::dispatch($this);

                // Return stored value from database
                $results = is_string($value)
                    ? json_decode($value, true)
                    : ($value ?? []);

                return $results;
            }
        );
    }

    /**
     * Transform channel URL using this alias's provider entries.
     *
     * Each entry's `url` is the provider URL used to decide which streams the entry
     * applies to. A matching stream gets the entry's credentials swapped in (when set)
     * and, when URL replacement is enabled, its provider URL replaced with the entry's
     * `replace_url`. Streams that match no entry are returned untouched.
     */
    public function transformChannelUrl(Channel $channel): string
    {
        $originalUrl = $channel->url_custom ?: ($channel->url ?? '');

        return $this->transformStreamUrl($originalUrl, $channel->getEffectivePlaylist()?->xtream_config);
    }

    /**
     * Transform episode URL using this alias's provider entries.
     *
     * See transformChannelUrl() for how entries are matched and applied.
     */
    public function transformEpisodeUrl(Episode $episode): string
    {
        return $this->transformStreamUrl($episode->url ?? '', $episode->getEffectivePlaylist()?->xtream_config);
    }

    /**
     * Apply only this alias's provider URL replacement to an already-resolved stream URL.
     *
     * Used by the proxy when a provider profile of the source playlist has already
     * picked the credentials: the profile's credentials are kept, and the provider URL
     * is swapped when the matching entry has URL replacement enabled.
     *
     * @param  array<string,mixed>|null  $playlistXtreamConfig
     */
    public function replaceProviderUrl(string $url, ?array $playlistXtreamConfig): string
    {
        $match = $this->matchProviderEntry($url, $playlistXtreamConfig);
        if (! $match) {
            return $url;
        }

        [$entry, $sourceConfig] = $match;

        return $this->swapProviderBaseUrl($url, (string) ($sourceConfig['url'] ?? ''), $entry) ?? $url;
    }

    /**
     * Whether a provider entry has its own credentials to swap in.
     *
     * @param  array<string,mixed>  $entry
     */
    public static function entryHasCredentials(array $entry): bool
    {
        return filled($entry['username'] ?? null) && filled($entry['password'] ?? null);
    }

    /**
     * Whether a provider entry replaces the provider URL clients receive.
     *
     * @param  array<string,mixed>  $entry
     */
    public static function entryReplacesUrl(array $entry): bool
    {
        return (bool) ($entry['replace_url_enabled'] ?? false) && filled($entry['replace_url'] ?? null);
    }

    /**
     * The first provider entry that has its own credentials, for calls that talk to the
     * provider's Xtream API as this alias (stats, connection info).
     *
     * @return array<string,mixed>|null
     */
    public function getPrimaryCredentialConfig(): ?array
    {
        foreach ($this->xtream_config as $entry) {
            if (self::entryHasCredentials($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $playlistXtreamConfig
     */
    private function transformStreamUrl(string $originalUrl, ?array $playlistXtreamConfig): string
    {
        $match = $this->matchProviderEntry($originalUrl, $playlistXtreamConfig);
        if (! $match) {
            return $originalUrl;
        }

        [$entry, $sourceConfig] = $match;

        return $this->rewriteStreamUrl($originalUrl, $sourceConfig, $entry);
    }

    /**
     * Find the provider entry that applies to a stream URL, along with the source config
     * describing what the URL currently contains.
     *
     * For Xtream playlists the stored xtream_config is the source of truth, and the entry
     * whose provider URL matches it is used. When none matches, a standard-playlist or
     * single-entry alias falls back to its first entry (e.g. after the playlist's DNS
     * failover moved it to another URL); a multi-provider alias leaves the stream alone
     * rather than applying another provider's entry. For M3U playlists (no stored config) the entry
     * is the one whose provider URL the stream URL starts with; credentials are parsed from
     * the stream URL when it is Xtream-shaped.
     *
     * @param  array<string,mixed>|null  $playlistXtreamConfig
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null
     */
    private function matchProviderEntry(string $streamUrl, ?array $playlistXtreamConfig): ?array
    {
        $primaryEntry = $this->getPrimaryXtreamConfig();
        if (! $primaryEntry || $streamUrl === '') {
            return null;
        }

        if ($playlistXtreamConfig) {
            $entry = $this->findXtreamConfigByUrl((string) ($playlistXtreamConfig['url'] ?? ''));

            if (! $entry && ($this->playlist_id !== null || count($this->xtream_config) === 1)) {
                $entry = $primaryEntry;
            }

            return $entry ? [$entry, $playlistXtreamConfig] : null;
        }

        $entry = $this->findEntryForStreamUrl($streamUrl);
        if (! $entry) {
            return null;
        }

        $entryUrl = rtrim((string) $entry['url'], '/');
        $parsedConfig = self::parseXtreamStreamUrl($streamUrl);
        $sourceConfig = $parsedConfig && strcasecmp(rtrim($parsedConfig['url'], '/'), $entryUrl) === 0
            ? $parsedConfig
            : ['url' => substr($streamUrl, 0, strlen($entryUrl))];

        return [$entry, $sourceConfig];
    }

    /**
     * Find the entry whose provider URL the stream URL starts with (longest match wins).
     *
     * @return array<string,mixed>|null
     */
    private function findEntryForStreamUrl(string $streamUrl): ?array
    {
        $bestEntry = null;
        $bestLength = 0;

        foreach ($this->xtream_config as $entry) {
            $entryUrl = rtrim((string) ($entry['url'] ?? ''), '/');
            if ($entryUrl !== '' && strlen($entryUrl) > $bestLength && self::urlStartsWithBase($streamUrl, $entryUrl)) {
                $bestEntry = $entry;
                $bestLength = strlen($entryUrl);
            }
        }

        return $bestEntry;
    }

    /**
     * Rewrite a stream URL from its source config to a provider entry.
     *
     * @param  array<string,mixed>  $sourceConfig
     * @param  array<string,mixed>  $entry
     */
    private function rewriteStreamUrl(string $originalUrl, array $sourceConfig, array $entry): string
    {
        $sourceBaseUrl = rtrim((string) ($sourceConfig['url'] ?? ''), '/');

        if (self::entryHasCredentials($entry)) {
            $outputBaseUrl = self::entryReplacesUrl($entry)
                ? rtrim((string) $entry['replace_url'], '/')
                : rtrim((string) ($entry['url'] ?? ''), '/');

            $rewritten = $this->swapCredentials(
                $originalUrl,
                $sourceBaseUrl,
                (string) ($sourceConfig['username'] ?? ''),
                (string) ($sourceConfig['password'] ?? ''),
                $outputBaseUrl,
                (string) $entry['username'],
                (string) $entry['password'],
            );

            if ($rewritten !== null) {
                return $rewritten;
            }
        }

        return $this->swapProviderBaseUrl($originalUrl, $sourceBaseUrl, $entry) ?? $originalUrl;
    }

    /**
     * Replace the provider URL at the start of a stream URL with the entry's replacement
     * URL, keeping the rest of the URL (path, credentials, stream ID) as it is.
     *
     * @param  array<string,mixed>  $entry
     */
    private function swapProviderBaseUrl(string $url, string $sourceBaseUrl, array $entry): ?string
    {
        if (! self::entryReplacesUrl($entry)) {
            return null;
        }

        $replaceUrl = rtrim((string) $entry['replace_url'], '/');

        foreach ([rtrim($sourceBaseUrl, '/'), rtrim((string) ($entry['url'] ?? ''), '/')] as $baseUrl) {
            if ($baseUrl !== '' && self::urlStartsWithBase($url, $baseUrl)) {
                return $replaceUrl.substr($url, strlen($baseUrl));
            }
        }

        return null;
    }

    /**
     * Whether a URL is the base URL or a path beneath it (case-insensitive).
     */
    private static function urlStartsWithBase(string $url, string $baseUrl): bool
    {
        return strcasecmp($url, $baseUrl) === 0
            || str_starts_with(strtolower($url), strtolower($baseUrl).'/');
    }

    /**
     * Swap the credentials (and base URL) of an Xtream-style stream URL.
     *
     * Returns null when the URL does not carry the source credentials.
     */
    private function swapCredentials(
        string $originalUrl,
        string $sourceBaseUrl,
        string $sourceUsername,
        string $sourcePassword,
        string $outputBaseUrl,
        string $outputUsername,
        string $outputPassword,
    ): ?string {
        if ($sourceBaseUrl === '' || $sourceUsername === '' || $sourcePassword === '' || $outputBaseUrl === '') {
            return null;
        }

        // Pattern matches:
        // http://domain:port/(live|series|movie)/username/password/<stream>
        $pattern =
            '#^'.preg_quote($sourceBaseUrl, '#').
            '/(live|series|movie)/'.preg_quote($sourceUsername, '#').
            '/'.preg_quote($sourcePassword, '#').
            '/(.+)$#';

        if (preg_match($pattern, $originalUrl, $matches)) {
            return "{$outputBaseUrl}/{$matches[1]}/{$outputUsername}/{$outputPassword}/{$matches[2]}";
        }

        // Prefix-less live form (common in M3U exports):
        // http://domain:port/username/password/<stream>
        $pattern =
            '#^'.preg_quote($sourceBaseUrl, '#').
            '/'.preg_quote($sourceUsername, '#').
            '/'.preg_quote($sourcePassword, '#').
            '/(.+)$#';

        if (preg_match($pattern, $originalUrl, $matches)) {
            return "{$outputBaseUrl}/{$outputUsername}/{$outputPassword}/{$matches[1]}";
        }

        return null;
    }

    /**
     * Parse an Xtream-style stream URL into a config array.
     *
     * Used as a source-config fallback for M3U-imported playlists, which have
     * no stored xtream config but commonly contain Xtream-style stream URLs.
     *
     * @return array{url: string, username: string, password: string}|null
     */
    private static function parseXtreamStreamUrl(string $url): ?array
    {
        // Prefixed form: http(s)://domain:port/(live|series|movie)/username/password/<stream>
        if (preg_match('#^(https?://[^/]+)/(?:live|series|movie)/([^/]+)/([^/]+)/.+$#', $url, $matches)) {
            return [
                'url' => $matches[1],
                'username' => $matches[2],
                'password' => $matches[3],
            ];
        }

        // Prefix-less live form: http(s)://domain:port/username/password/<numeric id>[.ext]
        // Requiring a numeric stream ID avoids matching arbitrary three-segment URLs.
        if (preg_match('#^(https?://[^/]+)/([^/]+)/([^/]+)/\d+(?:\.\w+)?$#', $url, $matches)) {
            return [
                'url' => $matches[1],
                'username' => $matches[2],
                'password' => $matches[3],
            ];
        }

        return null;
    }

    /**
     * PlaylistAuth assignments for this alias (polymorphic many-to-many).
     */
    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function playlistViewers(): MorphMany
    {
        return $this->morphMany(PlaylistViewer::class, 'viewerable');
    }

    public function bouquets(): BelongsToMany
    {
        return $this->belongsToMany(Bouquet::class, 'bouquet_playlist_alias')
            ->using(BouquetPlaylistAlias::class);
    }

    /**
     * Companion fix to the bouquet rename propagation: rewrite provider group
     * renames into the playlist's aliases' manual group_filter (and, for live,
     * the custom sort order), which previously went silently stale. Quiet saves:
     * output is identical before and after (names track the provider), so no
     * EPG-cache invalidation or other update side effects are wanted.
     *
     * @param  array<string, string>  $renames
     */
    public static function applyProviderGroupRenames(int $playlistId, string $type, array $renames): void
    {
        $key = self::CONTENT_FILTER_KEYS[$type];

        self::where('playlist_id', $playlistId)->cursor()->each(function (self $alias) use ($key, $type, $renames): void {
            $filter = $alias->group_filter ?? [];
            $changed = false;

            $map = function (array $names) use ($renames): array {
                return array_values(array_unique(
                    array_map(fn (string $name): string => $renames[$name] ?? $name, $names)
                ));
            };

            $current = $filter[$key] ?? [];
            if (! empty($current) && ($updated = $map($current)) !== $current) {
                $filter[$key] = $updated;
                $changed = true;
            }

            if ($type === 'live') {
                $order = $filter['live_group_order'] ?? [];
                if (! empty($order) && ($updatedOrder = $map($order)) !== $order) {
                    $filter['live_group_order'] = $updatedOrder;
                    $changed = true;
                }
            }

            if ($changed) {
                $alias->updateQuietly(['group_filter' => $filter]);
            }
        });
    }
}
