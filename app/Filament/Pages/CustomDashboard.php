<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;

class CustomDashboard extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected function getActions(): array
    {
        return [
            //
        ];
    }
}
