<?php

namespace App\Livewire;

use App\Models\Plugin;
use App\Models\PluginTableRecord;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiTableRegistry;
use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

class PluginTableInline extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public Model $record;

    public string $tableId = '';

    /** @var array<string, mixed> */
    public array $tableDefinition = [];

    public function mount(): void
    {
        /** @var Plugin $plugin */
        $plugin = $this->record;
        $definition = app(PluginUiTableRegistry::class)->tableFor($plugin, $this->tableId);

        if ($definition !== null && Schema::hasTable((string) $definition['table'])) {
            $this->tableDefinition = $definition;
            app(PluginUiTableRegistry::class)->prefillRows($plugin, $definition);
        }
    }

    public function render(): View
    {
        return view('livewire.plugin-table-inline');
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => $this->tableQuery())
            ->columns($this->tableColumns())
            ->headerActions($this->tableHeaderActions())
            ->recordActions($this->tableRecordActions(), position: RecordActionsPosition::BeforeCells);

        $tableName = $this->tableName();

        if ($tableName && Schema::hasColumn($tableName, 'updated_at')) {
            $table->defaultSort('updated_at', 'desc');
        } elseif ($tableName && Schema::hasColumn($tableName, 'id')) {
            $table->defaultSort('id', 'desc');
        }

        return $table;
    }

    private function tableQuery(): Builder
    {
        $tableName = $this->tableName();

        /** @var Plugin $plugin */
        $plugin = $this->record;

        return $this->newModel()
            ->newQuery()
            ->when(
                $tableName && Schema::hasColumn($tableName, 'extension_plugin_id'),
                fn (Builder $query) => $query->where('extension_plugin_id', $plugin->id),
            );
    }

    /** @return array<int, TextColumn|IconColumn|ToggleColumn|SelectColumn> */
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

        return SelectColumn::make($name)
            ->label($label)
            ->placeholder($this->selectPlaceholder($column))
            ->selectablePlaceholder(! (bool) ($column['required'] ?? false))
            ->options(fn (?PluginTableRecord $record = null): array => $this->columnOptions($column, $record))
            ->state(fn (PluginTableRecord $record): mixed => data_get($record->toArray(), $name))
            ->rules([(bool) ($column['required'] ?? false) ? 'required' : 'nullable']);
    }

    private function columnState(PluginTableRecord $record, array $column): mixed
    {
        return app(PluginUiTableRegistry::class)->columnDisplayState($this->pluginRecord(), $record, $column);
    }

    /** @return array<string, string> */
    private function columnOptions(array $column, ?PluginTableRecord $record = null): array
    {
        return app(PluginUiTableRegistry::class)->columnOptions(
            $this->pluginRecord(),
            $column,
            $record?->toArray() ?? [],
        );
    }

    private function selectPlaceholder(array $column): string
    {
        return (string) ($column['placeholder'] ?? ((bool) ($column['required'] ?? false) ? __('Select an option') : __('None')));
    }

    /** @return array<int, Action> */
    private function tableHeaderActions(): array
    {
        if (empty($this->tableDefinition) || ($this->tableDefinition['create'] ?? true) === false) {
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

    /** @return array<int, Action> */
    private function tableRecordActions(): array
    {
        if (empty($this->tableDefinition)) {
            return [];
        }

        $actions = [];

        if (($this->tableDefinition['edit'] ?? true) !== false) {
            $actions[] = EditAction::make()
                ->button()
                ->hiddenLabel()
                ->size('sm')
                ->schema(fn (PluginTableRecord $record): array => $this->formComponents($record))
                ->using(function (PluginTableRecord $record, array $data): PluginTableRecord {
                    $record->update($this->payloadForSave($data));

                    return $record;
                });
        }

        if (($this->tableDefinition['delete'] ?? true) !== false) {
            $actions[] = DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->size('sm');
        }

        return $actions;
    }

    /** @return array<int, mixed> */
    private function formComponents(?PluginTableRecord $record = null): array
    {
        return app(PluginSchemaMapper::class)->componentsForFieldDefinitions(
            $this->tableDefinition['fields'] ?? [],
            existing: $record?->toArray() ?? [],
            plugin: $this->record,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payloadForSave(array $data, bool $creating = false): array
    {
        $tableName = $this->tableName();

        if ($tableName && Schema::hasColumn($tableName, 'extension_plugin_id')) {
            $data['extension_plugin_id'] = $this->record->getKey();
        }

        if ($creating && $tableName && Schema::hasColumn($tableName, 'user_id') && blank($data['user_id'] ?? null)) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    private function newModel(): PluginTableRecord
    {
        return app(PluginUiTableRegistry::class)->newModel($this->record, $this->tableName() ?? '');
    }

    private function tableName(): ?string
    {
        return filled($this->tableDefinition['table'] ?? null) ? (string) $this->tableDefinition['table'] : null;
    }

    private function modelLabel(): string
    {
        return (string) ($this->tableDefinition['model_label'] ?? Str::singular($this->tableDefinition['label'] ?? Str::headline($this->tableId)));
    }

    private function pluginRecord(): Plugin
    {
        /** @var Plugin $plugin */
        $plugin = $this->record;

        return $plugin;
    }
}
