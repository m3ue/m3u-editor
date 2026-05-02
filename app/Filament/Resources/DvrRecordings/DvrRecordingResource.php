<?php

namespace App\Filament\Resources\DvrRecordings;

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Jobs\StopDvrRecording;
use App\Models\DvrRecording;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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

class DvrRecordingResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = DvrRecording::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseDvr();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('DVR');
    }

    public static function getNavigationLabel(): string
    {
        return __('Recordings');
    }

    public static function getModelLabel(): string
    {
        return __('DVR Recording');
    }

    public static function getPluralModelLabel(): string
    {
        return __('DVR Recordings');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Recording Details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('subtitle'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('post_processing_step')
                        ->label(__('Current Step'))
                        ->hidden(fn ($record) => ! $record->post_processing_step),
                    TextEntry::make('channel.title')->label(__('Channel')),
                    TextEntry::make('dvrSetting.playlist.name')->label(__('Playlist')),
                    TextEntry::make('scheduled_start')->dateTime(),
                    TextEntry::make('scheduled_end')->dateTime(),
                    TextEntry::make('actual_start')->dateTime(),
                    TextEntry::make('actual_end')->dateTime(),
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
                    TextEntry::make('file_path')->columnSpanFull(),
                    TextEntry::make('error_message')
                        ->label(__('Error'))
                        ->columnSpanFull()
                        ->hidden(fn ($record) => ! $record->error_message),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->columnSpanFull()
                        ->hidden(fn ($record) => ! $record->description),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->defaultSort('scheduled_start', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (DvrRecording $record): string => $record->subtitle ?? ''),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->description(fn (DvrRecording $record): ?string => $record->post_processing_step),
                TextColumn::make('channel.title')
                    ->label(__('Channel'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('dvrSetting.playlist.name')
                    ->label(__('Playlist'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('scheduled_start')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('scheduled_end')
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
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DvrRecordingStatus::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('watch')
                        ->label(__('Watch'))
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->visible(fn (DvrRecording $record): bool => in_array($record->status, [
                            DvrRecordingStatus::Recording,
                            DvrRecordingStatus::Completed,
                        ]) && $record->dvrSetting?->playlist)
                        ->action(function (DvrRecording $record, $livewire): void {
                            $playlist = $record->dvrSetting->playlist;
                            $username = $record->user->name;
                            $format = $record->status === DvrRecordingStatus::Completed
                                ? ($record->dvrSetting->dvr_output_format ?? 'mp4')
                                : 'm3u8';

                            $url = route('dvr.recording.stream', [
                                'username' => $username,
                                'password' => $playlist->uuid,
                                'uuid' => $record->uuid,
                                'format' => $format,
                            ]);

                            $livewire->dispatch('openFloatingStream', [
                                'id' => $record->id,
                                'stream_id' => $record->id,
                                'content_type' => 'dvr_recording',
                                'playlist_id' => $playlist->id,
                                'title' => $record->display_title ?? $record->title,
                                'display_title' => $record->display_title ?? $record->title,
                                'url' => $url,
                                'format' => $format,
                                'type' => 'channel',
                            ]);
                        }),
                    Action::make('retry')
                        ->label(__('Retry Post-Processing'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (DvrRecording $record): bool => $record->status === DvrRecordingStatus::Failed)
                        ->requiresConfirmation()
                        ->action(function (DvrRecording $record): void {
                            $record->update(['status' => DvrRecordingStatus::PostProcessing, 'error_message' => null]);
                            PostProcessDvrRecording::dispatch($record);

                            Notification::make()
                                ->success()
                                ->title(__('Post-processing queued'))
                                ->send();
                        }),
                    Action::make('cancel')
                        ->label(__('Cancel'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (DvrRecording $record): bool => in_array(
                            $record->status,
                            [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]
                        ))
                        ->requiresConfirmation()
                        ->action(function (DvrRecording $record): void {
                            StopDvrRecording::dispatch($record->id);

                            Notification::make()
                                ->success()
                                ->title(__('Recording cancellation queued'))
                                ->send();
                        }),
                    DeleteAction::make()
                        ->modalDescription(__('Are you sure you want to delete this recording? The file on disk and any linked VOD entry will also be removed.')),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDvrRecordings::route('/'),
            'view' => Pages\ViewDvrRecording::route('/{record}'),
        ];
    }
}
