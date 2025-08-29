<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Facades\PlaylistFacade;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Forms;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Illuminate\Support\Facades\Session;

class GuestAuthPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    public ?array $data = [];
    public $playlist = null;
    public $playlistName = null;
    public $authError = '';

    public function mount(): void
    {
        // Pre-fill form with session data if available
        $this->form->fill([
            'username' => session('guest_auth_username', ''),
            'password' => session('guest_auth_password', ''),
        ]);

        // Load playlist info
        $playlist = PlaylistFacade::resolvePlaylistByUuid($this->getCurrentUuid());

        $this->playlist = $playlist;
        $this->playlistName = $playlist->name ?? 'Playlist';
    }

    public function login(): void
    {
        $state = $this->form->getState();
        $username = $state['username'] ?? '';
        $password = $state['password'] ?? '';
        if ($this->tryAuthenticate($username, $password)) {
            $this->authError = '';
            // Optionally, clear password from form state for security
            $this->form->fill(['username' => $username, 'password' => '']);
        } else {
            $this->authError = 'Invalid credentials.';
        }
    }

    public function logout(): void
    {
        $this->logoutGuest();
        $this->form->fill(['username' => '', 'password' => '']);
        $this->authError = '';
    }

    public function isGuestAuthenticated(): bool
    {
        return $this->isAuthenticated();
    }

    public function getGuestAuthUserData(): ?array
    {
        return $this->getGuestAuthUser();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('username')
                    ->label('Username')
                    ->required(),
                Forms\Components\TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->required(),
            ])->statePath('data');
    }

    protected function isAuthenticated(): bool
    {
        $username = session('guest_auth_username');
        $password = session('guest_auth_password');
        if (!$username || !$password) {
            return false;
        }
        $result = PlaylistFacade::authenticate($username, $password);
        return $result && $result[0];
    }

    protected function tryAuthenticate(string $username, string $password): bool
    {
        $result = PlaylistFacade::authenticate($username, $password);
        if ($result && $result[0]) {
            session(['guest_auth_username' => $username, 'guest_auth_password' => $password]);
            return true;
        }
        return false;
    }

    protected function logoutGuest(): void
    {
        session()->forget(['guest_auth_username', 'guest_auth_password']);
    }

    protected function getGuestAuthUser(): ?array
    {
        $username = session('guest_auth_username');
        $password = session('guest_auth_password');
        if (!$username || !$password) {
            return null;
        }
        $result = PlaylistFacade::authenticate($username, $password);
        return $result && $result[0] ? $result : null;
    }
}
