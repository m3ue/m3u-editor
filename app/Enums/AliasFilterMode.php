<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a playlist alias filters one content type (live, VOD or series).
 *
 * Block bouquets hide their groups in every mode. In Only, the alias's own picks and
 * its allow bouquets are the allow-list, and nothing else gets through. In Except,
 * the alias's own picks are hidden instead.
 */
enum AliasFilterMode: string implements HasIcon, HasLabel
{
    case All = 'all';
    case Only = 'only';
    case Except = 'except';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('Everything'),
            self::Only => __('Only selected'),
            self::Except => __('Everything except selected'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::All => 'heroicon-o-squares-2x2',
            self::Only => 'heroicon-o-check-circle',
            self::Except => 'heroicon-o-no-symbol',
        };
    }
}
