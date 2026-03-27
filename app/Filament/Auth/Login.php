<?php

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    public bool $localBypass = false;

    public function mount(): void
    {
        $this->localBypass = request()->has('local');

        // Auto-redirect to OIDC provider if configured
        if (
            config('services.oidc.enabled')
            && config('services.oidc.auto_redirect')
            && ! $this->localBypass
            && ! session()->has('oidc_error')
        ) {
            $this->redirect(route('auth.oidc.redirect'));

            return;
        }

        parent::mount();
    }

    /**
     * Whether the login form should be hidden in favour of the SSO button.
     */
    private function shouldHideLoginForm(): bool
    {
        return config('services.oidc.enabled')
            && config('services.oidc.hide_login_form')
            && ! $this->localBypass;
    }

    /**
     * Get the form fields for the component.
     */
    public function form(Schema $schema): Schema
    {
        if ($this->shouldHideLoginForm()) {
            return $schema
                ->components([])
                ->statePath('data');
        }

        return $schema
            ->components([
                // $this->getEmailFormComponent(),
                $this->getLoginFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ])
            ->statePath('data');
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        if ($this->shouldHideLoginForm()) {
            return [];
        }

        return parent::getFormActions();
    }

    public function getFormContentComponent(): Component
    {
        if ($this->shouldHideLoginForm()) {
            return Group::make([])
                ->visible(false);
        }

        return parent::getFormContentComponent();
    }

    /**
     * Get the login form component.
     */
    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Login')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Login using either username or email address.
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login_type = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        return [
            $login_type => $data['login'],
            'password' => $data['password'],
        ];
    }

    /**
     * Failure message.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => 'Invalid login or password. Please try again.',
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (session()->has('oidc_error')) {
            $error = e(session('oidc_error'));

            return new HtmlString(
                "<span class=\"text-danger-600 dark:text-danger-400\">{$error}</span>"
            );
        }

        return parent::getSubheading();
    }
}
