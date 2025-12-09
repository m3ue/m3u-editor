<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Actions;
use Filament\Notifications\Notification;

class CustomDashboard extends Dashboard
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rocket-launch';

    protected function getActions(): array
    {
        return [
            //
        ];
    }
}
