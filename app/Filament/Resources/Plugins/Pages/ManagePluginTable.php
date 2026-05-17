<?php

namespace App\Filament\Resources\Plugins\Pages;

use App\Filament\Resources\Plugins\PluginResource;
use App\Models\Plugin;
use App\Models\PluginTableRecord;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiTableRegistry;
use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ManagePluginTable extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = PluginResource::class;

    protected string $view = 'filament.resources.extension-plugins.pages.manage-plugin-table';

    public string $tableId;

    /**
     * @var array<string, mixed>
     */
    public array $tableDefinition = [];

    public function mount(int|string $record, string $table): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();
        abort_unless(auth()->user()?->canManagePlugins(), 403);

        /** @var Plugin $plugin */
        $plugin = $this->getRecord();
        $definition = app(PluginUiTableRegistry::class)->tableFor($plugin, $table);

        abort_unless($definition !== null, 404);
        abort_unless(Schema::hasTable((string) $definition['table']), 404);

        $this->tableId = $table;
        $this->tableDefinition = $definition;

        app(PluginUiTableRegistry::class)->prefillRows($plugin, $definition);
    }

    public function getTitle(): string
    {
        return (string) ($this->tableDefinition['label'] ?? Str::headline($this->tableId));
    }

    public function getSubheading(): ?string
    {
        return $this->tableDefinition['description'] ?? null;
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => $this->tableQuery())
            ->columns($this->tableColumns())
            ->headerActions($this->tableHeaderActions())
            ->recordActions($this->tableRecordActions(), position: RecordActionsPosition::BeforeCells);

        if (Schema::hasColumn($this->tableName(), 'updated_at')) {
            $table->defaultSort('updated_at', 'desc');
        } elseif (Schema::hasColumn($this->tableName(), 'id')) {
            $table->defaultSort('id', 'desc');
        }

        return $table;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_plugin')
                ->label(__('Back to Plugin'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => PluginResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    private function tableQuery(): Builder
    {
        $tableName = $this->tableName();

        return $this->newModel()
            ->newQuery()
            ->when(Schema::hasColumn($tableName, 'extension_plugin_id'), fn (Builder $query) => $query->where('extension_plugin_id', $this->getRecord()->id));
    }

    /**
     * @return array<int, TextColumn|IconColumn|ToggleColumn|SelectColumn>
     */
    private function tableColumns(): array
    {
        return collect($this->tableDefinition['columns'] ?? [])
            ->filter(fn (array $column): bool => filled($column['name'] ?? null))
            ->map(function (array $column): TextColumn|IconColumn|ToggleColumn|SelectColumn {
                $name = (string) $column['name'];
                $label = (string) ($column['label'] ?? Str::headline($name));

                if ((bool) ($column['editable'] ?? false)) {
                    return $this->editableColumn($column, $label);
                }

                if (($column['type'] ?? null) === 'boolean') {
                    return IconColumn::make($name)
                        ->label($label)
                        ->boolean()
                        ->state(fn (PluginTableRecord $record): bool => (bool) $this->columnState($record, $column));
                }

                $textColumn = TextColumn::make($name)
                    ->label($label)
                    ->state(fn (PluginTableRecord $record): mixed => $this->columnState($record, $column))
                    ->limit((int) ($column['limit'] ?? 80));

                if (($column['type'] ?? null) === 'datetime') {
                    $textColumn->formatStateUsing(fn ($state): string => $state ? app(DateFormatService::class)->format($state) : '-');
                }

                if ((bool) ($column['searchable'] ?? false) && ! str_contains($name, '.') && empty($column['lookup'])) {
                    $textColumn->searchable();
                }

                if ((bool) ($column['sortable'] ?? false) && ! str_contains($name, '.') && empty($column['lookup'])) {
                    $textColumn->sortable();
                }

                return $textColumn;
            })
            ->values()
            ->all();
    }

    private function editableColumn(array $column, string $label): ToggleColumn|SelectColumn
    {
        $name = (string) $column['name'];

        if (($column['type'] ?? null) === 'boolean') {
            return ToggleColumn::make($name)
                ->label($label)
                ->state(fn (PluginTableRecord $record): bool => (bool) $this->columnState($record, $column));
        }

        $selectColumn = SelectColumn::make($name)
            ->label($label)
            ->options(fn (): array => $this->columnOptions($column))
            ->state(fn (PluginTableRecord $record): mixed => data_get($record->toArray(), $name))
            ->rules([(bool) ($column['required'] ?? false) ? 'required' : 'nullable']);

        return $selectColumn;
    }

    private function columnState(PluginTableRecord $record, array $column): mixed
    {
        if (! empty($column['lookup']) && is_array($column['lookup'])) {
            $value = data_get($record->toArray(), (string) ($column['lookup']['source_column'] ?? $column['name']));

            return app(PluginUiTableRegistry::class)->lookupLabel($this->getRecord(), $column['lookup'], $value);
        }

        $value = data_get($record->toArray(), (string) $column['name']);

        if (is_scalar($value) && is_array($column['options'] ?? null)) {
            return $column['options'][(string) $value] ?? $value;
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;
    }

    /**
     * @return array<string, string>
     */
    private function columnOptions(array $column): array
    {
        if (is_array($column['options'] ?? null)) {
            return $column['options'];
        }

        return is_array($column['lookup'] ?? null)
            ? app(PluginUiTableRegistry::class)->lookupOptions($this->getRecord(), $column['lookup'])
            : [];
    }

    /**
     * @return array<int, Action>
     */
    private function tableHeaderActions(): array
    {
        if (($this->tableDefinition['create'] ?? true) === false) {
            return [];
        }

        return [
            CreateAction::make()
                ->model(PluginTableRecord::class)
                ->label(__('New :model', ['model' => $this->modelLabel()]))
                ->schema(fn (): array => $this->formComponents())
                ->using(fn (array $data): Model => $this->newModel()->newQuery()->create($this->payloadForSave($data, creating: true))),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function tableRecordActions(): array
    {
        $actions = [];

        if (($this->tableDefinition['edit'] ?? true) !== false) {
            $actions[] = EditAction::make()
                ->schema(fn (PluginTableRecord $record): array => $this->formComponents($record))
                ->using(function (PluginTableRecord $record, array $data): PluginTableRecord {
                    $record->update($this->payloadForSave($data));

                    return $record;
                });
        }

        if (($this->tableDefinition['delete'] ?? true) !== false) {
            $actions[] = DeleteAction::make();
        }

        return $actions;
    }

    /**
     * @return array<int, mixed>
     */
    private function formComponents(?PluginTableRecord $record = null): array
    {
        return app(PluginSchemaMapper::class)->componentsForFieldDefinitions(
            $this->tableDefinition['fields'] ?? [],
            existing: $record?->toArray() ?? [],
            plugin: $this->getRecord(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payloadForSave(array $data, bool $creating = false): array
    {
        $tableName = $this->tableName();

        if (Schema::hasColumn($tableName, 'extension_plugin_id')) {
            $data['extension_plugin_id'] = $this->getRecord()->id;
        }

        if ($creating && Schema::hasColumn($tableName, 'user_id') && blank($data['user_id'] ?? null)) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    private function newModel(): PluginTableRecord
    {
        return app(PluginUiTableRegistry::class)->newModel($this->getRecord(), $this->tableName());
    }

    private function tableName(): string
    {
        return (string) $this->tableDefinition['table'];
    }

    private function modelLabel(): string
    {
        return (string) ($this->tableDefinition['model_label'] ?? Str::singular($this->getTitle()));
    }
}
