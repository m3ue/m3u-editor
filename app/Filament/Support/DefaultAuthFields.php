<?php

namespace App\Filament\Support;

use App\Enums\DefaultAuthMode;
use App\Filament\Actions\GeneratePasswordAction;
use App\Rules\UrlSafeCredential;
use App\Services\PlaylistCredentialResolver;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Default Authentication" fields shared by the Playlist, Custom Playlist, Merged
 * Playlist and Playlist Alias forms: how the owner's own login (their m3u editor
 * username) is accepted - with the playlist UUID, a custom password, or not at all.
 */
class DefaultAuthFields
{
    public static function schema(): array
    {
        return [
            Fieldset::make(__('Default Authentication'))
                ->columns(1)
                ->columnSpanFull()
                ->schema([
                    ToggleButtons::make('default_auth_mode')
                        ->label(__('Default login'))
                        ->options(DefaultAuthMode::class)
                        ->default(DefaultAuthMode::Uuid)
                        ->inline()
                        ->grouped()
                        ->live()
                        ->helperText(fn (Get $get): string => match (self::mode($get)) {
                            DefaultAuthMode::Disabled => __('Only Playlist Auths can access this playlist. Your m3u editor username can no longer log in to its Xtream API, M3U or HDHR outputs.'),
                            DefaultAuthMode::Custom => __('Log in with your m3u editor username and the password below. The UUID no longer works as a password, and the M3U and HDHR URLs require these credentials.'),
                            DefaultAuthMode::Uuid => __('Log in with your m3u editor username and this playlist\'s unique identifier (UUID) as the password.'),
                        }),
                    TextInput::make('default_auth_password')
                        ->label(__('Custom Password'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->rules(fn (?Model $record): array => [new UrlSafeCredential, self::uniquePasswordRule($record)])
                        ->suffixAction(GeneratePasswordAction::make('default_auth_password'))
                        ->visible(fn (Get $get): bool => self::mode($get) === DefaultAuthMode::Custom),
                ]),
        ];
    }

    /**
     * The selected mode: the enum while hydrated from the record, its value once changed.
     */
    private static function mode(Get $get): DefaultAuthMode
    {
        $state = $get('default_auth_mode');

        return $state instanceof DefaultAuthMode
            ? $state
            : DefaultAuthMode::tryFrom((string) $state) ?? DefaultAuthMode::Uuid;
    }

    /**
     * The owner's name + custom password must identify one playlist, so the password
     * can't be shared with another of the owner's Custom Password playlists (of any type).
     */
    private static function uniquePasswordRule(?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $userId = $record?->user_id ?? auth()->id();

            foreach (PlaylistCredentialResolver::PLAYLIST_TYPES as $type) {
                $taken = $type::query()
                    ->where('user_id', $userId)
                    ->where('default_auth_mode', DefaultAuthMode::Custom)
                    ->where('default_auth_password', $value)
                    ->when($record instanceof $type, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->exists();

                if ($taken) {
                    $fail(__('Another of your playlists already uses this password.'));

                    return;
                }
            }
        };
    }
}
