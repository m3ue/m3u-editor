<?php

namespace App\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ForcePasswordChange extends Component
{
    public bool $show = false;

    #[Validate]
    public string $password = '';

    #[Validate]
    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        if ($user && $user->must_change_password) {
            $this->show = true;
        }
    }

    protected function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (in_array(strtolower($value), ['admin', 'password', '12345678', '123456789', 'qwerty123'])) {
                        $fail('Please choose a more secure password.');
                    }
                },
            ],
            'password_confirmation' => ['required'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $user = auth()->user();

        if (! $user) {
            return;
        }

        $user->update([
            'password' => $this->password,
            'must_change_password' => false,
        ]);

        // Directly update the session's stored password hash so that
        // AuthenticateSession doesn't consider the session stale and log
        // the user out. We cannot use auth()->guard()->login() here because
        // that calls session()->regenerate(), which changes the session ID,
        // invalidates the CSRF token, and causes a "page expired" error in
        // Livewire. Patching the hash in-place avoids all of that.
        $user->refresh();
        session()->put(
            'password_hash_'.config('auth.defaults.guard'),
            $user->getAuthPassword()
        );

        $this->password = '';
        $this->password_confirmation = '';
        $this->show = false;

        Notification::make()
            ->title('Password updated successfully')
            ->success()
            ->send();
    }

    public function render(): View
    {
        return view('livewire.force-password-change');
    }
}
