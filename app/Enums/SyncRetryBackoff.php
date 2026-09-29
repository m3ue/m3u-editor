<?php

namespace App\Enums;

/**
 * Retry ladder used when a playlist sync is invalidated (see ProcessM3uImportComplete).
 *
 * Each step is the cooldown (in minutes) before the next automatic retry. Once the
 * ladder is exhausted the playlist falls back to its regular sync schedule, and the
 * ladder starts over if that scheduled sync is invalidated again.
 */
enum SyncRetryBackoff: string
{
    case Aggressive = 'aggressive';
    case Balanced = 'balanced';
    case Conservative = 'conservative';
    case None = 'none';

    /**
     * Cooldown in minutes before each retry attempt.
     *
     * @return list<int>
     */
    public function steps(): array
    {
        return match ($this) {
            self::Aggressive => [1, 5, 15],
            self::Balanced => [5, 30, 120],
            self::Conservative => [30, 120, 360],
            self::None => [],
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Aggressive => __('Aggressive (1m, 5m, 15m)'),
            self::Balanced => __('Balanced (5m, 30m, 2h)'),
            self::Conservative => __('Conservative (30m, 2h, 6h)'),
            self::None => __("Don't auto-retry (wait for next scheduled sync)"),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
