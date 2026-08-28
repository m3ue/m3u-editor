<?php

namespace App\Filament\Resources\MergedGroups;

use App\Filament\Resources\MergedGroups\Pages\ListMergedGroups;
use App\Filament\Tables\MergedGroupChildrenTable;
use App\Models\Group;
use App\Services\MergedGroupService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MergedGroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Live and VOD merged groups share the Group model; scope every query to this slice. */
    protected static string $groupType = 'live';

    public static function getNavigationGroup(): ?string
    {
        return __('Live Channels');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return __('Merged Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Merged Groups');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('groups.user_id', auth()->id())
            ->where('groups.is_merged', true)
            ->where('groups.type', static::$groupType);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('groups.user_id', auth()->id())
            ->where('groups.is_merged', true)
            ->where('groups.type', static::$groupType);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('playlist_id')
                ->label(__('Playlist'))
                ->relationship('playlist', 'name', fn (Builder $query) => $query->where('user_id', auth()->id()))
                ->required()
                ->searchable()
                ->preload()
                ->disabledOn('edit')
                ->helperText(__('A merged group can only combine groups from a single playlist.')),
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255)
                ->helperText(__('The group-title clients see for every channel in the merged groups.')),
            TextInput::make('sort_order')
                ->label(__('Sort Order'))
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('playlist')
                ->withCount(['children', 'descendantChannels']))
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->columns([
                TextInputColumn::make('name')
                    ->label(__('Name'))
                    ->rules(['min:1', 'max:255'])
                    ->searchable()
                    ->sortable(),
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->sortable(),
                TextColumn::make('children_count')
                    ->label(__('Merged groups'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('descendant_channels_count')
                    ->label(__('Channels'))
                    ->badge()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->size('sm')->hiddenLabel()
                    ->using(fn (Group $record) => $record->forceDelete())
                    ->modalDescription(__('The merged groups are released back to their own names; no channels are affected.')),
                EditAction::make()
                    ->button()->size('sm')->hiddenLabel()
                    ->slideOver(),
                Action::make('manageChildren')
                    ->label(__('Manage groups'))
                    ->icon('heroicon-o-squares-plus')
                    ->button()->size('sm')
                    ->slideOver()
                    ->fillForm(fn (Group $record): array => [
                        'children' => $record->children()->pluck('id')->all(),
                    ])
                    ->schema([
                        ModalTableSelect::make('children')
                            ->label(__('Groups to merge'))
                            ->tableConfiguration(MergedGroupChildrenTable::class)
                            ->multiple()
                            ->tableArguments(fn (Group $record): array => [
                                'playlist_id' => $record->playlist_id,
                                'type' => $record->type,
                                'merged_group_id' => $record->id,
                            ])
                            ->getOptionLabelsUsing(fn (array $values): array => Group::whereIn('id', $values)->pluck('name', 'id')->all())
                            ->selectAction(fn (Action $action) => $action
                                ->label(__('Select groups'))
                                ->modalHeading(__('Search groups'))
                                ->button()),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $count = MergedGroupService::syncGroupChildren($record, $data['children'] ?? []);

                        Notification::make()
                            ->success()
                            ->title(__('Merged group updated'))
                            ->body(trans_choice(':count group merged into :name|:count groups merged into :name', $count, [
                                'count' => $count,
                                'name' => $record->name,
                            ]))
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->using(fn ($records) => $records->each->forceDelete()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMergedGroups::route('/'),
        ];
    }
}
