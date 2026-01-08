<?php

namespace App\Filament\Auth;

use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->description('Update your profile information')
                    ->schema([
                        // Forms\Components\FileUpload::make('avatar_url')
                        //     ->disk('public')
                        //     ->avatar(),
                        // TextInput::make('username')->required()->maxLength(255),
                        $this->getNameFormComponent(),

                        // $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent()
                            ->helperText('Leave blank to keep the current password'),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
            ]);
    }
}
