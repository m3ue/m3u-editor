<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Filament\Navigation\AdminNavigationLayout;
use App\Filament\Navigation\AdminNavigationSchema;
use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ManageNavigationSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $slug = 'navigation';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Navigation Menu');
    }

    public function getTitle(): string
    {
        return __('Navigation Menu');
    }

    public function getSubheading(): ?string
    {
        return __('Hide, show, and reorder the admin sidebar for every user. Names and icons are fixed.');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            Action::make('restore_default')
                ->label(__('Restore Default'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Restore Default navigation'))
                ->modalDescription(__('This resets the navigation menu to the original default order, with everything visible, for all users.'))
                ->modalSubmitActionLabel(__('Restore Default'))
                ->action(function (): void {
                    $settings = app(GeneralSettings::class);
                    $settings->admin_nav_layout = null;
                    $settings->admin_nav_active_preset = 'default';
                    $settings->save();

                    $this->fillForm();

                    Notification::make()
                        ->success()
                        ->title(__('Navigation restored to Default'))
                        ->send();
                }),
            Action::make('restore_simplified_default')
                ->label(__('Restore Simplified Default'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Restore Simplified Default navigation'))
                ->modalDescription(__('This applies the Simplified Default navigation layout for all users.'))
                ->modalSubmitActionLabel(__('Restore Simplified Default'))
                ->action(function (): void {
                    $settings = app(GeneralSettings::class);
                    $settings->admin_nav_layout = $settings->admin_nav_simplified_layout
                        ?: AdminNavigationLayout::canonicalSnapshot($this->resolvePanelSettings($settings->toArray()));
                    $settings->admin_nav_active_preset = 'simplified';
                    $settings->save();

                    $this->fillForm();

                    Notification::make()
                        ->success()
                        ->title(__('Navigation set to Simplified Default'))
                        ->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('groups')
                    ->hiddenLabel()
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->addable(false)
                    ->deletable(false)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema($this->rowSchema())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<Component>
     */
    private function rowSchema(bool $nested = false): array
    {
        // Icon/label and the visibility toggle share one narrow row (a Grid) instead of
        // stacking, since the only thing an admin actually edits per row is visibility.
        $schema = [
            Hidden::make('key'),
            Hidden::make('label'),
            Hidden::make('icon'),
            Grid::make(12)
                ->schema([
                    View::make('filament.forms.components.nav-item-label')
                        ->viewData(fn (Get $get): array => [
                            'icon' => $get('icon'),
                            'label' => $get('label'),
                        ])
                        ->columnSpan(10),
                    Toggle::make('visible')
                        ->label(__('Visible'))
                        ->columnSpan(2),
                ]),
        ];

        if (! $nested) {
            $schema[] = Repeater::make('items')
                ->hiddenLabel()
                ->reorderable()
                ->reorderableWithButtons()
                ->addable(false)
                ->deletable(false)
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->schema($this->rowSchema(nested: true))
                ->columnSpanFull();
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $panelSettings = $this->resolvePanelSettings($data);
        $schema = AdminNavigationSchema::groups($panelSettings);

        $layout = $data['admin_nav_layout'] ?: (
            ($data['admin_nav_active_preset'] ?? null) === 'simplified'
                ? ($data['admin_nav_simplified_layout'] ?? [])
                : []
        );
        $layout ??= [];

        $available = array_filter($schema, fn (array $group) => $group['available']());
        $groupOrder = AdminNavigationLayout::mergeOrder(array_keys($available), $layout['groups']['order'] ?? []);
        $hiddenGroups = $layout['groups']['hidden'] ?? [];

        $data['groups'] = [];

        foreach ($groupOrder as $groupKey) {
            $groupDef = $available[$groupKey];
            $itemLayout = $layout['items'][$groupKey] ?? [];

            $data['groups'][] = [
                'key' => $groupKey,
                'label' => value($groupDef['label']),
                'icon' => $groupDef['icon'],
                'visible' => ! in_array($groupKey, $hiddenGroups, true),
                'items' => $this->describeItems($groupDef['items'], $itemLayout),
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, array{available?: \Closure, resolve: \Closure}>  $itemDefs
     * @param  array{order?: list<string>, hidden?: list<string>}  $itemLayout
     * @return list<array{key: string, label: string, icon: string|null, visible: bool}>
     */
    private function describeItems(array $itemDefs, array $itemLayout): array
    {
        $available = array_filter(
            $itemDefs,
            fn (array $item) => ! isset($item['available']) || $item['available']()
        );

        $order = AdminNavigationLayout::mergeOrder(array_keys($available), $itemLayout['order'] ?? []);
        $hidden = $itemLayout['hidden'] ?? [];

        $items = [];
        foreach ($order as $itemKey) {
            $resolved = $available[$itemKey]['resolve']();
            $first = $resolved[0] ?? null;
            $icon = $first?->getIcon();

            $items[] = [
                'key' => $itemKey,
                'label' => $first?->getLabel() ?? $itemKey,
                'icon' => is_string($icon) ? $icon : null,
                'visible' => ! in_array($itemKey, $hidden, true),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $groups = $data['groups'] ?? [];
        unset($data['groups']);

        $layout = [
            'groups' => [
                'order' => array_column($groups, 'key'),
                'hidden' => array_column(array_filter($groups, fn (array $group) => ! $group['visible']), 'key'),
            ],
            'items' => [],
        ];

        foreach ($groups as $group) {
            $items = $group['items'] ?? [];

            $layout['items'][$group['key']] = [
                'order' => array_column($items, 'key'),
                'hidden' => array_column(array_filter($items, fn (array $item) => ! $item['visible']), 'key'),
            ];
        }

        $data['admin_nav_layout'] = $layout;
        $data['admin_nav_active_preset'] = 'custom';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolvePanelSettings(array $data): array
    {
        return [
            'push_relay_enabled' => $data['push_relay_enabled'] ?? true,
            'device_pairing_enabled' => $data['device_pairing_enabled'] ?? true,
        ];
    }
}
