<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\GuestPanelPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LogoServiceProvider;
use App\Providers\VersionServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    GuestPanelPanelProvider::class,
    HorizonServiceProvider::class,
    LogoServiceProvider::class,
    VersionServiceProvider::class,
];
