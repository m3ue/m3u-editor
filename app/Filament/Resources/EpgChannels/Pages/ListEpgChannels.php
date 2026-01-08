<?php

namespace App\Filament\Resources\EpgChannels\Pages;

use App\Filament\Resources\EpgChannels\EpgChannelResource;
use App\Jobs\EpgChannelFindAndReplace;
use App\Jobs\EpgChannelFindAndReplaceReset;
use App\Models\Epg;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

class ListEpgChannels extends ListRecords
{
    protected static string $resource = EpgChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('find-replace')
                    ->label('Find & Replace')
                    ->schema([
                        Toggle::make('all_epgs')
                            ->label('All EPGs')
                            ->live()
                            ->helperText('Apply find and replace to all EPGs? If disabled, it will only apply to the selected EPG.')
                            ->default(true),
                        Select::make('epg')
                            ->label('EPG')
                            ->required()
                            ->helperText('Select the EPG you would like to apply changes to.')
                            ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_epgs') === true)
                            ->searchable(),
                        Toggle::make('use_regex')
                            ->label('Use Regex')
                            ->live()
                            ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                            ->default(true),
                        Select::make('column')
                            ->label('Column to modify')
                            ->options([
                                'icon' => 'Channel Icon',
                                'name' => 'Channel Name',
                                'display_name' => 'Display Name',
                            ])
                            ->default('icon')
                            ->required()
                            ->columnSpan(1),
                        TextInput::make('find_replace')
                            ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
                            ->required()
                            ->placeholder(
                                fn (Get $get) => $get('use_regex')
                                    ? '^(US- |UK- |CA- )'
                                    : 'US -'
                            )->helperText(
                                fn (Get $get) => ! $get('use_regex')
                                    ? 'This is the string you want to find and replace.'
                                    : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                            ),
                        TextInput::make('replace_with')
                            ->label('Replace with (optional)')
                            ->placeholder('Leave empty to remove'),

                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new EpgChannelFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_epgs: $data['all_epgs'] ?? false,
                                epg_id: $data['epg'] ?? null,
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? ''
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace started')
                            ->body('Find & Replace working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Select what you would like to find and replace in your channels list.')
                    ->modalSubmitActionLabel('Replace now'),

                Action::make('find-replace-reset')
                    ->label('Undo Find & Replace')
                    ->schema([
                        Toggle::make('all_epgs')
                            ->label('All EPGs')
                            ->live()
                            ->helperText('Apply reset to all EPGs? If disabled, it will only apply to the selected EPG.')
                            ->default(false),
                        Select::make('epg')
                            ->required()
                            ->label('EPG')
                            ->helperText('Select the EPG you would like to apply the reset to.')
                            ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_epgs') === true)
                            ->searchable(),
                        Select::make('column')
                            ->label('Column to reset')
                            ->options([
                                'icon' => 'Channel Icon',
                                'name' => 'Channel Name',
                                'display_name' => 'Display Name',
                            ])
                            ->default('icon')
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new EpgChannelFindAndReplaceReset(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_epgs: $data['all_epgs'] ?? false,
                                epg_id: $data['epg'] ?? null,
                                column: $data['column'] ?? 'title',
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace reset started')
                            ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset Find & Replace results back to EPG defaults. This will remove any custom values set in the selected column.')
                    ->modalSubmitActionLabel('Reset now'),
            ])->button()->label('Actions'),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
