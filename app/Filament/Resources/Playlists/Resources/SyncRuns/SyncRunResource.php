<?php

namespace App\Filament\Resources\Playlists\Resources\SyncRuns;

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\SyncRun;
use App\Services\DateFormatService;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $parentResource = PlaylistResource::class;

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return PlaylistResource::asParent()
            ->relationship('syncRuns');
    }

    protected static ?string $recordTitleAttribute = 'created_at';

    protected static ?string $label = 'Sync Run';

    protected static ?string $pluralLabel = 'Sync Runs';

    /**
     * Format a duration in seconds as a human-readable string (e.g. "2m 13s" or "4.7s").
     */
    private static function formatDuration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        if ($seconds >= 60) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        return round($seconds, 1).'s';
    }

    /**
     * Format an optional datetime using the application's date format service.
     */
    private static function formatDate(mixed $state): string
    {
        if (! $state) {
            return '—';
        }

        return app(DateFormatService::class)->format($state);
    }

    /**
     * Resolve the badge color for a given SyncRunStatus string value.
     */
    private static function statusBadgeColor(string $state): string
    {
        return match ($state) {
            SyncRunStatus::Completed->value => 'success',
            SyncRunStatus::Running->value => 'warning',
            SyncRunStatus::Failed->value => 'danger',
            SyncRunStatus::Cancelled->value => 'gray',
            default => 'info',
        };
    }

    /**
     * Resolve a phase enum value to its human-readable label, falling back to the raw value.
     */
    private static function phaseLabel(?string $state): string
    {
        if ($state === null) {
            return '—';
        }

        return SyncRunPhase::tryFrom($state)?->getLabel() ?? $state;
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
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('trigger')
                            ->label(__('Trigger'))
                            ->badge()
                            ->color('gray'),
                        Infolists\Components\TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->color(fn (string $state): string => self::statusBadgeColor($state)),
                        Infolists\Components\TextEntry::make('duration')
                            ->label(__('Duration'))
                            ->formatStateUsing(fn (?float $state): string => self::formatDuration($state)),
                        Infolists\Components\TextEntry::make('current_phase')
                            ->label(__('Current Phase'))
                            ->formatStateUsing(fn (?string $state): string => self::phaseLabel($state)),
                        Infolists\Components\TextEntry::make('started_at')
                            ->label(__('Started'))
                            ->formatStateUsing(fn ($state): string => self::formatDate($state)),
                        Infolists\Components\TextEntry::make('finished_at')
                            ->label(__('Finished'))
                            ->formatStateUsing(fn ($state): string => self::formatDate($state)),
                    ]),

                Section::make(__('Phase Timeline'))
                    ->columnSpanFull()
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('phase_timeline')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('label')
                                    ->label(__('Phase')),
                                Infolists\Components\TextEntry::make('status')
                                    ->label(__('Status'))
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'completed' => 'success',
                                        'running' => 'warning',
                                        'failed' => 'danger',
                                        'skipped' => 'gray',
                                        default => 'info',
                                    }),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Started'))
                    ->formatStateUsing(fn ($state): string => self::formatDate($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('trigger')
                    ->label(__('Trigger'))
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => self::statusBadgeColor($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('current_phase')
                    ->label(__('Phase'))
                    ->formatStateUsing(fn (?string $state): string => self::phaseLabel($state))
                    ->toggleable(),
                TextColumn::make('duration')
                    ->label(__('Duration'))
                    ->state(fn (SyncRun $record): ?float => $record->duration)
                    ->formatStateUsing(fn (?float $state): string => self::formatDuration($state))
                    ->toggleable(),
                TextColumn::make('finished_at')
                    ->label(__('Finished'))
                    ->formatStateUsing(fn ($state): string => self::formatDate($state))
                    ->toggleable(),
            ])
            ->filters([])
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
            'view' => Pages\ViewSyncRun::route('/{record}'),
        ];
    }
}
