<?php

namespace App\Support;

/**
 * Channel numbers with an optional sub-channel ("minor") part, as used by
 * over-the-air broadcasts (e.g. "5.1", "5.2", "5.10").
 *
 * The number is stored as two integers (`channel` + `channel_minor`, and the
 * same pair on the custom playlist pivot) rather than a decimal, so "5.1" and
 * "5.10" stay distinct sub-channels and whole-number sorting/renumbering keeps
 * working on the major part.
 */
final class ChannelNumber
{
    /**
     * Validation pattern for an entered channel number: "7", or "7.1" for a sub-channel.
     */
    public const PATTERN = '/^\d{1,9}(\.\d{1,4})?$/';

    /**
     * Split a channel number into its [major, minor] parts.
     *
     * "5.1" => [5, 1], "5.10" => [5, 10], "7" => [7, null] and "" => [null, null].
     * A ".0" minor is treated as no sub-channel. Anything else falls back to an
     * integer cast of the whole value (the previous import behavior). A float (e.g. a
     * JSON-decoded Xtream `num`) can't tell 5.1 from 5.10, so 5.10 arrives as 5.1.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function parse(int|float|string|null $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $value, $matches)) {
            $minor = (int) $matches[2];

            return [(int) $matches[1], $minor > 0 ? $minor : null];
        }

        return [(int) $value, null];
    }

    /**
     * Join a [major, minor] pair back into its display form: an int when there is
     * no sub-channel (so existing output is unchanged), "major.minor" otherwise.
     */
    public static function format(int|string|null $major, int|string|null $minor): int|string|null
    {
        if ($major === null || $major === '') {
            return null;
        }

        $major = (int) $major;
        $minor = (int) $minor;

        return $minor > 0 ? "{$major}.{$minor}" : $major;
    }

    /**
     * Resolve a channel's own output number. A custom playlist pivot number wins
     * when set, and brings its own minor (or none) as a pair, so a "2.1" channel
     * overridden to "7" outputs 7, never 7.1.
     */
    public static function resolve(
        int|string|null $channel,
        int|string|null $channelMinor,
        int|string|null $pivotNumber = null,
        int|string|null $pivotMinor = null,
    ): int|string|null {
        if (! empty($pivotNumber)) {
            return self::format($pivotNumber, $pivotMinor);
        }

        return self::format($channel, $channelMinor);
    }

    /**
     * The whole-number part of a resolved number, for outputs that only accept
     * integers (the Xtream API `num`).
     */
    public static function major(int|string|null $number): ?int
    {
        return $number === null ? null : (int) $number;
    }

    /**
     * The model attributes for a user-entered number.
     *
     * @return array{channel: int|null, channel_minor: int|null}
     */
    public static function toAttributes(int|float|string|null $value): array
    {
        [$major, $minor] = self::parse($value);

        return ['channel' => $major, 'channel_minor' => $minor];
    }

    /**
     * The custom playlist pivot attributes for a user-entered number.
     *
     * @return array{channel_number: int|null, channel_minor: int|null}
     */
    public static function toPivotAttributes(int|string|null $value): array
    {
        [$major, $minor] = self::parse($value);

        return ['channel_number' => $major, 'channel_minor' => $minor];
    }
}
