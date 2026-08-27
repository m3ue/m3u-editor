<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;

class CustomDashboard extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getHeading(): string
    {
        return ''; // Return empty string to hide heading
    }

    /**
     * Two-column magazine grid: full-width widgets span both columns, charts and
     * list widgets pair up side by side.
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }

    protected function getActions(): array
    {
        return [
            //
        ];
    }
}
