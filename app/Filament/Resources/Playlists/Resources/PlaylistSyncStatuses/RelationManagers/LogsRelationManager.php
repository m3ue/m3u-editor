<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\RelationManagers;

use App\Models\PlaylistSyncStatusLog;
use App\Tables\Columns\SyncStats;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Sync Logs'))
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Split::make([
                    TextColumn::make('name')
                        ->label(__('Item Name'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(),
                    Split::make([
                        TextColumn::make('content_type')
                            ->label(__('Content Type'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => self::contentTypeOptions()[$state] ?? $state)
                            ->color(fn (?string $state): string => match ($state) {
                                'vod' => 'info',
                                'series' => 'warning',
                                default => 'primary',
                            })
                            ->sortable()
                            ->toggleable(),
                        TextColumn::make('type')
                            ->badge()
                            ->colors([
                                'primary',
                                'primary' => 'channel',
                                'gray' => 'group',
                            ])
                            ->sortable()
                            ->searchable()
                            ->toggleable(),
                        TextColumn::make('status')
                            ->badge()
                            ->colors([
                                'primary',
                                'success' => 'added',
                                'danger' => 'removed',
                            ])
                            ->sortable()
                            ->searchable()
                            ->toggleable(),
                    ])->grow(false),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        SyncStats::make('meta')
                            ->label(__('Item Details'))
                            ->searchable(),
                    ]),
                ])->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('content_type')
                    ->label(__('Content Type'))
                    ->options(self::contentTypeOptions()),
            ])
            ->persistFiltersInSession()
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function getTabs(): array
    {
        $syncId = $this->getOwnerRecord()->getKey();

        // Keep the tab badges in line with the content type filter
        return self::setupTabs($syncId, $this->tableFilters['content_type']['value'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public static function contentTypeOptions(): array
    {
        return [
            'live' => __('Live'),
            'vod' => __('VOD'),
            'series' => __('Series'),
        ];
    }

    public static function setupTabs(int $syncId, ?string $contentType = null): array
    {
        // Count every type/status pair in one query
        $counts = PlaylistSyncStatusLog::query()
            ->where('playlist_sync_status_id', $syncId)
            ->when($contentType, fn ($query) => $query->where('content_type', $contentType))
            ->selectRaw('type, status, count(*) as aggregate')
            ->groupBy('type', 'status')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->type}.{$row->status}" => (int) $row->aggregate]);

        $tabs = [
            'added_channels' => ['label' => __('Added Channels'), 'type' => 'channel', 'status' => 'added'],
            'removed_channels' => ['label' => __('Removed Channels'), 'type' => 'channel', 'status' => 'removed'],
            'added_groups' => ['label' => __('Added Groups'), 'type' => 'group', 'status' => 'added'],
            'removed_groups' => ['label' => __('Removed Groups'), 'type' => 'group', 'status' => 'removed'],
            'added_series' => ['label' => __('Added Series'), 'type' => 'series', 'status' => 'added'],
            'removed_series' => ['label' => __('Removed Series'), 'type' => 'series', 'status' => 'removed'],
        ];

        return collect($tabs)
            ->map(fn (array $tab) => Tab::make($tab['label'])
                ->badge($counts->get("{$tab['type']}.{$tab['status']}", 0))
                ->badgeColor($tab['status'] === 'added' ? 'success' : 'danger')
                ->modifyQueryUsing(fn ($query) => $query->where([
                    'playlist_sync_status_id' => $syncId,
                    'type' => $tab['type'],
                    'status' => $tab['status'],
                ])))
            ->all();
    }
}
