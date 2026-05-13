<?php

namespace App\Filament\GuestPanel\Resources\DvrRecordings;

use App\Enums\DvrRecordingStatus;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestDvr;
use App\Jobs\ProcessComskipOnRecording;
use App\Jobs\StopDvrRecording;
use App\Models\DvrRecording;
use App\Tables\Columns\AnimatedStatusColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GuestDvrRecordingResource extends Resource
{
    use HasGuestDvr;

    protected static ?string $model = DvrRecording::class;

    protected static ?string $slug = 'dvr';

    public static function getNavigationLabel(): string
    {
        return __('DVR');
    }

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-video-camera';

    public static function canAccess(): bool
    {
        return static::guestCanAccessDvr();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::guestCanAccessDvr()) {
            return null;
        }

        $dvrSetting = static::getDvrSetting();
        if (! $dvrSetting) {
            return null;
        }

        $count = $dvrSetting->recordings()
            ->whereIn('status', [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();
        $routeName = static::getRouteBaseName($panel).'.'.($name ?? 'index');

        return route($routeName, $parameters, $isAbsolute);
    }

    public static function getEloquentQuery(): Builder
    {
        $dvrSetting = static::getDvrSetting();
        if (! $dvrSetting) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->with(['channel', 'playlistAuth'])
            ->where('dvr_setting_id', $dvrSetting->id)
            ->orderByDesc('scheduled_start');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Recording Details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('subtitle')
                        ->hidden(fn ($record) => ! $record->subtitle),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('post_processing_step')
                        ->label(__('Current Step'))
                        ->hidden(fn ($record) => ! $record->post_processing_step),
                    TextEntry::make('channel.title')->label(__('Channel')),
                    TextEntry::make('scheduled_start')->dateTime(),
                    TextEntry::make('scheduled_end')->dateTime(),
                    TextEntry::make('actual_start')->dateTime()
                        ->hidden(fn ($record) => ! $record->actual_start),
                    TextEntry::make('actual_end')->dateTime()
                        ->hidden(fn ($record) => ! $record->actual_end),
                    TextEntry::make('duration_seconds')
                        ->label(__('Duration'))
                        ->formatStateUsing(fn (?int $state): string => $state
                            ? gmdate('H:i:s', $state)
                            : '—'),
                    TextEntry::make('file_size_bytes')
                        ->label(__('File Size'))
                        ->formatStateUsing(fn (?int $state): string => $state
                            ? number_format($state / 1024 / 1024, 1).' MB'
                            : '—'),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->columnSpanFull()
                        ->hidden(fn ($record) => ! $record->description),
                    TextEntry::make('error_message')
                        ->label(__('Error'))
                        ->columnSpanFull()
                        ->color('danger')
                        ->hidden(fn ($record) => ! $record->error_message),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $currentAuth = static::getCurrentPlaylistAuth();

        return $table
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Filter')))
            ->defaultSort('scheduled_start', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (DvrRecording $record): string => $record->subtitle ?? ''),
                AnimatedStatusColumn::make('status')
                    ->sortable(),
                TextColumn::make('channel.title')
                    ->label(__('Channel'))
                    ->sortable(),
                TextColumn::make('scheduled_start')
                    ->label(__('Starts'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('scheduled_end')
                    ->label(__('Ends'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duration_seconds')
                    ->label(__('Duration'))
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? gmdate('H:i:s', $state)
                        : '—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('file_size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? number_format($state / 1024 / 1024, 1).' MB'
                        : '—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DvrRecordingStatus::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->button()
                        ->icon('heroicon-s-eye')
                        ->hiddenLabel()
                        ->modalHeading(fn (DvrRecording $record) => $record->display_title),
                    Action::make('cancel')
                        ->label(__('Cancel'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->button()
                        ->hiddenLabel()
                        ->visible(function (DvrRecording $record) use ($currentAuth): bool {
                            if (! in_array($record->status, [
                                DvrRecordingStatus::Scheduled,
                                DvrRecordingStatus::Recording,
                            ])) {
                                return false;
                            }

                            // Guests can only cancel recordings they created
                            return $currentAuth && $record->playlist_auth_id === $currentAuth->id;
                        })
                        ->requiresConfirmation()
                        ->modalDescription(__('This will stop the recording. Are you sure?'))
                        ->action(function (DvrRecording $record) use ($currentAuth): void {
                            // Backend guard — prevents forged Livewire calls bypassing ->visible()
                            if (! $currentAuth || $record->playlist_auth_id !== $currentAuth->id) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Unauthorized'))
                                    ->send();

                                return;
                            }

                            if (! in_array($record->status, [
                                DvrRecordingStatus::Scheduled,
                                DvrRecordingStatus::Recording,
                            ])) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Recording cannot be cancelled'))
                                    ->send();

                                return;
                            }

                            StopDvrRecording::dispatch($record->id);

                            Notification::make()
                                ->success()
                                ->title(__('Recording cancellation queued'))
                                ->send();
                        }),
                    Action::make('reprocessComskip')
                        ->label(__('Reprocess Comskip'))
                        ->icon('heroicon-o-scissors')
                        ->color('gray')
                        ->visible(fn (DvrRecording $record): bool => $record->hasFilePath())
                        ->requiresConfirmation()
                        ->modalDescription(__('Re-run commercial detection (comskip) on the existing recording file. Any existing .edl file will be overwritten.'))
                        ->action(function (DvrRecording $record): void {
                            ProcessComskipOnRecording::dispatch($record->id);

                            Notification::make()
                                ->success()
                                ->title(__('Comskip reprocessing queued'))
                                ->send();
                        }),
                ])->button()->hiddenLabel()->size('sm'),
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
            'index' => Pages\ListGuestDvrRecordings::route('/'),
            'view' => Pages\ViewGuestDvrRecording::route('/{record}'),
        ];
    }
}
