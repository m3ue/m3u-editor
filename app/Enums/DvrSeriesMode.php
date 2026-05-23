<?php

namespace App\Enums;

enum DvrSeriesMode: string
{
    case All = 'all';
    case NewFlag = 'new_flag';
    case UniqueSe = 'unique_se';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('All Episodes'),
            self::NewFlag => __('New Episodes Only'),
            self::UniqueSe => __('Unique Episodes (S/E)'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::All => 'info',
            self::NewFlag => 'warning',
            self::UniqueSe => 'success',
        };
    }
}
