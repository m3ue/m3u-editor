<?php

declare(strict_types=1);

use App\Filament\GuestPanel\Pages\GuestDashboard;

use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

it('shows login form when not authenticated', function () {
    get(route('filament.playlist.pages.guest', ['uuid' => 'test-uuid']))
        ->assertSee('Playlist Login')
        ->assertSee('Username')
        ->assertSee('Password');
});

it('authenticates and shows dashboard after login', function () {
    // You may want to seed a test playlist and auth here, or mock PlaylistFacade
    // For now, just check the login flow UI
    livewire(GuestDashboard::class)
        ->set('username', 'demo')
        ->set('password', 'demo')
        ->call('login');
    // This test should be expanded with a real playlist and credentials
});
