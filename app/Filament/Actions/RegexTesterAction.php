<?php

namespace App\Filament\Actions;

use App\Services\RegexTesterService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

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

                    Hidden::make('results_json')->default(''),
                    Hidden::make('tested')->default(false),

                    Actions::make([
                        Action::make('run-test-'.$name)
                            ->label(__('Run Test'))
                            ->icon('heroicon-o-play')
                            ->color('primary')
                            ->action(function (Get $get, Set $set) use ($flags): void {
                                $results = RegexTesterService::test(
                                    (string) ($get('pattern') ?? ''),
                                    $flags,
                                    (string) ($get('replacement') ?? ''),
                                    RegexTesterService::normalizeSamples((string) ($get('samples') ?? '')),
                                );
                                $set('results_json', json_encode($results));
                                $set('tested', true);
                            }),
                    ]),

                    Placeholder::make('results_display')
                        ->label('')
                        ->content(function (Get $get): HtmlString {
                            $json = $get('results_json');
                            if (! $json) {
                                return new HtmlString('');
                            }
                            $results = json_decode($json, true) ?? [];

                            return RegexTesterService::renderResults($results, true);
                        })
                        ->visible(fn (Get $get): bool => (bool) $get('tested'))
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
