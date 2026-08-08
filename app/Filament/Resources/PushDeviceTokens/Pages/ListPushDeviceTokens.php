<?php

namespace App\Filament\Resources\PushDeviceTokens\Pages;

use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ListPushDeviceTokens extends ListRecords
{
    protected static string $resource = PushDeviceTokenResource::class;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function getSubheading(): string|Htmlable|null
    {
        return $this->activeTab === 'pairing'
            ? __('Enter the code shown on M3U TV, or scan its QR code with your phone, then choose which credential to sign it in with.')
            : __('Mobile devices registered to receive push notifications through the relay. Devices are added automatically when the app registers for push, and pruned automatically after :days days without a check-in.', ['days' => config('services.push_relay.stale_days', 60)]);
    }

    public function getHeaderActions(): array
    {
        return [];
    }

    public function mount(): void
    {
        parent::mount();

        $tabs = $this->getTabs();

        if ($this->activeTab === null || ! array_key_exists($this->activeTab, $tabs)) {
            $this->activeTab = array_key_first($tabs);
        }

        $code = (string) request()->query('code', '');
        $this->data = [
            'user_code' => self::normalizeUserCode($code),
            'playlist_auth_id' => null,
        ];
    }

    public function getTabs(): array
    {
        $tabs = [];

        if (PushDeviceTokenResource::isPushRelayEnabled()) {
            $tabs['devices'] = Tab::make(__('Devices'));
        }

        if (PushDeviceTokenResource::isDevicePairingEnabled()) {
            $tabs['pairing'] = Tab::make(__('Device Pairing'));
        }

        return $tabs;
    }

    /**
     * Uppercases and re-inserts the dash so "xkqp9f3t", "xkqp 9f3t", and
     * "XKQP-9F3T" all normalize to the same stored format — only the 8
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

    public function content(Schema $schema): Schema
    {
        if ($this->activeTab === 'pairing') {
            return $schema
                ->components([
                    $this->getTabsContentComponent(),
                    Section::make(__('Pair a Device'))
                        ->icon('heroicon-o-device-phone-mobile')
                        ->compact()
                        ->description(__('Enter the code shown on M3U TV, or scan its QR code with your phone, then choose which credential to sign it in with.'))
                        ->schema([
                            TextInput::make('user_code')
                                ->label(__('Device Code'))
                                ->required()
                                ->maxLength(12)
                                ->placeholder('XXXX-XXXX'),
                            Select::make('playlist_auth_id')
                                ->label(__('Grant Access As'))
                                ->required()
                                ->searchable()
                                ->options(fn (): array => PlaylistAuth::where('user_id', auth()->id())->pluck('name', 'id')->all())
                                ->helperText(__('M3U TV will sign in using this credential\'s username and password.')),
                        ]),
                    SchemaActions::make([
                        Action::make('approve')
                            ->label(__('Pair Device'))
                            ->action(fn () => $this->approve()),
                    ]),

                ])
                ->statePath('data');
        }

        return $schema->components([
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    public function approve(): void
    {
        if (! PushDeviceTokenResource::isDevicePairingEnabled()) {
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

        $playlistAuth = PlaylistAuth::where('id', $data['playlist_auth_id'] ?? null)
            ->where('user_id', auth()->id())
            ->first();

        if ($playlistAuth === null) {
            RateLimiter::hit($rateLimitKey, 600);

            Notification::make()
                ->danger()
                ->title(__('Invalid or expired code'))
                ->send();

            return;
        }

        DB::transaction(function () use ($deviceAuth, $playlistAuth): void {
            $deviceAuth->update([
                'status' => 'approved',
                'playlist_auth_id' => $playlistAuth->id,
                'approved_by_user_id' => auth()->id(),
                'approved_ip' => request()->ip(),
            ]);
        });

        RateLimiter::clear($rateLimitKey);

        $this->data = ['user_code' => '', 'playlist_auth_id' => null];

        Notification::make()
            ->success()
            ->title(__('Device paired'))
            ->body(__('The TV should connect automatically within a few seconds.'))
            ->send();
    }
}
