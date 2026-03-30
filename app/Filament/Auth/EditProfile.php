<?php

namespace App\Filament\Auth;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    /**
     * After the profile is saved, clear the must_change_password flag
     * if the user just fulfilled a forced-password-change requirement.
     */
    protected function afterSave(): void
    {
        $user = auth()->user();
        if ($user && $user->must_change_password && filled($this->data['password'] ?? null)) {
            $user->update(['must_change_password' => false]);
        }
    }

    public function form(Schema $schema): Schema
    {
        $isOidcUser = auth()->user()?->isOidcUser();

        $fields = [
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
        ];

        // OIDC users authenticate via their IdP — hide password fields
        if (! $isOidcUser) {
            $fields[] = $this->getPasswordFormComponent()
                ->helperText('Leave blank to keep the current password')
                ->rules([
                    'min:8',
                    function () {
                        return function (string $attribute, mixed $value, \Closure $fail) {
                            if (filled($value) && in_array(strtolower($value), ['admin', 'password', '12345678', '123456789', 'qwerty123'])) {
                                $fail('Please choose a more secure password.');
                            }
                        };
                    },
                ]);
            $fields[] = $this->getPasswordConfirmationFormComponent();
        }

        return $schema
            ->components([
                Section::make()
                    ->description('Update your profile information')
                    ->schema($fields),
            ]);
    }
}
