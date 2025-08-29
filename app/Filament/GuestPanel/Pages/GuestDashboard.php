<?php

namespace App\Filament\GuestPanel\Pages;

use App\Facades\PlaylistFacade;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use App\Filament\GuestPanel\Pages\Concerns\HandlesGuestAuth;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Forms;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Illuminate\Support\Facades\Session;

class GuestDashboard extends Page implements HasSchemas
{
    use HandlesGuestAuth;
    use InteractsWithSchemas;

    protected string $view = 'filament.guest-panel.pages.guest-dashboard';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-play';
    protected static ?string $navigationLabel = 'Playlist';
    protected static ?string $title = '';
    protected static ?string $slug = 'guest';

    public ?array $data = [];
    public $authError = '';

    public function mount(): void
    {
        $this->form->fill([
            'username' => session('guest_auth_username', ''),
            'password' => session('guest_auth_password', ''),
        ]);
    }

    static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static function getCurrentUuid(): ?string
    {
        return request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();
        return route(static::getRouteName($panel), $parameters, $isAbsolute);
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
}
