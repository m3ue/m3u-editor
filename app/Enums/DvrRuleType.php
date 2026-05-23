<?php

namespace App\Enums;

enum DvrRuleType: string
{
    case Once = 'once';
    case Series = 'series';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Once => __('Once'),
            self::Series => __('Series'),
            self::Manual => __('Manual'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Once => 'info',
            self::Series => 'success',
            self::Manual => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Once => 'heroicon-o-play',
            self::Series => 'heroicon-o-queue-list',
            self::Manual => 'heroicon-o-hand-raised',
        };
    }
}
