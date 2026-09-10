<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManageApiSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $slug = 'api';

    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string
    {
        return __('API');
    }

    public function getTitle(): string
    {
        return __('API');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('API Settings'))
                    ->headerActions([
                        Action::make('manage_api_keys')
                            ->label(__('Manage API Tokens'))
                            ->color('gray')
                            ->icon('heroicon-s-key')
                            ->iconPosition('before')
                            ->size('sm')
                            ->url('/personal-access-tokens'),
                        Action::make('view_api_docs')
                            ->label(__('API Docs'))
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url('/docs/api')
                            ->openUrlInNewTab(true),
                    ])->schema([
                        Toggle::make('show_api_docs')
                            ->label(__('Allow access to API docs'))
                            ->helperText(__('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.')),
                    ]),
            ]);
    }
}
