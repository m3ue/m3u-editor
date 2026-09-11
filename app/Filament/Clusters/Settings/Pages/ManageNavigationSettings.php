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
use Filament\Schemas\Components\Callout;
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
        return __('Navigation');
    }

    public function getTitle(): string
    {
        return __('Navigation');
    }

    public function getSubheading(): ?string
    {
        return __('Hide, show, and reorder the admin sidebar for every user. Names and icons are fixed.');
    }

    protected function getHeaderActions(): array
    {
        return [
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
                ->label(__('Use Simplified Default'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Use Simplified Default navigation'))
                ->modalDescription(__('This applies the Simplified Default navigation layout for all users.'))
                ->modalSubmitActionLabel(__('Use Simplified Default'))
                ->action(function (): void {
                    $settings = app(GeneralSettings::class);
                    $settings->admin_nav_layout = AdminNavigationLayout::simplifiedDefault($this->resolvePanelSettings($settings->toArray()));
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
                Callout::make()
                    ->info()
                    ->columnSpanFull()
                    ->description(__('Drag and drop to reorder groups and items. Toggle visibility to hide or show them. You will need to refresh the page to see the changes take effect.')),
                Repeater::make('groups')
                    ->hiddenLabel()
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->addable(false)
                    ->deletable(false)
                    ->collapsible()
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
        // The toggle is only made live() at the group level, where it needs to hide the
        // nested items when the group itself is turned off - toggling a leaf item needs
        // no reactivity, so it stays a plain (non-live) field there.
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
                        ->live(! $nested)
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
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->schema($this->rowSchema(nested: true))
                // A disabled group's items are irrelevant until it's turned back on.
                ->hidden(fn (Get $get): bool => ! $get('visible'))
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
                ? AdminNavigationLayout::simplifiedDefault($panelSettings)
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

        $data['admin_nav_layout'] = $this->buildLayout($groups);
        $data['admin_nav_active_preset'] = 'custom';

        return $data;
    }

    /**
     * Collapse the editor's repeater rows back into the stored
     * order/hidden shape, for both the live layout and the Simplified
     * Default preset snapshot.
     *
     * @param  list<array{key: string, visible: bool, items?: list<array{key: string, visible: bool}>}>  $groups
     * @return array{groups: array{order: list<string>, hidden: list<string>}, items: array<string, array{order: list<string>, hidden: list<string>}>}
     */
    private function buildLayout(array $groups): array
    {
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

        return $layout;
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
