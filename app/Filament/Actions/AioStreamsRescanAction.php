<?php

namespace App\Filament\Actions;

use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\AioStreamsCandidateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;

/**
 * Per-item "Rescan for stream candidates" for an AIOStreams-backed movie
 * (Channel) or Episode. Automatic re-runs the auto-matcher (and drops any
 * earlier manual pick); Manual fetches the current streams so the user can
 * choose which to use and drag them into failover order.
 */
final class AioStreamsRescanAction
{
    public static function make(): Action
    {
        return Action::make('rescan')
            ->tooltip(__('Rescan for stream candidates'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->button()
            ->hiddenLabel()
            ->size('sm')
            ->modalHeading(__('Rescan for stream candidates'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('Rescan'))
            ->fillForm([
                'mode' => 'automatic',
                'candidates' => [],
            ])
            // A wizard rather than a single form: the stream fetch can take many
            // seconds, and running it from a live field update left the modal's
            // submit button clickable meanwhile - a click (or Enter) queued
            // behind the fetch and saved the pre-selected streams unseen. Here
            // the fetch runs on "Next" (which shows a spinner and goes inert
            // while loading) and the submit button only exists on the last step.
            // In Automatic mode the picker step is hidden, so step one is the
            // last step and submits straight away as before.
            ->steps([
                Step::make('mode')
                    ->label(__('Stream selection'))
                    ->schema([
                        ToggleButtons::make('mode')
                            ->hiddenLabel()
                            ->grouped()
                            ->options([
                                'automatic' => __('Automatic'),
                                'manual' => __('Manual'),
                            ])
                            ->icons([
                                'automatic' => 'heroicon-o-sparkles',
                                'manual' => 'heroicon-o-hand-raised',
                            ])
                            ->helperText(fn (Get $get): string => $get('mode') === 'manual'
                                ? __('Choose the streams to use and drag them into order. The first is played, the rest are failovers. Your picks are kept when links are refreshed.')
                                : __('Let the auto-matcher pick the best streams. Replaces any manually chosen streams.'))
                            ->inline()
                            ->required()
                            ->live(),
                    ])
                    ->afterValidation(function (Get $get, Set $set, Channel|Episode $record): void {
                        if ($get('mode') !== 'manual' || ! empty($get('candidates'))) {
                            return;
                        }

                        try {
                            $rows = app(AioStreamsCandidateService::class)->loadManualCandidates($record);
                        } catch (\Throwable $e) {
                            Log::warning('AioStreamsRescanAction: fetching streams failed', [
                                'record' => $record::class.':'.$record->id,
                                'exception' => $e->getMessage(),
                            ]);
                            $rows = [];
                        }

                        if ($rows === []) {
                            Notification::make()->danger()->title(__('No streams found for this title right now'))->send();

                            throw new Halt;
                        }

                        $set('candidates', $rows);
                    }),
                Step::make('streams')
                    ->label(__('Pick streams'))
                    ->visible(fn (Get $get): bool => $get('mode') === 'manual')
                    ->schema([
                        self::candidatesRepeater(),
                    ]),
            ])
            ->action(function (Channel|Episode $record, array $data, Action $action): void {
                if (($data['mode'] ?? 'automatic') === 'manual') {
                    self::applyManual($record, $data['candidates'] ?? [], $action);

                    return;
                }

                self::rescanAutomatically($record);
            });
    }

    private static function candidatesRepeater(): Repeater
    {
        return Repeater::make('candidates')
            ->hiddenLabel()
            ->schema([
                Hidden::make('key'),
                Hidden::make('name')->dehydrated(false),
                Hidden::make('details')->dehydrated(false),
                Toggle::make('selected')
                    ->label(__('Use this stream')),
                Text::make(fn (Get $get) => AioStreamsCandidateService::detailsHtml($get('details'))),
            ])
            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
            ->addable(false)
            ->deletable(false)
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->reorderableWithButtons()
            ->columnSpanFull();
    }

    /**
     * @param  array<array-key, array{key?: string, selected?: bool}>  $rows  In the user's dragged order.
     */
    private static function applyManual(Channel|Episode $record, array $rows, Action $action): void
    {
        $orderedKeys = collect($rows)
            ->filter(fn (array $row) => ! empty($row['selected']) && ! empty($row['key']))
            ->pluck('key')
            ->values()
            ->all();

        if ($orderedKeys === []) {
            Notification::make()->warning()->title(__('Select at least one stream'))->send();
            $action->halt();
        }

        $stored = app(AioStreamsCandidateService::class)->applyManualSelection($record, $orderedKeys);

        if ($stored === 0) {
            Notification::make()->danger()->title(__('The stream list expired, please rescan again'))->send();

            return;
        }

        Notification::make()->success()->title(__(':count stream(s) saved', ['count' => $stored]))->send();
    }

    private static function rescanAutomatically(Channel|Episode $record): void
    {
        app(AioStreamsCandidateService::class)->clearManualSelection($record);

        $record->update(['aio_resolution_status' => 'pending']);

        if ($record instanceof Channel) {
            ResolveAioStreamsChannel::dispatch($record->id);
            NotifyAioStreamsResolutionComplete::dispatch([$record->id], [], $record->user_id, $record->title_custom ?? $record->title ?? $record->name)
                ->delay(now()->addSeconds(15));
        } else {
            ResolveAioStreamsEpisode::dispatch($record->id);
            NotifyAioStreamsResolutionComplete::dispatch([], [$record->id], $record->user_id, $record->title)
                ->delay(now()->addSeconds(15));
        }

        Notification::make()->success()->title(__('Rescanning stream, check back shortly'))->send();
    }
}
