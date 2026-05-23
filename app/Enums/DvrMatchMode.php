<?php

namespace App\Enums;

enum DvrMatchMode: string
{
    case Contains = 'contains';
    case Exact = 'exact';
    case StartsWith = 'starts_with';
    case Tmdb = 'tmdb';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contains => __('Contains'),
            self::Exact => __('Exact Match'),
            self::StartsWith => __('Starts With'),
            self::Tmdb => __('TMDB ID'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Contains => 'info',
            self::Exact => 'success',
            self::StartsWith => 'warning',
            self::Tmdb => 'primary',
        };
    }
}
