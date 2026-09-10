<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Models\StreamFileSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManageSyncSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $slug = 'sync';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('Sync Options');
    }

    public function getTitle(): string
    {
        return __('Sync Options');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Provider Rate Limiting & Concurrency'))
                    ->description(__('Control request concurrency for parallel processing and add delays between requests to avoid provider rate limiting.'))
                    ->columnSpan('full')
                    ->columns(4)
                    ->collapsible(false)
                    ->schema([
                        Toggle::make('enable_provider_request_delay')
                            ->label(__('Enable request delay'))
                            ->live()
                            ->inline(false)
                            ->columnSpan(2)
                            ->helperText(__('When enabled, a delay will be added between requests to the provider during playlist and EPG syncs and other stream processing tasks.')),
                        TextInput::make('provider_max_concurrent_requests')
                            ->label(__('Max concurrent requests'))
                            ->integer()
                            ->columnSpan(2)
                            ->required()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Lower values (1-2) are safer but slower. Set to 1 to process requests sequentially.')
                            )
                            ->minValue(1)
                            ->default(2)
                            ->helperText(__('Maximum number of simultaneous requests allowed. Also controls the level of parallelism for batch operations such as stream probing and channel scrubbing.')),
                        TextInput::make('provider_request_delay_ms')
                            ->label(__('Request delay'))
                            ->integer()
                            ->required()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Recommended: 500-2000ms. Higher values reduce load on provider but increase sync time.')
                            )
                            ->columnSpan(1)
                            ->minValue(100)
                            ->maxValue(10000)
                            ->step(100)
                            ->default(500)
                            ->suffix('ms')
                            ->hidden(fn ($get) => ! $get('enable_provider_request_delay'))
                            ->helperText(__('Minimum delay between provider requests, in milliseconds.')),
                    ]),
                Section::make(__('Sync Invalidation'))
                    ->description(__('Prevent sync from proceeding if conditions are met.'))
                    ->columnSpan('full')
                    ->columns(3)
                    ->collapsible(false)
                    ->schema([
                        Toggle::make('invalidate_import')
                            ->label(__('Enable sync invalidation'))
                            ->columnSpanFull()
                            ->disabled(fn () => ! empty(config('dev.invalidate_import')))
                            ->live()
                            ->hint(fn () => ! empty(config('dev.invalidate_import')) ? __('Already set by environment variable!') : null)
                            ->default(function () {
                                return ! empty(config('dev.invalidate_import')) ? (bool) config('dev.invalidate_import') : false;
                            })
                            ->afterStateHydrated(function (Toggle $component, $state) {
                                if (! empty(config('dev.invalidate_import'))) {
                                    $component->state((bool) config('dev.invalidate_import'));
                                }
                            })
                            ->dehydrated(fn () => empty(config('dev.invalidate_import'))),
                        TextInput::make('invalidate_import_threshold')
                            ->label(__('Channel removal threshold'))
                            ->columnSpan(1)
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Some providers frequently remove and re-add groups/categories, which can lead to channels be removed during sync. This setting helps prevent large-scale removals by canceling the sync if the defined number of channels would be removed.')
                            )
                            ->suffixIcon(fn () => ! empty(config('dev.invalidate_import_threshold')) ? 'heroicon-m-lock-closed' : null)
                            ->disabled(fn () => ! empty(config('dev.invalidate_import_threshold')))
                            ->hint(fn () => ! empty(config('dev.invalidate_import_threshold')) ? __('Already set by environment variable!') : null)
                            ->dehydrated(fn () => empty(config('dev.invalidate_import_threshold')))
                            ->placeholder(fn () => empty(config('dev.invalidate_import_threshold')) ? 100 : config('dev.invalidate_import_threshold'))
                            ->hidden(fn ($get) => ! empty(config('dev.invalidate_import')) || ! $get('invalidate_import'))
                            ->numeric()
                            ->helperText(__('If sync will remove more than this number of channels, the sync will be canceled.')),
                        TextInput::make('invalidate_import_series_threshold')
                            ->label(__('Series removal threshold'))
                            ->columnSpan(1)
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Cancel the sync if this many series would be removed. Helps protect against provider API responses that omit series data temporarily.')
                            )
                            ->suffixIcon(fn () => ! empty(config('dev.invalidate_import_series_threshold')) ? 'heroicon-m-lock-closed' : null)
                            ->disabled(fn () => ! empty(config('dev.invalidate_import_series_threshold')))
                            ->hint(fn () => ! empty(config('dev.invalidate_import_series_threshold')) ? __('Already set by environment variable!') : null)
                            ->dehydrated(fn () => empty(config('dev.invalidate_import_series_threshold')))
                            ->placeholder(fn () => empty(config('dev.invalidate_import_series_threshold')) ? 100 : config('dev.invalidate_import_series_threshold'))
                            ->hidden(fn ($get) => ! empty(config('dev.invalidate_import')) || ! $get('invalidate_import'))
                            ->numeric()
                            ->helperText(__('If sync will remove more than this number of series, the sync will be canceled.')),
                        TextInput::make('invalidate_import_group_threshold')
                            ->label(__('Group/category removal threshold'))
                            ->columnSpan(1)
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Cancel the sync if this many groups or categories would be removed. Useful for catching provider outages that drop entire category lists.')
                            )
                            ->suffixIcon(fn () => ! empty(config('dev.invalidate_import_group_threshold')) ? 'heroicon-m-lock-closed' : null)
                            ->disabled(fn () => ! empty(config('dev.invalidate_import_group_threshold')))
                            ->hint(fn () => ! empty(config('dev.invalidate_import_group_threshold')) ? __('Already set by environment variable!') : null)
                            ->dehydrated(fn () => empty(config('dev.invalidate_import_group_threshold')))
                            ->placeholder(fn () => empty(config('dev.invalidate_import_group_threshold')) ? 50 : config('dev.invalidate_import_group_threshold'))
                            ->hidden(fn ($get) => ! empty(config('dev.invalidate_import')) || ! $get('invalidate_import'))
                            ->numeric()
                            ->helperText(__('If sync will remove more than this number of groups/categories, the sync will be canceled.')),
                    ]),
                Section::make(__('Series stream file settings'))
                    ->description(__('Select a Stream File Setting for series .strm file generation.'))
                    ->columnSpan('full')
                    ->columns(1)
                    ->collapsible(false)
                    ->schema([
                        Select::make('default_series_stream_file_setting_id')
                            ->label(__('Default Series Stream File Setting'))
                            ->searchable()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Stream File Settings can be created and managed in Playlist > Stream File Settings. Settings can be overridden at the Category level or per-Series.')
                            )
                            ->options(function () {
                                return StreamFileSetting::where('user_id', auth()->id())
                                    ->forSeries()
                                    ->pluck('name', 'id');
                            })
                            ->hintAction(
                                Action::make('manage_series_settings')
                                    ->label(__('Manage Stream File Settings'))
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->iconPosition('after')
                                    ->size('sm')
                                    ->url('/stream-file-settings')
                                    ->openUrlInNewTab(false)
                            )
                            ->helperText(__('Leave empty to disable .strm file generation for series. Priority: Series > Category > Global.')),
                    ]),
                Section::make(__('VOD stream file settings'))
                    ->description(__('Select a Stream File Setting for VOD .strm file generation. '))
                    ->columnSpan('full')
                    ->columns(1)
                    ->collapsible(false)
                    ->schema([
                        Select::make('default_vod_stream_file_setting_id')
                            ->label(__('Default VOD Stream File Setting'))
                            ->searchable()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Stream File Settings can be created and managed in Playlist > Stream File Settings. Settings can be overridden at the Group level or per-VOD channel.')
                            )
                            ->options(function () {
                                return StreamFileSetting::where('user_id', auth()->id())
                                    ->forVod()
                                    ->pluck('name', 'id');
                            })
                            ->hintAction(
                                Action::make('manage_vod_settings')
                                    ->label(__('Manage Stream File Settings'))
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->iconPosition('after')
                                    ->size('sm')
                                    ->url('/stream-file-settings')
                                    ->openUrlInNewTab(false)
                            )
                            ->helperText(__('Leave empty to disable .strm file generation for VOD. Priority: VOD > Group > Global.')),
                    ]),
            ]);
    }
}
