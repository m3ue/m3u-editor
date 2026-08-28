<?php

namespace App\Filament\Widgets;

use App\Enums\DvrRecordingStatus;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Models\DvrRecording;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UpcomingRecordingsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->canUseDvr();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Upcoming recordings'))
            ->query(
                DvrRecording::query()
                    ->where('user_id', auth()->id())
                    ->upcoming()
                    ->with('channel:id,title,name')
                    ->orderBy('scheduled_start')
            )
            ->headerActions([
                Action::make('manage')
                    ->label(__('All recordings'))
                    ->url(DvrRecordingResource::getUrl())
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->button(),
            ])
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->description(fn (DvrRecording $record): ?string => $record->subtitle)
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('channel.title')
                    ->label(__('Channel'))
                    ->state(fn (DvrRecording $record): string => $record->channel?->title ?? $record->channel?->name ?? __('N/A'))
                    ->limit(30),

                TextColumn::make('scheduled_start')
                    ->label(__('Starts'))
                    ->dateTime()
                    ->description(fn (DvrRecording $record): ?string => $record->scheduled_start?->diffForHumans())
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', $state instanceof DvrRecordingStatus ? $state->value : (string) $state)))
                    ->color(fn ($state): string => match ($state instanceof DvrRecordingStatus ? $state : DvrRecordingStatus::tryFrom((string) $state)) {
                        DvrRecordingStatus::Recording => 'warning',
                        DvrRecordingStatus::Scheduled => 'info',
                        default => 'gray',
                    }),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('No upcoming recordings'))
            ->emptyStateDescription(__('Scheduled and in-progress recordings will appear here.'))
            ->emptyStateIcon('heroicon-o-video-camera');
    }
}
