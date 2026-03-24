<?php

namespace App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs;

use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\Pages\ViewChannelScrubberLog;
use App\Models\ChannelScrubberLog;
use App\Services\DateFormatService;
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

class ChannelScrubberLogResource extends Resource
{
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
                Section::make('Run Summary')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Ran At')
                            ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state)),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state) => $state === 'completed' ? 'success' : 'danger'),
                        Infolists\Components\TextEntry::make('runtime')
                            ->label('Runtime')
                            ->formatStateUsing(fn ($state): string => $state ? gmdate('H:i:s', (int) $state) : '-'),
                        Infolists\Components\TextEntry::make('channel_count')
                            ->label('Channels Checked'),
                        Infolists\Components\TextEntry::make('dead_count')
                            ->label('Dead Links Found')
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                        Infolists\Components\TextEntry::make('disabled_count')
                            ->label('Channels Disabled')
                            ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                    ]),
                Section::make('Dead Channels')
                    ->columnSpanFull()
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('meta')
                            ->label(false)
                            ->hiddenLabel()
                            ->schema([
                                Infolists\Components\TextEntry::make('title')
                                    ->label('Channel'),
                                Infolists\Components\TextEntry::make('url')
                                    ->label('URL')
                                    ->columnSpan(2),
                            ])
                            ->columns(3)
                            ->contained(false)
                            ->placeholder('No dead channels recorded for this run.'),
                    ])
                    ->hidden(fn ($record) => empty($record->meta)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Ran At')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'completed' ? 'success' : 'danger')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('channel_count')
                    ->label('Channels Checked')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('dead_count')
                    ->label('Dead Links')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('disabled_count')
                    ->label('Channels Disabled')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('runtime')
                    ->label('Runtime')
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewChannelScrubberLog::route('/{record}'),
        ];
    }
}
