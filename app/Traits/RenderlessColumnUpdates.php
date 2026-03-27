<?php

namespace App\Traits;

use Livewire\Attributes\Renderless;

/**
 * Makes interactive table column updates (toggle, text input, select)
 * skip Livewire's full component re-render. The Alpine.js client handles
 * the visual update, so re-rendering the entire table HTML is unnecessary.
 */
trait RenderlessColumnUpdates
{
    #[Renderless]
    public function updateTableColumnState(string $column, string $record, mixed $input): mixed
    {
        return parent::updateTableColumnState($column, $record, $input);
    }
}
