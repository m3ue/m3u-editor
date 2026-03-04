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
        $static->label('Actions');
        $static->tooltip('Open action menu');
        $static->icon('heroicon-s-wrench-screwdriver');
        $static->modalIcon('heroicon-o-wrench-screwdriver');
        $static->modalHeading('Actions');
        $static->modalWidth('2xl');
        $static->modalSubmitAction(false);
        $static->modalCancelActionLabel('Cancel');
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
        // Wrap the schema in our grid layout
        $schema = [
            Grid::make(columns: $this->gridColumns)
                ->schema($schema),
        ];

        return parent::schema($schema);
    }

    public function actions(array $actions): static
    {
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
