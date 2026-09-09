<?php

namespace App\Services;

/**
 * Shared channel matching for cross-playlist operations (Copy Changes and Provider Migration).
 *
 * Provides a single normalization routine plus ordered, uniqueness-guarded match passes so the
 * apply job and the interactive preview page never diverge on how two channels are considered
 * "the same".
 */
class ChannelMatchResolver
{
    /**
     * Ordered match passes. Each pass is only applied to source rows not already matched by an
     * earlier pass, and only accepts a key that is unique on BOTH the source and target side.
     */
    public const PASS_TVG_ID = 'tvg_id';

    public const PASS_NAME = 'name';

    public const PASS_TITLE = 'title';

    /**
     * The default ordered pass list for a provider migration.
     *
     * @var list<string>
     */
    public const DEFAULT_PASSES = [
        self::PASS_TVG_ID,
        self::PASS_NAME,
        self::PASS_TITLE,
    ];

    /**
     * Normalize a scalar value for matching: lower-cased, trimmed, internal whitespace collapsed.
     * Returns null for values that are empty once normalized.
     */
    public function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Resolve the key for a single channel under a given pass, or null when the channel has no
     * usable value for that pass.
     *
     * @param  object  $channel  Channel model or lightweight row with the relevant attributes
     */
    public function keyFor(object $channel, string $pass): ?string
    {
        return match ($pass) {
            self::PASS_TVG_ID => $this->normalize($channel->stream_id_custom ?? $channel->stream_id ?? null),
            self::PASS_NAME => $this->normalize($channel->name_custom ?? $channel->name ?? null),
            self::PASS_TITLE => $this->normalize($channel->title_custom ?? $channel->title ?? null),
            default => null,
        };
    }

    /**
     * Build a per-pass index of channels keyed by their normalized value, split into rows whose
     * key is unique and rows whose key collides with another row.
     *
     * @param  iterable<int, object>  $channels
     * @return array{unique: array<string, object>, ambiguous: array<string, list<object>>}
     */
    public function buildIndex(iterable $channels, string $pass): array
    {
        /** @var array<string, list<object>> $byKey */
        $byKey = [];

        foreach ($channels as $channel) {
            $key = $this->keyFor($channel, $pass);
            if ($key === null) {
                continue;
            }
            $byKey[$key][] = $channel;
        }

        $unique = [];
        $ambiguous = [];
        foreach ($byKey as $key => $rows) {
            if (count($rows) === 1) {
                $unique[$key] = $rows[0];
            } else {
                $ambiguous[$key] = $rows;
            }
        }

        return ['unique' => $unique, 'ambiguous' => $ambiguous];
    }

    /**
     * Resolve source channels to target channels using the ordered passes.
     *
     * @param  iterable<int, object>  $sourceChannels  each row must expose id + the pass attributes
     * @param  iterable<int, object>  $targetChannels  each row must expose id + the pass attributes
     * @param  list<string>  $passes
     * @return array{
     *     matched: array<int, array{target_id: int, pass: string, key: string}>,
     *     ambiguous: array<int, array{pass: string, key: string, target_ids: list<int>}>,
     *     unmatched_source: list<int>,
     *     matched_target_ids: list<int>,
     *     unmatched_target: list<int>
     * }
     */
    public function resolve(iterable $sourceChannels, iterable $targetChannels, array $passes = self::DEFAULT_PASSES): array
    {
        $source = $this->toList($sourceChannels);
        $target = $this->toList($targetChannels);

        $matched = [];
        $ambiguous = [];
        $matchedTargetIds = [];

        $remainingSource = $source;

        foreach ($passes as $pass) {
            if (empty($remainingSource)) {
                break;
            }

            $sourceIndex = $this->buildIndex($remainingSource, $pass);
            $targetIndex = $this->buildIndex($target, $pass);

            $stillRemaining = [];

            foreach ($remainingSource as $sourceChannel) {
                $key = $this->keyFor($sourceChannel, $pass);

                // Source value collides with another source row under this pass: cannot use it.
                if ($key === null || isset($sourceIndex['ambiguous'][$key])) {
                    if ($key !== null && isset($targetIndex['ambiguous'][$key]) && ! isset($ambiguous[$sourceChannel->id])) {
                        $ambiguous[$sourceChannel->id] = [
                            'pass' => $pass,
                            'key' => $key,
                            'target_ids' => array_map(static fn ($c) => (int) $c->id, $targetIndex['ambiguous'][$key]),
                        ];
                    }
                    $stillRemaining[] = $sourceChannel;

                    continue;
                }

                // Unique on the source side. Check the target side.
                if (isset($targetIndex['ambiguous'][$key])) {
                    $ambiguous[$sourceChannel->id] = [
                        'pass' => $pass,
                        'key' => $key,
                        'target_ids' => array_map(static fn ($c) => (int) $c->id, $targetIndex['ambiguous'][$key]),
                    ];
                    $stillRemaining[] = $sourceChannel;

                    continue;
                }

                $targetChannel = $targetIndex['unique'][$key] ?? null;
                if ($targetChannel === null) {
                    $stillRemaining[] = $sourceChannel;

                    continue;
                }

                $targetId = (int) $targetChannel->id;

                // Guard against many source rows landing on one target row across passes.
                if (in_array($targetId, $matchedTargetIds, true)) {
                    $stillRemaining[] = $sourceChannel;

                    continue;
                }

                $matched[(int) $sourceChannel->id] = [
                    'target_id' => $targetId,
                    'pass' => $pass,
                    'key' => $key,
                ];
                $matchedTargetIds[] = $targetId;
                unset($ambiguous[$sourceChannel->id]);
            }

            $remainingSource = $stillRemaining;
        }

        $unmatchedSource = [];
        foreach ($remainingSource as $sourceChannel) {
            $sourceId = (int) $sourceChannel->id;
            if (! isset($ambiguous[$sourceId])) {
                $unmatchedSource[] = $sourceId;
            }
        }

        $allTargetIds = array_map(static fn ($c) => (int) $c->id, $target);
        $unmatchedTarget = array_values(array_diff($allTargetIds, $matchedTargetIds));

        return [
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'unmatched_source' => $unmatchedSource,
            'matched_target_ids' => $matchedTargetIds,
            'unmatched_target' => $unmatchedTarget,
        ];
    }

    /**
     * @param  iterable<int, object>  $channels
     * @return list<object>
     */
    private function toList(iterable $channels): array
    {
        if (is_array($channels)) {
            return array_values($channels);
        }

        $list = [];
        foreach ($channels as $channel) {
            $list[] = $channel;
        }

        return $list;
    }
}
