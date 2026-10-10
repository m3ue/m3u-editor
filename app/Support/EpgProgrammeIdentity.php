<?php

namespace App\Support;

use App\Services\EpisodeNumberNormalizer;

final class EpgProgrammeIdentity
{
    private const CONTENT_SYSTEM = 'm3u-editor:content-id';

    private const SERIES_SYSTEM = 'm3u-editor:series-id';

    /**
     * Resolve only source-declared identities; occurrence IDs, titles and numbering are never identities.
     *
     * @param  array<string, mixed>  $programme
     * @return array{content_id?: string, series_id?: string}
     */
    public static function fromProgramme(array $programme): array
    {
        $episodeNumbers = EpisodeNumberNormalizer::forProgramme($programme);
        $explicitContent = self::explicitValues($episodeNumbers, self::CONTENT_SYSTEM);
        $explicitSeries = self::explicitValues($episodeNumbers, self::SERIES_SYSTEM);

        $derivedContent = [];
        $derivedSeries = [];
        foreach ($episodeNumbers as $episodeNumber) {
            if (mb_strtolower(trim((string) ($episodeNumber['system'] ?? ''))) !== 'dd_progid') {
                continue;
            }

            $derived = self::fromGracenoteProgramId((string) ($episodeNumber['value'] ?? ''));
            if (isset($derived['content_id'])) {
                $derivedContent[$derived['content_id']] = true;
            }
            if (isset($derived['series_id'])) {
                $derivedSeries[$derived['series_id']] = true;
            }
        }

        $identity = [];
        $contentId = self::resolveIdentity($explicitContent, array_keys($derivedContent));
        $seriesId = self::resolveIdentity($explicitSeries, array_keys($derivedSeries));

        if ($contentId !== null) {
            $identity['content_id'] = $contentId;
        }
        if ($seriesId !== null) {
            $identity['series_id'] = $seriesId;
        }

        return $identity;
    }

    /** @return array{content_id?: string, series_id?: string} */
    public static function fromSchedulesDirect(object $program): array
    {
        $programId = strtoupper(trim((string) ($program->programID ?? '')));
        $entityType = mb_strtolower(trim((string) ($program->entityType ?? '')));

        if (! preg_match('/\A[A-Z]{2}\d{12}\z/D', $programId)) {
            return [];
        }

        if (str_starts_with($programId, 'EP') && $entityType === 'episode') {
            return self::episodeIdentity($programId);
        }

        if (str_starts_with($programId, 'MV') && in_array($entityType, ['movie', 'tv movie', 'short film'], true)) {
            return ['content_id' => 'gracenote:'.$programId];
        }

        if (str_starts_with($programId, 'SH') && in_array($entityType, ['series', 'show', 'miniseries'], true)) {
            return ['series_id' => 'gracenote:'.$programId];
        }

        return [];
    }

    /** @return array{content_id?: string, series_id?: string} */
    private static function fromGracenoteProgramId(string $programId): array
    {
        $programId = strtoupper(trim($programId));
        if (! preg_match('/\A(?:EP|MV|SH)\d{12}\z/D', $programId)) {
            return [];
        }

        return match (substr($programId, 0, 2)) {
            'EP' => self::episodeIdentity($programId),
            'MV' => ['content_id' => 'gracenote:'.$programId],
            'SH' => ['series_id' => 'gracenote:'.$programId],
        };
    }

    /** @return array{content_id: string, series_id: string} */
    private static function episodeIdentity(string $programId): array
    {
        return [
            'content_id' => 'gracenote:'.$programId,
            'series_id' => 'gracenote:SH'.substr($programId, 2, 8).'0000',
        ];
    }

    /**
     * @param  array<int, array{system: string|null, value: string}>  $episodeNumbers
     * @return array{present: bool, values: list<string>}
     */
    private static function explicitValues(array $episodeNumbers, string $system): array
    {
        $present = false;
        $values = [];

        foreach ($episodeNumbers as $episodeNumber) {
            if (mb_strtolower(trim((string) ($episodeNumber['system'] ?? ''))) !== $system) {
                continue;
            }

            $present = true;
            $value = trim((string) ($episodeNumber['value'] ?? ''));
            if (self::validIdentity($value)) {
                $values[$value] = true;
            }
        }

        return ['present' => $present, 'values' => array_keys($values)];
    }

    /**
     * @param  array{present: bool, values: list<string>}  $explicit
     * @param  list<string>  $derived
     */
    private static function resolveIdentity(array $explicit, array $derived): ?string
    {
        if ($explicit['present']) {
            if (count($explicit['values']) !== 1
                || ($derived !== [] && $derived !== $explicit['values'])) {
                return null;
            }

            return $explicit['values'][0];
        }

        return count($derived) === 1 ? $derived[0] : null;
    }

    private static function validIdentity(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,254}\z/D', $value) === 1;
    }
}
