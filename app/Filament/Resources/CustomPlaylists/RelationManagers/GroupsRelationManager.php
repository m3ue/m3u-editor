<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $label = 'Group';

    protected static ?string $pluralLabel = 'Groups';

    protected static ?string $title = 'Groups';

    protected static ?string $navigationLabel = 'Groups';

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make('Groups')
            ->badge($ownerRecord->groups()->count());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name.en')
                    ->label('Name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;

        return $table
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label('Sort');
            })
            ->modifyQueryUsing(function (Builder $query) use ($ownerRecord) {
                $query->where('type', $ownerRecord->uuid);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextInputColumn::make('name')
                    ->sortable(),
            ])
            ->reorderable('order_column')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data, string $model) use ($ownerRecord): Model {
                        $data['type'] = $ownerRecord->uuid;
                        $tag = $model::create($data);
                        $ownerRecord->attachTag($tag);

                        return $tag;
                    })
                    ->modalWidth('md')
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Group created')
                            ->body('You can now assign channels to this group from the Channels tab.'),
                    ),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->hiddenLabel(true),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
