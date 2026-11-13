<?php

namespace App\Enums;

enum WatchHistoryProvider: string
{
    case Trakt = 'trakt';
    case Simkl = 'simkl';
    case Mdblist = 'mdblist';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trakt => 'Trakt',
            self::Simkl => 'SIMKL',
            self::Mdblist => 'MDBList',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trakt => 'danger',
            self::Simkl => 'info',
            self::Mdblist => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $provider): array => [$provider->value => $provider->getLabel()])
            ->all();
    }
}
