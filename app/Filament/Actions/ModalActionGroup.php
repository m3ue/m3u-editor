<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;

/**
 * Creates an action button that opens a modal with organized action buttons.
 * Actions maintain their record context through Filament's modal action system.
 */
class ModalActionGroup extends Action
{
    protected array $childActions = [];

    protected array $sections = [];

    protected int $gridColumns = 2;

    protected ?string $customModalClass = null;

    public static function make(?string $name = null): static
    {
        $name = $name ?? 'modal_actions_'.uniqid();

        $static = parent::make($name);

        // Set defaults
        $static->label(__('Actions'));
        $static->tooltip(__('Open action menu'));
        $static->icon('heroicon-s-wrench-screwdriver');
        $static->modalIcon('heroicon-o-wrench-screwdriver');
        $static->modalHeading(__('Actions'));
        $static->modalWidth('2xl');
        $static->modalSubmitAction(false);
        $static->modalCancelActionLabel(__('Cancel'));
        $static->slideOver(condition: true); // Default to slide-over for better UX with many actions

        // Add custom class to the modal for targeting
        $static->extraModalWindowAttributes([
            'class' => 'modal-action-group',
        ]);

        // Empty action - just opens the modal
        $static->action(fn () => null);

        return $static;
    }

    public function schema(array|Closure|null $schema): static
    {
        // Ensure top-level child actions close the parent modal when they complete.
        // When actions are nested inside layout components (Section, etc.),
        // callers must call cancelParentActions() on each action themselves.
        if (is_array($schema)) {
            foreach ($schema as $component) {
                if ($component instanceof Action) {
                    $component->cancelParentActions();
                }
            }
        }

        // When schema contains only flat Actions, wrap in a grid.
        // When it contains layout components (Section, etc.), they manage their own layout.
        $allActions = is_array($schema) && collect($schema)->every(fn ($c) => $c instanceof Action);

        if ($allActions) {
            $schema = [
                Grid::make(columns: $this->gridColumns)
                    ->schema($schema),
            ];
        }

        return parent::schema($schema);
    }

    public function actions(array $actions): static
    {
        // Ensure child actions close the parent modal when they complete
        foreach ($actions as $action) {
            if ($action instanceof Action) {
                $action->cancelParentActions();
            }
        }

        $this->childActions = $actions;

        // Register these as modal footer actions with a closure
        $this->extraModalFooterActions(fn () => $this->childActions);

        return $this;
    }

    public function sections(array $sections): static
    {
        $this->sections = $sections;

        return $this;
    }

    public function gridColumns(int $columns): static
    {
        $this->gridColumns = $columns;

        return $this;
    }

    public function modalClass(string $class): static
    {
        $this->customModalClass = $class;

        $classes = 'modal-action-group';
        if ($this->customModalClass) {
            $classes .= ' '.$this->customModalClass;
        }

        $this->extraModalWindowAttributes([
            'class' => $classes,
        ]);

        return $this;
    }

    public function getChildActions(): array
    {
        return $this->childActions;
    }
}
