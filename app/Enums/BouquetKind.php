<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What a bouquet does to the aliases it is assigned to: an allow bouquet adds its
 * groups to the alias (a package, e.g. Sports), a block bouquet hides its groups
 * whatever else allows them (a reusable exclusion, e.g. no adult content).
 */
enum BouquetKind: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Allow = 'allow';
    case Block = 'block';

    public function getLabel(): string
    {
        return match ($this) {
            self::Allow => __('Allow'),
            self::Block => __('Block'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Allow => __('Aliases using this bouquet include its groups.'),
            self::Block => __('Aliases using this bouquet hide its groups, even when something else allows them.'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Allow => 'heroicon-o-check-circle',
            self::Block => 'heroicon-o-no-symbol',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Allow => 'success',
            self::Block => 'danger',
        };
    }
}
