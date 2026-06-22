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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PluginTableInline extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public Model $record;

    #[Locked]
    public string $tableId = '';

    #[Locked]
    public ?int $runId = null;

    #[Locked]
    public ?int $playlistId = null;

    #[Locked]
    public bool $readOnly = false;

    #[Locked]
    public bool $showHeading = false;

    /** @var array<string, mixed> */
    #[Locked]
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
            ->heading($this->tableHeading())
            ->description($this->tableDescription())
            ->columns($this->tableColumns())
            ->filters($this->tableFilters())
            ->headerActions($this->tableHeaderActions())
            ->recordActions($this->tableRecordActions(), position: RecordActionsPosition::BeforeCells);

        $tableName = $this->tableName();

        if ($this->showHeading && ! empty($this->tableDefinition)) {
            $table->heading((string) ($this->tableDefinition['label'] ?? Str::headline($this->tableId)));

            if (filled($this->tableDefinition['description'] ?? null)) {
                $table->description((string) $this->tableDefinition['description']);
            }
        }

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

        return app(PluginUiTableRegistry::class)->applyTableScope(
            $this->newModel()->newQuery(),
            $plugin,
            $tableName ?? '',
            $this->runId,
            $this->playlistId,
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

                if (! $this->readOnly && (bool) ($column['editable'] ?? false)) {
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
        if (empty($this->tableDefinition)) {
            return [];
        }

        $actions = [];

        if (! $this->readOnly && ($this->tableDefinition['create'] ?? true) !== false) {
            $actions[] = CreateAction::make()
                ->model(PluginTableRecord::class)
                ->label(__('New :model', ['model' => $this->modelLabel()]))
                ->schema(fn (): array => $this->formComponents())
                ->using(fn (array $data): Model => $this->newModel()->newQuery()->create($this->payloadForSave($data, creating: true)));
        }

        return [
            ...$actions,
            ...$this->exportActions(),
        ];
    }

    /** @return array<int, Action> */
    private function tableRecordActions(): array
    {
        if (empty($this->tableDefinition) || $this->readOnly) {
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
            $actions[] = $this->deleteAction();
        }

        return $actions;
    }

    private function deleteAction(): DeleteAction
    {
        $action = DeleteAction::make()
            ->button()
            ->hiddenLabel()
            ->size('sm');

        $registry = app(PluginUiTableRegistry::class);

        if (! $registry->clearsRecordOnDelete($this->tableDefinition)) {
            return $action;
        }

        return $registry->decorateClearAction($action, $this->tableDefinition, $this->modelLabel());
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

        if ($this->runId !== null && $tableName && Schema::hasColumn($tableName, 'extension_plugin_run_id')) {
            $data['extension_plugin_run_id'] = $this->runId;
        }

        if ($this->playlistId !== null && $tableName && Schema::hasColumn($tableName, 'playlist_id')) {
            $data['playlist_id'] = $this->playlistId;
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

    private function tableHeading(): ?string
    {
        return filled($this->tableDefinition['label'] ?? null) ? (string) $this->tableDefinition['label'] : null;
    }

    private function tableDescription(): ?string
    {
        return filled($this->tableDefinition['description'] ?? null) ? (string) $this->tableDefinition['description'] : null;
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

    /** @return array<int, SelectFilter> */
    private function tableFilters(): array
    {
        $tableName = $this->tableName();
        if (! $tableName) {
            return [];
        }

        $filters = [];

        if ($this->runId === null && Schema::hasColumn($tableName, 'extension_plugin_run_id')) {
            $filters[] = SelectFilter::make('extension_plugin_run_id')
                ->label(__('Run'))
                ->options(fn (): array => $this->runFilterOptions());
        }

        if ($this->playlistId === null && Schema::hasColumn($tableName, 'playlist_id')) {
            $filters[] = SelectFilter::make('playlist_id')
                ->label(__('Playlist'))
                ->options(fn (): array => $this->playlistFilterOptions());
        }

        foreach (['result_type' => __('Type'), 'decision' => __('Decision')] as $column => $label) {
            if (Schema::hasColumn($tableName, $column)) {
                $filters[] = SelectFilter::make($column)
                    ->label($label)
                    ->options(fn (): array => $this->filterOptions($column));
            }
        }

        return $filters;
    }

    /** @return array<int, Action> */
    private function exportActions(): array
    {
        if (empty($this->tableDefinition) || ! $this->tableName()) {
            return [];
        }

        return collect(app(PluginUiTableRegistry::class)->exportFormatsFor($this->tableDefinition))
            ->map(fn (string $format): Action => Action::make("export_{$format}")
                ->label(__(Str::upper($format)))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => $this->exportUrl($format)))
            ->all();
    }

    private function exportUrl(string $format): string
    {
        return route('extension-plugins.tables.export', array_filter([
            'plugin' => $this->pluginRecord(),
            'table' => $this->tableId,
            'format' => $format,
            'run' => $this->runId,
            'playlist' => $this->playlistId,
        ], fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, string> */
    private function runFilterOptions(): array
    {
        $options = $this->filterOptions('extension_plugin_run_id');
        $ids = array_keys($options);
        if ($ids === []) {
            return [];
        }

        return DB::table('extension_plugin_runs')
            ->whereIn('id', $ids)
            ->orderByDesc('id')
            ->get(['id', 'action', 'hook', 'status'])
            ->mapWithKeys(function (object $run): array {
                $label = $run->action ?: $run->hook ?: 'plugin run';

                return [(string) $run->id => '#'.$run->id.' '.Str::headline($label).' ('.Str::headline((string) $run->status).')'];
            })
            ->union($options)
            ->all();
    }

    /** @return array<string, string> */
    private function playlistFilterOptions(): array
    {
        $options = $this->filterOptions('playlist_id');
        $ids = array_keys($options);
        if ($ids === [] || ! Schema::hasTable('playlists')) {
            return $options;
        }

        $labels = DB::table('playlists')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $label, mixed $id): array => [(string) $id => (string) $label]);

        return collect($ids)
            ->mapWithKeys(fn (string $id): array => [$id => $labels[$id] ?? "#{$id}"])
            ->all();
    }

    /** @return array<string, string> */
    private function filterOptions(string $column): array
    {
        $tableName = $this->tableName();
        if (! $tableName) {
            return [];
        }

        return app(PluginUiTableRegistry::class)->distinctColumnOptions(
            $this->pluginRecord(),
            $tableName,
            $column,
            $this->runId,
            $this->playlistId,
        );
    }
}
