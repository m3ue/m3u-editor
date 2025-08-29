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
    public $playlistUuid = null;
    public $authError = '';

    protected static function getCurrentUuid(): ?string
    {
        return request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
    }

    public function mount(): void
    {
        // Pre-fill form with session data if available
        $this->form->fill([
            'username' => session('guest_auth_username', ''),
            'password' => session('guest_auth_password', ''),
        ]);

        // Load playlist info
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());

        $this->playlist = $playlist;
        $this->playlistName = $playlist->name ?? 'Playlist';
        $this->playlistUuid = $playlist->uuid ?? null;
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

        // If authenticated, check if the playlist UUID matches
        if ($result && $result[0]) {
            if ($result[0]->uuid !== $this->playlistUuid) {
                return false;
            }
            return true;
        }

        return false;
    }

    protected function tryAuthenticate(string $username, string $password): bool
    {
        $result = PlaylistFacade::authenticate($username, $password);
        if ($result && $result[0]) {
            if ($result[0]->uuid !== $this->playlistUuid) {
                return false;
            }
            session(['guest_auth_username' => $username, 'guest_auth_password' => $password]);
            return true;
        }
        return false;
    }

    protected function logoutGuest(): void
    {
        session()->forget(['guest_auth_username', 'guest_auth_password']);
    }
}
