<?php

namespace App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\Pages\ViewChannelScrubberLog;
use App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\RelationManagers\DeadChannelsRelationManager;
use App\Models\ChannelScrubberLog;
use App\Services\DateFormatService;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class ChannelScrubberLogResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = ChannelScrubberLog::class;

    protected static ?string $parentResource = ChannelScrubberResource::class;

    protected static ?string $label = 'Run Log';

    protected static ?string $pluralLabel = 'Run Logs';

    protected static ?string $recordTitleAttribute = 'created_at';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return ChannelScrubberResource::asParent()
            ->relationship('logs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Run Summary'))
                    ->columnSpanFull()
                    ->compact()
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label(__('Ran At'))
                            ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state)),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'completed' => 'success',
                                'processing' => 'warning',
                                'cancelled' => 'gray',
                                default => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('runtime')
                            ->label(__('Runtime'))
                            ->formatStateUsing(fn ($state): string => $state ? gmdate('H:i:s', (int) $state) : '-'),
                        Infolists\Components\TextEntry::make('channel_count')
                            ->label(__('Channels Checked')),
                        Infolists\Components\TextEntry::make('dead_count')
                            ->label(__('Dead Links Found'))
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                        Infolists\Components\TextEntry::make('disabled_count')
                            ->label(__('Channels Disabled'))
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                        Infolists\Components\TextEntry::make('live_count')
                            ->label(__('Live Channels'))
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Ran At'))
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'cancelled' => 'gray',
                        default => 'danger',
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('channel_count')
                    ->label(__('Channels Checked'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('dead_count')
                    ->label(__('Dead Links'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('disabled_count')
                    ->label(__('Channels Disabled'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('live_count')
                    ->label(__('Live Channels'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('runtime')
                    ->label(__('Runtime'))
                    ->formatStateUsing(fn ($state): string => $state ? gmdate('H:i:s', (int) $state) : '-')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([])
            ->recordActions([
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DeadChannelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewChannelScrubberLog::route('/{record}'),
        ];
    }
}
