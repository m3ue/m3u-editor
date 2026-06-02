<?php

namespace App\Services;

use App\Filament\Actions\RegexTesterAction;
use App\Models\Playlist;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Service to build shared Find & Replace form schemas and load saved patterns.
 */
class FindReplaceService
{
    /**
     * Load saved find/replace patterns from playlists for the given target.
     *
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    public static function getSavedPatterns(string $target): array
    {
        $patterns = [];
        $rules = [];
        $counter = 0;

        foreach (Playlist::where('user_id', auth()->id())->get() as $playlist) {
            foreach ($playlist->find_replace_rules ?? [] as $rule) {
                if (is_array($rule) && ($rule['target'] ?? 'channels') === $target) {
                    $patterns[$counter] = "{$playlist->name} - ".($rule['name'] ?? 'Unnamed');
                    $rules[$counter] = $rule;
                    $counter++;
                }
            }
        }

        return [$patterns, $rules];
    }

    /**
     * Build the find/replace schema used by bulk actions (no playlist selector).
     *
     * @return array<int, mixed>
     */
    public static function getBulkActionSchema(string $target): array
    {
        [$savedPatterns, $savedPatternRules] = self::getSavedPatterns($target);

        return [
            Select::make('saved_pattern')
                ->label('Load saved pattern')
                ->searchable()
                ->placeholder('Select a saved pattern...')
                ->options($savedPatterns)
                ->hidden(empty($savedPatterns))
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) use ($savedPatternRules): void {
                    if ($state === null || $state === '') {
                        return;
                    }
                    $rule = $savedPatternRules[(int) $state] ?? null;
                    if (! $rule) {
                        return;
                    }
                    $set('use_regex', $rule['use_regex'] ?? true);
                    $set('find_replace', $rule['find_replace'] ?? '');
                    $set('replace_with', $rule['replace_with'] ?? '');
                })
                ->dehydrated(false),
            Toggle::make('use_regex')
                ->label('Use Regex')
                ->live()
                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                ->default(true),
            TextInput::make('find_replace')
                ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
                ->required()
                ->placeholder(
                    fn (Get $get) => $get('use_regex') ? '^(US- |UK- |CA- )' : 'US -'
                )
                ->helperText(
                    fn (Get $get) => ! $get('use_regex')
                        ? 'This is the string you want to find and replace.'
                        : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                )
                ->suffixAction(
                    RegexTesterAction::make(samplesContext: $target, patternField: 'find_replace', replacementField: 'replace_with')
                        ->visible(fn (Get $get): bool => (bool) $get('use_regex'))
                ),
            TextInput::make('replace_with')
                ->label('Replace with (optional)')
                ->placeholder('Leave empty to remove'),
        ];
    }

    /**
     * Build the find/replace schema for header actions (includes playlist selector).
     * Pass $columnOptions to include a column selector (e.g. for channels/series).
     *
     * @param  array<string, string>  $columnOptions
     * @return array<int, mixed>
     */
    public static function getHeaderActionSchema(string $target, array $columnOptions = []): array
    {
        [$savedPatterns, $savedPatternRules] = self::getSavedPatterns($target);

        $afterStateUpdated = function (?string $state, Set $set) use ($savedPatternRules, $columnOptions): void {
            if ($state === null || $state === '') {
                return;
            }
            $rule = $savedPatternRules[(int) $state] ?? null;
            if (! $rule) {
                return;
            }
            $set('use_regex', $rule['use_regex'] ?? true);
            if (! empty($columnOptions)) {
                $set('column', $rule['column'] ?? array_key_first($columnOptions));
            }
            $set('find_replace', $rule['find_replace'] ?? '');
            $set('replace_with', $rule['replace_with'] ?? '');
        };

        $schema = [
            Select::make('saved_pattern')
                ->label('Load saved pattern')
                ->searchable()
                ->placeholder('Select a saved pattern...')
                ->options($savedPatterns)
                ->hidden(empty($savedPatterns))
                ->live()
                ->afterStateUpdated($afterStateUpdated)
                ->dehydrated(false),
            Toggle::make('all_playlists')
                ->label('All Playlists')
                ->live()
                ->helperText('Apply find and replace to all playlists? If disabled, it will only apply to the selected playlist.')
                ->default(true),
            Select::make('playlist')
                ->label('Playlist')
                ->required()
                ->helperText('Select the playlist you would like to apply changes to.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(fn (Get $get) => $get('all_playlists') === true)
                ->searchable(),
            Toggle::make('use_regex')
                ->label('Use Regex')
                ->live()
                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                ->default(true),
        ];

        if (! empty($columnOptions)) {
            $schema[] = Select::make('column')
                ->label('Column to modify')
                ->options($columnOptions)
                ->default(array_key_first($columnOptions))
                ->required()
                ->columnSpan(1);
        }

        $schema[] = TextInput::make('find_replace')
            ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
            ->required()
            ->placeholder(
                fn (Get $get) => $get('use_regex') ? '^(US- |UK- |CA- )' : 'US -'
            )
            ->helperText(
                fn (Get $get) => ! $get('use_regex')
                    ? 'This is the string you want to find and replace.'
                    : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
            )
            ->suffixAction(
                RegexTesterAction::make(samplesContext: $target, patternField: 'find_replace', replacementField: 'replace_with')
                    ->visible(fn (Get $get): bool => (bool) $get('use_regex'))
            );

        $schema[] = TextInput::make('replace_with')
            ->label('Replace with (optional)')
            ->placeholder('Leave empty to remove');

        return $schema;
    }

    /**
     * Build the reset schema for header actions (includes playlist selector).
     * Pass $columnOptions to include a column selector.
     *
     * @param  array<string, string>  $columnOptions
     * @return array<int, mixed>
     */
    public static function getHeaderResetSchema(array $columnOptions = []): array
    {
        $schema = [
            Toggle::make('all_playlists')
                ->label('All Playlists')
                ->live()
                ->helperText('Apply reset to all playlists? If disabled, it will only apply to the selected playlist.')
                ->default(false),
            Select::make('playlist')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist you would like to apply the reset to.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(fn (Get $get) => $get('all_playlists') === true)
                ->searchable(),
        ];

        if (! empty($columnOptions)) {
            $schema[] = Select::make('column')
                ->label('Column to reset')
                ->options($columnOptions)
                ->default(array_key_first($columnOptions))
                ->required()
                ->columnSpan(1);
        }

        return $schema;
    }
}
