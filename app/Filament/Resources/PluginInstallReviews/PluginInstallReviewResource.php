<?php

namespace App\Filament\Resources\PluginInstallReviews;

use App\Filament\Resources\PluginInstallReviews\Pages\EditPluginInstallReview;
use App\Filament\Resources\PluginInstallReviews\Pages\ListPluginInstallReviews;
use App\Models\PluginInstallReview;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class PluginInstallReviewResource extends Resource
{
    protected static ?string $model = PluginInstallReview::class;

    public static function getModelLabel(): string
    {
        return __('Plugin Install');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Plugin Installs');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Plugins');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canManagePlugins();
    }

    public static function useFakeScanner(): bool
    {
        return config('plugins.clamav.driver', 'fake') === 'fake';
    }

    public static function getNavigationLabel(): string
    {
        return __('Installs');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Review Summary'))
                ->columns(2)
                ->schema([
                    TextInput::make('plugin_id')->disabled(),
                    TextInput::make('plugin_name')->disabled(),
                    TextInput::make('source_type')->disabled(),
                    TextInput::make('source_origin')->disabled(),
                    TextInput::make('status')->disabled(),
                    TextInput::make('validation_status')->disabled(),
                    TextInput::make('scan_status')->disabled()->hidden(fn () => static::useFakeScanner()),
                    TextInput::make('archive_filename')->disabled(),
                    TextInput::make('expected_archive_sha256')->disabled(),
                    TextInput::make('archive_sha256')->disabled(),
                    TextInput::make('installed_path')->disabled()->columnSpanFull(),
                    TextInput::make('source_path')->disabled()->columnSpanFull(),
                    TextInput::make('extracted_path')->disabled()->columnSpanFull(),
                ]),
            Section::make(__('Requested Access'))
                ->columns(2)
                ->schema([
                    Placeholder::make('permissions_preview')
                        ->hiddenLabel()
                        ->content(fn (?PluginInstallReview $record) => collect($record?->permissions ?? [])->implode(', ') ?: 'No permissions declared.'),
                    Placeholder::make('capabilities_preview')
                        ->hiddenLabel()
                        ->content(fn (?PluginInstallReview $record) => collect($record?->capabilities ?? [])->implode(', ') ?: 'No capabilities declared.'),
                    Placeholder::make('high_risk_permissions')
                        ->label(__('High-Risk Permissions'))
                        ->content(function (?PluginInstallReview $record): string {
                            $highRisk = collect($record?->permissions ?? [])
                                ->intersect(['network_egress', 'filesystem_write', 'schema_manage'])
                                ->values();

                            return $highRisk->isNotEmpty()
                                ? $highRisk->implode(', ')
                                : 'No high-risk permissions declared.';
                        })
                        ->columnSpanFull(),
                    Textarea::make('schema_json')
                        ->label(__('Schema'))
                        ->disabled()
                        ->rows(10)
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?PluginInstallReview $record) => json_encode($record?->schema_definition ?? [], JSON_PRETTY_PRINT)),
                    Textarea::make('ownership_json')
                        ->label(__('Data Ownership'))
                        ->disabled()
                        ->rows(10)
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?PluginInstallReview $record) => json_encode($record?->data_ownership ?? [], JSON_PRETTY_PRINT)),
                ]),
            Section::make(fn () => static::useFakeScanner() ? 'Validation' : 'Validation And Scan')
                ->columns(2)
                ->schema([
                    Textarea::make('validation_errors_json')
                        ->label(__('Validation Errors'))
                        ->disabled()
                        ->rows(10)
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?PluginInstallReview $record) => json_encode($record?->validation_errors ?? [], JSON_PRETTY_PRINT)),
                    Textarea::make('scan_details_json')
                        ->label(__('Scan Details'))
                        ->disabled()
                        ->rows(10)
                        ->dehydrated(false)
                        ->hidden(fn () => static::useFakeScanner())
                        ->formatStateUsing(fn (?PluginInstallReview $record) => json_encode($record?->scan_details ?? [], JSON_PRETTY_PRINT)),
                    Textarea::make('source_metadata_json')
                        ->label(__('Source Metadata'))
                        ->disabled()
                        ->rows(10)
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?PluginInstallReview $record) => json_encode($record?->source_metadata ?? [], JSON_PRETTY_PRINT)),
                ]),
            Section::make(__('Review Notes'))
                ->schema([
                    Textarea::make('review_notes')
                        ->disabled()
                        ->rows(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('plugin_id')->searchable(),
                TextColumn::make('source_type')->badge(),
                TextColumn::make('source_origin')->limit(40)->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('validation_status')->badge(),
                TextColumn::make('scan_status')->badge()->hidden(fn () => static::useFakeScanner()),
                TextColumn::make('created_at')->since()->sortable(),
                TextColumn::make('installed_at')->since()->sortable(),
            ])->recordActions([
                DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()->canManagePlugins())
                    ->modalDescription(__('Removes the selected reviews from the system. This does not affect the installed plugins or their files on disk.')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPluginInstallReviews::route('/'),
            'edit' => EditPluginInstallReview::route('/{record}/edit'),
        ];
    }
}
