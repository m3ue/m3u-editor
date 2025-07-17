<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $label = 'Group';
    protected static ?string $pluralLabel = 'Groups';

    protected static ?string $title = 'Groups';
    protected static ?string $navigationLabel = 'Groups';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name.en')
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
            ->modifyQueryUsing(function (Builder $query) use ($ownerRecord) {
                $query->where('type', $ownerRecord->uuid);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextInputColumn::make('name')
                    ->sortable(),
            ])
            ->reorderable('order_column')
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
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
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->button()->hiddenLabel(true),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
