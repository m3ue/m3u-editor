<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class KoFiWidget extends Widget
{
    protected int|string|array $columnSpan = [
        'sm' => 1,
        'lg' => 2,
    ];

    protected string $view = 'filament.widgets.ko-fi-widget';
}
