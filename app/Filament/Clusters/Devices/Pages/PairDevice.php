<?php

namespace App\Filament\Clusters\Devices\Pages;

use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use App\Filament\Resources\TvDevices\TvDeviceResource;
use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PairDevice extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $cluster = DevicesCluster::class;

    protected static ?string $slug = 'pairing';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin()
            && TvDeviceResource::isDevicePairingEnabled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return TvDeviceResource::isDevicePairingEnabled();
    }

    public static function getNavigationLabel(): string
    {
        return __('Device Pairing');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Device Pairing');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Enter the code shown on M3U TV, or scan its QR code with your phone, then choose which credentials to sign it in with.');
    }

    public function mount(): void
    {
        $code = (string) request()->query('code', '');

        $this->data = [
            'user_code' => self::normalizeUserCode($code),
            'playlist_auth_ids' => self::emptyCredentialRows(),
        ];
    }

    /**
     * One empty row, keyed the way Filament keys repeater items.
     *
     * @return array<string, null>
     */
    private static function emptyCredentialRows(): array
    {
        return [(string) Str::uuid() => null];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Pair a Device'))
                    ->icon('heroicon-o-qr-code')
                    ->compact()
                    ->description(__('Enter the code shown on M3U TV, or scan its QR code with your phone, then choose which credentials to sign it in with.'))
                    ->schema([
                        TextInput::make('user_code')
                            ->label(__('Device Code'))
                            ->required()
                            ->maxLength(12)
                            ->placeholder('XXXX-XXXX')
                            ->mask('****-****')
                            ->stripCharacters('-'),
                        Repeater::make('playlist_auth_ids')
                            ->label(__('Grant Access As'))
                            ->simple(
                                Select::make('playlist_auth_id')
                                    ->label(__('Credential'))
                                    ->required()
                                    ->searchable()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->options(fn (): array => PlaylistAuth::where('user_id', auth()->id())->pluck('name', 'id')->all()),
                            )
                            ->minItems(1)
                            ->reorderable()
                            ->addActionLabel(__('Add credential'))
                            ->hintAction(Action::make('manage')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->iconPosition('after')
                                ->label(__('Manage Auth'))
                                ->url(PlaylistAuthResource::getUrl())
                                ->openUrlInNewTab())
                            ->helperText(__('M3U TV saves each credential as a playlist, in this order, and signs in with the first one.')),
                    ]),
                SchemaActions::make([
                    Action::make('approve')
                        ->label(__('Pair Device'))
                        ->action(fn () => $this->approve()),
                ]),
            ])
            ->statePath('data');
    }

    /**
     * Uppercases and re-inserts the dash so "xkqp9f3t", "xkqp 9f3t", and
     * "XKQP-9F3T" all normalize to the same stored format - only the 8
     * alphanumeric characters are actually meaningful to the user.
     */
    private static function normalizeUserCode(?string $state): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $state) ?? '');

        if (strlen($normalized) === 8) {
            $normalized = substr($normalized, 0, 4).'-'.substr($normalized, 4);
        }

        return $normalized;
    }

    public function approve(): void
    {
        if (! TvDeviceResource::isDevicePairingEnabled()) {
            return;
        }

        $rateLimitKey = 'device-pairing-attempts:'.auth()->id();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            Notification::make()
                ->danger()
                ->title(__('Too many attempts'))
                ->body(__('Please wait a few minutes before trying again.'))
                ->send();

            return;
        }

        $data = $this->data;
        $userCode = self::normalizeUserCode($data['user_code'] ?? null);

        $deviceAuth = DeviceAuthorization::where('user_code', $userCode)
            ->where('status', 'pending')
            ->first();

        if ($deviceAuth === null || $deviceAuth->isExpired()) {
            RateLimiter::hit($rateLimitKey, 600);

            $deviceAuth?->delete();

            Notification::make()
                ->danger()
                ->title(__('Invalid or expired code'))
                ->send();

            return;
        }

        $playlistAuthIds = collect($data['playlist_auth_ids'] ?? [])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $ownedCount = PlaylistAuth::whereIn('id', $playlistAuthIds)
            ->where('user_id', auth()->id())
            ->count();

        if ($playlistAuthIds->isEmpty() || $ownedCount !== $playlistAuthIds->count()) {
            RateLimiter::hit($rateLimitKey, 600);

            Notification::make()
                ->danger()
                ->title(__('Invalid or expired code'))
                ->send();

            return;
        }

        DB::transaction(function () use ($deviceAuth, $playlistAuthIds): void {
            $deviceAuth->update([
                'status' => 'approved',
                'playlist_auth_id' => $playlistAuthIds->first(),
                'playlist_auth_ids' => $playlistAuthIds->all(),
                'approved_by_user_id' => auth()->id(),
                'approved_ip' => request()->ip(),
            ]);
        });

        RateLimiter::clear($rateLimitKey);

        $this->data = ['user_code' => '', 'playlist_auth_ids' => self::emptyCredentialRows()];

        Notification::make()
            ->success()
            ->title(__('Device paired'))
            ->body(__('The TV should connect automatically within a few seconds.'))
            ->send();
    }
}
