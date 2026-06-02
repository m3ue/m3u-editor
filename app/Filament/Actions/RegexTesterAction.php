<?php

namespace App\Filament\Actions;

use App\Services\RegexTesterService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class RegexTesterAction
{
    public static function make(
        string $name = 'test-regex',
        string $flags = 'ui',
        string|Closure $samplesContext = '',
        string $patternField = '',
        string $replacementField = '',
    ): Action {
        $hasFields = filled($patternField);

        $action = Action::make($name)
            ->label(__('Test'))
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->slideOver()
            ->modalWidth('2xl')
            ->modalHeading(__('Regex Tester'))
            ->modalDescription(__('Enter a pattern and sample values to preview matches and replacements before applying.'))
            ->schema(function (Get $get) use ($flags, $samplesContext, $patternField, $replacementField, $name): array {
                $resolvedContext = $samplesContext instanceof Closure
                    ? $samplesContext($get)
                    : $samplesContext;

                $initialPattern = $patternField ? (string) ($get($patternField) ?? '') : '';
                $initialReplacement = $replacementField ? (string) ($get($replacementField) ?? '') : '';

                return [
                    TextInput::make('pattern')
                        ->label(__('Regex Pattern'))
                        ->default($initialPattern)
                        ->placeholder('e.g. ^(US|UK|CA):\s*')
                        ->helperText(__("Do not include delimiters (e.g. write pattern, not /pattern/). Flags used: {$flags}."))
                        ->columnSpanFull(),

                    TextInput::make('replacement')
                        ->label(__('Replace with'))
                        ->default($initialReplacement)
                        ->placeholder(__('Leave empty to test match-only'))
                        ->columnSpanFull(),

                    Textarea::make('samples')
                        ->label(__('Sample data'))
                        ->placeholder("Paste sample values here, one per line\ne.g.\nUS: BBC One HD\nUK: Sky News\nSport FHD")
                        ->rows(6)
                        ->hintAction(
                            Action::make('load-samples-'.$name)
                                ->label(__('Load samples'))
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->visible(fn (): bool => filled($resolvedContext))
                                ->action(function (Set $set) use ($resolvedContext): void {
                                    $samples = RegexTesterService::fetchSamplesForContext($resolvedContext, auth()->id());
                                    $set('samples', implode("\n", $samples->toArray()));
                                })
                        )
                        ->columnSpanFull(),

                    Hidden::make('tested')->default(false),
                    Hidden::make('match_count')->default(''),
                    Hidden::make('regex_error')->default(''),

                    Actions::make([
                        Action::make('run-test-'.$name)
                            ->label(__('Run Test'))
                            ->icon('heroicon-o-play')
                            ->color('primary')
                            ->action(function (Get $get, Set $set) use ($flags): void {
                                $raw = RegexTesterService::test(
                                    (string) ($get('pattern') ?? ''),
                                    $flags,
                                    (string) ($get('replacement') ?? ''),
                                    RegexTesterService::normalizeSamples((string) ($get('samples') ?? '')),
                                );

                                if (! empty($raw) && ! empty($raw[0]['error'])) {
                                    $set('regex_error', (string) $raw[0]['error']);
                                    $set('match_count', '');
                                    $set('results', []);
                                } else {
                                    $matchCount = count(array_filter($raw, fn ($r) => $r['matches']));
                                    $total = count($raw);

                                    $set('regex_error', '');
                                    $set('match_count', __("{$matchCount} of {$total} samples matched"));
                                    $set('results', array_map(fn (array $row) => [
                                        'input' => $row['input'],
                                        'matched' => $row['matches'] ? __('Match') : __('No match'),
                                        'output' => $row['output'],
                                    ], $raw));
                                }

                                $set('tested', true);
                            }),
                    ]),

                    // Error state
                    Section::make()
                        ->schema([
                            TextEntry::make('regex_error_display')
                                ->label(__('Invalid Pattern'))
                                ->color('danger')
                                ->state(fn (Get $get): string => (string) ($get('regex_error') ?? '')),
                        ])
                        ->visible(fn (Get $get): bool => (bool) $get('tested') && filled($get('regex_error'))),

                    // Results
                    TextEntry::make('match_count_display')
                        ->label('')
                        ->state(fn (Get $get): string => (string) ($get('match_count') ?? ''))
                        ->visible(fn (Get $get): bool => filled($get('match_count')))
                        ->columnSpanFull(),

                    Repeater::make('results')
                        ->label('')
                        ->table([
                            TableColumn::make(__('Input'))->width('40%'),
                            TableColumn::make(__('Status'))->width('20%'),
                            TableColumn::make(__('Output'))->width('40%'),
                        ])
                        ->schema([
                            TextInput::make('input')
                                ->hiddenLabel()
                                ->disabled(),
                            TextEntry::make('matched')
                                ->hiddenLabel()
                                ->badge()
                                ->color(fn (string $state): string => $state === __('Match') ? 'success' : 'gray'),
                            TextInput::make('output')
                                ->hiddenLabel()
                                ->disabled(),
                        ])
                        ->default([])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->visible(fn (Get $get): bool => (bool) $get('tested') && filled($get('match_count')))
                        ->columnSpanFull(),
                ];
            });

        if ($hasFields) {
            $action
                ->modalSubmitActionLabel(__('Apply & Close'))
                ->action(function (array $data, Set $set) use ($patternField, $replacementField): void {
                    $set($patternField, $data['pattern'] ?? '');
                    if (filled($replacementField)) {
                        $set($replacementField, $data['replacement'] ?? '');
                    }
                });
        } else {
            $action
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close'));
        }

        return $action;
    }
}
