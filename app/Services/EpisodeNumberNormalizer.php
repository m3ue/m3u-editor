<?php

namespace App\Services;

final class EpisodeNumberNormalizer
{
    /**
     * @param  array<int, mixed>  $episodeNumbers
     * @return array<int, array{system: string|null, value: string}>
     */
    public static function normalize(array $episodeNumbers): array
    {
        $normalizedEpisodeNumbers = [];

        foreach ($episodeNumbers as $episodeNumber) {
            if (! is_array($episodeNumber)) {
                continue;
            }

            $system = array_key_exists('system', $episodeNumber)
                ? $episodeNumber['system']
                : null;
            $value = trim((string) ($episodeNumber['value'] ?? ''));

            if ($system !== null && ! is_string($system)) {
                continue;
            }

            if ($value === '' || ($system === 'xmltv_ns' && ! self::isValidXmltvNamespace($value))) {
                continue;
            }

            $normalizedEpisodeNumbers[] = [
                'system' => $system,
                'value' => $value,
            ];
        }

        return $normalizedEpisodeNumbers;
    }

    /**
     * @param  array<string, mixed>  $programme
     * @return array<int, array{system: string|null, value: string}>
     */
    public static function forProgramme(array $programme): array
    {
        if (! array_key_exists('episode_nums', $programme)) {
            return self::normalize([[
                'system' => 'xmltv_ns',
                'value' => $programme['episode_num'] ?? '',
            ]]);
        }

        return is_array($programme['episode_nums'])
            ? self::normalize($programme['episode_nums'])
            : [];
    }

    private static function isValidXmltvNamespace(string $value): bool
    {
        $valueWithoutWhitespace = preg_replace('/\s+/', '', $value) ?? '';

        return $valueWithoutWhitespace !== '..'
            && preg_match('/^(?:\d+(?:\/0*[1-9]\d*)?)?\.(?:\d+(?:\/0*[1-9]\d*)?)?\.(?:\d+(?:\/0*[1-9]\d*)?)?$/', $valueWithoutWhitespace) === 1;
    }
}
