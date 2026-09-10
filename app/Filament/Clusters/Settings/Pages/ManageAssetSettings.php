<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Filament\Resources\Assets\AssetResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ManageAssetSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $slug = 'assets';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('Assets');
    }

    public function getTitle(): string
    {
        return __('Assets');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Logo Cache'))
                    ->description(__('Manage logo cache behavior and storage used by logo proxy URLs.'))
                    ->columns(1)
                    ->headerActions([
                        Action::make('manage_assets')
                            ->label(__('Manage Assets'))
                            ->color('gray')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url(AssetResource::getUrl('index')),
                        Action::make('view_repo')
                            ->label(__('View Logo Repository'))
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url('/logo-repository')
                            ->hidden(fn ($get) => ! $get('logo_repository_enabled'))
                            ->openUrlInNewTab(true),
                    ])
                    ->schema([
                        Toggle::make('logo_cache_permanent')
                            ->label(__('Keep cache permanently (disable expiry cleanup)'))
                            ->helperText(__('When enabled, expired cache cleanup will skip deletion. You can still refresh/clear cache manually.')),
                        Toggle::make('logo_repository_enabled')
                            ->label(__('Enable Logo Repository endpoint'))
                            ->live()
                            ->helperText(__('When enabled, /logo-repository endpoints are publicly accessible for apps like UHF.')),

                    ]),
                Section::make(__('Placeholder Images'))
                    ->description(__('Override app-wide placeholder images for logos, episode previews, and VOD/Series poster fallbacks.'))
                    ->columns(3)
                    ->schema([
                        FileUpload::make('logo_placeholder_url')
                            ->label(__('Logo placeholder'))
                            ->image()
                            ->disk('public')
                            ->directory('assets/placeholders')
                            ->visibility('public')
                            ->openable()
                            ->downloadable()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Used when a channel logo is missing. Clear to use the default placeholder.')
                            )
                            ->helperText(new HtmlString('<strong>Recommended size:</strong> 300x300px for best results.<br/>Default image: <img src="'.url('/placeholder.png').'" alt="Default Logo Placeholder" style="width:80px; height:80px; margin-top:5px;">')),
                        FileUpload::make('episode_placeholder_url')
                            ->label(__('Episode preview placeholder'))
                            ->image()
                            ->disk('public')
                            ->directory('assets/placeholders')
                            ->visibility('public')
                            ->openable()
                            ->downloadable()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Used when an episode preview image is missing. Clear to use the default placeholder.')
                            )
                            ->helperText(new HtmlString('<strong>Recommended size:</strong> 600x400px for best results.<br/>Default image: <img src="'.url('/episode-placeholder.png').'" alt="Default Episode Placeholder" style="width:120px; height:80px; margin-top:5px;">')),
                        FileUpload::make('vod_series_poster_placeholder_url')
                            ->label(__('VOD/Series poster placeholder'))
                            ->image()
                            ->disk('public')
                            ->directory('assets/placeholders')
                            ->visibility('public')
                            ->openable()
                            ->downloadable()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Used when VOD/Series poster or cover images are missing. Clear to use the default placeholder.')
                            )
                            ->helperText(new HtmlString('<strong>Recommended size:</strong> 600x900px for best results.<br/>Default image: <img src="'.url('/vod-series-poster-placeholder.png').'" alt="Default VOD/Series Poster Placeholder" style="width:80px; height:120px; margin-top:5px;">')),
                    ]),
            ]);
    }
}
