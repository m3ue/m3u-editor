<?php

namespace App\Filament\Resources\ChannelScrubbers\RelationManagers;

use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class ScrubberLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Run Logs';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
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
                Action::make('view')
                    ->icon('heroicon-s-eye')
                    ->button()->hiddenLabel()->size('sm')
                    ->tooltip(__('View log details'))
                    ->url(function ($record): string {
                        return "/channel-scrubbers/{$this->getOwnerRecord()->id}/channel-scrubber-logs/{$record->id}";
                    }),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
