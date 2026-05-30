<?php

namespace App\Services;

use App\Models\Channel;

class ChannelMergeKeyService
{
    public const MODE_NORMALIZED_NAME = 'normalized_name';

    public const MODE_ALIAS_RULES = 'alias_rules';

    public const MODE_NORMALIZED_NAME_AND_ALIAS_RULES = 'normalized_name_and_alias_rules';

    /**
     * Return the fallback merge key for a channel, or null when fallback matching is disabled.
     *
     * Fallback matching is intentionally conservative. It only applies to channels without a
     * usable stream ID and it never removes quality tokens like HD, UHD, 4K or SD.
     *
     * @param  array<string, mixed>  $config
     */
    public function fallbackKeyFor(Channel $channel, array $config): ?string
    {
        if (! $this->isEnabled($config) || $this->hasUsableStreamId($channel)) {
            return null;
        }

        $mode = $this->mode($config);
        $normalizedNames = $this->normalizedChannelNames($channel);

        if ($this->usesAliasRules($mode)) {
            $aliasMap = $this->buildAliasMap($config['alias_rules'] ?? []);
            foreach ($normalizedNames as $name) {
                if (isset($aliasMap[$name])) {
                    return 'alias:'.$aliasMap[$name];
                }
            }
        }

        if ($this->usesNormalizedName($mode)) {
            return $normalizedNames[0] ?? null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function isEnabled(array $config): bool
    {
        return (bool) ($config['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function mode(array $config): string
    {
        $mode = $config['mode'] ?? self::MODE_NORMALIZED_NAME;

        return in_array($mode, [
            self::MODE_NORMALIZED_NAME,
            self::MODE_ALIAS_RULES,
            self::MODE_NORMALIZED_NAME_AND_ALIAS_RULES,
        ], true) ? $mode : self::MODE_NORMALIZED_NAME;
    }

    public function hasUsableStreamId(Channel $channel): bool
    {
        return trim((string) ($channel->stream_id_custom ?: $channel->stream_id)) !== '';
    }

    public function normalizeName(?string $name): ?string
    {
        $normalized = mb_strtolower(trim((string) $name));
        $normalized = preg_replace('/[^\pL\pN]+/u', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedChannelNames(Channel $channel): array
    {
        return collect([
            $channel->title_custom,
            $channel->title,
            $channel->name_custom,
            $channel->name,
        ])
            ->map(fn ($name) => $this->normalizeName($name))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Build alias lookup and drop ambiguous aliases that appear in more than one group.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, string>
     */
    private function buildAliasMap(array $rules): array
    {
        $aliasesByName = [];
        $groupByAlias = [];

        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $label = $this->normalizeName($rule['label'] ?? null) ?? 'group'.$index;
            $aliases = $rule['aliases'] ?? [];
            if (is_string($aliases)) {
                $aliases = preg_split('/\r\n|\r|\n|,/', $aliases) ?: [];
            }
            if (! is_array($aliases)) {
                $aliases = [];
            }

            $aliases[] = $rule['label'] ?? null;

            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalizeName(is_scalar($alias) ? (string) $alias : null);
                if ($normalizedAlias === null) {
                    continue;
                }

                $aliasesByName[$normalizedAlias] = $aliasesByName[$normalizedAlias] ?? [];
                $aliasesByName[$normalizedAlias][$label] = true;
            }
        }

        foreach ($aliasesByName as $alias => $groups) {
            if (count($groups) === 1) {
                $groupByAlias[$alias] = array_key_first($groups);
            }
        }

        return $groupByAlias;
    }

    private function usesAliasRules(string $mode): bool
    {
        return in_array($mode, [self::MODE_ALIAS_RULES, self::MODE_NORMALIZED_NAME_AND_ALIAS_RULES], true);
    }

    private function usesNormalizedName(string $mode): bool
    {
        return in_array($mode, [self::MODE_NORMALIZED_NAME, self::MODE_NORMALIZED_NAME_AND_ALIAS_RULES], true);
    }
}
