<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How a playlist's "default" login (username = the playlist owner's name)
 * is accepted by the Xtream API, stream routes and M3U/HDHR outputs.
 * Playlist Auths are unaffected by this setting.
 */
enum DefaultAuthMode: string implements HasColor, HasIcon, HasLabel
{
    case Disabled = 'disabled';
    case Uuid = 'uuid';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Disabled => __('Disabled'),
            self::Uuid => __('UUID as Password'),
            self::Custom => __('Custom Password'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Disabled => 'heroicon-o-no-symbol',
            self::Uuid => 'heroicon-o-finger-print',
            self::Custom => 'heroicon-o-key',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Disabled => 'danger',
            self::Uuid => 'primary',
            self::Custom => 'success',
        };
    }
}
