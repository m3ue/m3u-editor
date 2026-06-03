<?php

namespace App\Filament\Actions;

use App\Services\CronService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class CronHelperAction
{
    public static function make(
        string $name = 'cron-helper',
        string $cronField = '',
    ): Action {
        $hasField = filled($cronField);

        $action = Action::make($name)
            ->label(__('Helper'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->slideOver()
            ->modalWidth('xl')
            ->modalHeading(__('Cron Schedule Helper'))
            ->modalDescription(__('Build and preview your cron schedule before applying it.'))
            ->schema(function (Get $get) use ($cronField, $name): array {
                $initialExpression = $cronField ? (string) ($get($cronField) ?? '') : '';

                return [
                    TextInput::make('expression')
                        ->label(__('Cron Expression'))
                        ->default($initialExpression)
                        ->placeholder(__('0 */6 * * *'))
                        ->live(debounce: 500)
                        ->helperText(function (Get $get): string {
                            $expression = trim((string) ($get('expression') ?? ''));

                            return $expression && CronService::isValid($expression)
                                ? CronService::describe($expression)
                                : __('Format: minute  hour  day  month  weekday');
                        })
                        ->hintAction(
                            Action::make('view_cron_example')
                                ->label(__('CRON Example'))
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->iconPosition('after')
                                ->size('sm')
                                ->url('https://crontab.guru')
                                ->openUrlInNewTab(true)
                        )
                        ->columnSpanFull(),

                    Select::make('preset')
                        ->label(__('Common presets'))
                        ->placeholder(__('Select a preset to fill the expression…'))
                        ->options(CronService::presets())
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state) {
                                $set('expression', $state);
                            }
                        })
                        ->dehydrated(false)
                        ->columnSpanFull(),

                    Hidden::make('valid')->default(''),
                    Hidden::make('previewed')->default(false),

                    Actions::make([
                        Action::make('preview-'.$name)
                            ->label(__('Preview Schedule'))
                            ->icon('heroicon-o-calendar')
                            ->color('primary')
                            ->action(function (Get $get, Set $set): void {
                                $expression = trim((string) ($get('expression') ?? ''));
                                $valid = CronService::isValid($expression);
                                $set('valid', $valid ? 'true' : 'false');
                                $set('previewed', true);

                                if ($valid) {
                                    $runs = CronService::nextRuns($expression, 5);
                                    $set('preview_data', array_filter([
                                        __('Next run') => $runs[0] ?? null,
                                        '#2' => $runs[1] ?? null,
                                        '#3' => $runs[2] ?? null,
                                        '#4' => $runs[3] ?? null,
                                        '#5' => $runs[4] ?? null,
                                    ]));
                                }
                            }),
                    ]),

                    Section::make()
                        ->schema([
                            TextEntry::make('error_msg')
                                ->label(__('Invalid Expression'))
                                ->color('danger')
                                ->state(__('The cron expression is not valid. Please check the syntax and try again.')),
                        ])
                        ->visible(fn (Get $get): bool => $get('previewed') && $get('valid') === 'false'),

                    KeyValue::make('preview_data')
                        ->label(__('Preview'))
                        ->keyLabel(__('Run'))
                        ->valueLabel(__('Date & Time'))
                        ->addable(false)
                        ->deletable(false)
                        ->editableKeys(false)
                        ->editableValues(false)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('previewed') && $get('valid') === 'true'),
                ];
            });

        if ($hasField) {
            $action
                ->modalSubmitActionLabel(__('Apply & Close'))
                ->action(function (array $data, Set $set) use ($cronField): void {
                    $set($cronField, $data['expression'] ?? '');
                });
        } else {
            $action
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close'));
        }

        return $action;
    }
}
