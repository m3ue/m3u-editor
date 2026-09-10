<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class ManageSmtpSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $slug = 'smtp';

    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string
    {
        return __('SMTP');
    }

    public function getTitle(): string
    {
        return __('SMTP');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('SMTP Settings'))
                    ->description(__('Configure SMTP settings to send emails from the application.'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->headerActions([
                        Action::make('send_test_email')
                            ->label(__('Send Test Email'))
                            ->icon('heroicon-o-envelope')
                            ->iconPosition('after')
                            ->color('gray')
                            ->size('sm')
                            ->modalWidth('md')
                            ->schema([
                                TextInput::make('to_email')
                                    ->label(__('To Email Address'))
                                    ->email()
                                    ->required()
                                    ->placeholder(__('Enter To Email Address'))
                                    ->helperText(__('A test email will be sent to this address using the entered SMTP settings.')),
                            ])
                            ->action(function (array $data, $get): void {
                                try {
                                    // Get SMTP settings from the form state
                                    $formState = $this->form->getState();

                                    // Make sure all required fields are present
                                    if (empty($formState['smtp_host']) || empty($formState['smtp_port']) || empty($formState['smtp_username']) || empty($formState['smtp_password'])) {
                                        Notification::make()
                                            ->danger()
                                            ->title(__('Missing SMTP Fields'))
                                            ->body(__('Please fill in all required SMTP fields before sending a test email.'))
                                            ->send();

                                        return;
                                    }

                                    // Configure mail settings temporarily
                                    Config::set('mail.default', 'smtp');
                                    Config::set('mail.from.address', $formState['smtp_from_address'] ?? 'no-reply@m3u-editor.dev');
                                    Config::set('mail.from.name', 'm3u editor');
                                    Config::set('mail.mailers.smtp.host', $formState['smtp_host']);
                                    Config::set('mail.mailers.smtp.username', $formState['smtp_username']);
                                    Config::set('mail.mailers.smtp.password', $formState['smtp_password']);
                                    Config::set('mail.mailers.smtp.port', $formState['smtp_port']);
                                    Config::set('mail.mailers.smtp.encryption', $formState['smtp_encryption']);

                                    Mail::raw('This is a test email to verify your SMTP settings.', function ($message) use ($data) {
                                        $message->to($data['to_email'])
                                            ->subject('Test Email from m3u editor');
                                    });

                                    Notification::make()
                                        ->success()
                                        ->title(__('Test Email Sent'))
                                        ->body('Test email sent successfully to '.$data['to_email'])
                                        ->send();
                                } catch (Exception $e) {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('Error Sending Test Email'))
                                        ->body($e->getMessage())
                                        ->send();
                                }
                            }),
                    ])
                    ->schema([
                        TextInput::make('smtp_host')
                            ->label(__('SMTP Host'))
                            ->placeholder(__('Enter SMTP Host'))
                            ->requiredWith('smtp_port')
                            ->helperText(__('Required to send emails.')),
                        TextInput::make('smtp_port')
                            ->label(__('SMTP Port'))
                            ->placeholder(__('Enter SMTP Port'))
                            ->requiredWith('smtp_host')
                            ->numeric()
                            ->helperText(__('Required to send emails.')),
                        TextInput::make('smtp_username')
                            ->label(__('SMTP Username'))
                            ->placeholder(__('Enter SMTP Username'))
                            ->requiredWith('smtp_password')
                            ->helperText(__('Required to send emails, if your provider requires authentication.')),
                        TextInput::make('smtp_password')
                            ->label(__('SMTP Password'))
                            ->revealable()
                            ->placeholder(__('Enter SMTP Password'))
                            ->requiredWith('smtp_username')
                            ->password()
                            ->helperText(__('Required to send emails, if your provider requires authentication.')),
                        Select::make('smtp_encryption')
                            ->label(__('SMTP Encryption'))
                            ->options([
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                                null => 'None',
                            ])
                            ->placeholder(__('Select encryption type (optional)')),
                        TextInput::make('smtp_from_address')
                            ->label(__('SMTP From Address'))
                            ->placeholder(__('Enter SMTP From Address'))
                            ->email()
                            ->helperText(__('The "From" email address for outgoing emails. Defaults to no-reply@m3u-editor.dev.')),
                    ]),
            ]);
    }
}
