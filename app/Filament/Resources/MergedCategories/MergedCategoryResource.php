<?php

namespace App\Filament\Resources\MergedCategories;

use App\Filament\Resources\MergedCategories\Pages\ListMergedCategories;
use App\Filament\Tables\MergedCategoryChildrenTable;
use App\Models\Category;
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

class MergedCategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Series');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function getModelLabel(): string
    {
        return __('Merged Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Merged Categories');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('categories.user_id', auth()->id())
            ->where('categories.is_merged', true);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('categories.user_id', auth()->id())
            ->where('categories.is_merged', true);
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
                ->helperText(__('Merged categories only combine categories from a single playlist.')),
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255)
                ->helperText(__('The category clients see for every series in the merged categories.')),
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
                ->withCount(['children', 'descendantSeries']))
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
                    ->label(__('Merged categories'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('descendant_series_count')
                    ->label(__('Series'))
                    ->badge()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->size('sm')->hiddenLabel()
                    ->modalDescription(__('The merged categories are released back to their own names; no series are affected.')),
                EditAction::make()
                    ->button()->size('sm')->hiddenLabel()
                    ->slideOver(),
                Action::make('manageChildren')
                    ->label(__('Manage categories'))
                    ->icon('heroicon-o-squares-plus')
                    ->button()->size('sm')
                    ->slideOver()
                    ->fillForm(fn (Category $record): array => [
                        'children' => $record->children()->pluck('id')->all(),
                    ])
                    ->schema([
                        ModalTableSelect::make('children')
                            ->label(__('Categories to merge'))
                            ->tableConfiguration(MergedCategoryChildrenTable::class)
                            ->multiple()
                            ->tableArguments(fn (Category $record): array => [
                                'playlist_id' => $record->playlist_id,
                                'merged_category_id' => $record->id,
                            ])
                            ->getOptionLabelsUsing(fn (array $values): array => Category::whereIn('id', $values)->pluck('name', 'id')->all())
                            ->selectAction(fn (Action $action) => $action
                                ->label(__('Select categories'))
                                ->modalHeading(__('Search categories'))
                                ->button()),
                    ])
                    ->action(function (Category $record, array $data): void {
                        $count = MergedGroupService::syncCategoryChildren($record, $data['children'] ?? []);

                        Notification::make()
                            ->success()
                            ->title(__('Merged category updated'))
                            ->body(trans_choice(':count category merged into :name|:count categories merged into :name', $count, [
                                'count' => $count,
                                'name' => $record->name,
                            ]))
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMergedCategories::route('/'),
        ];
    }
}
