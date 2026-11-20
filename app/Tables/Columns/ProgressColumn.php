<?php

namespace App\Tables\Columns;

use Closure;
use Filament\Tables\Columns\Column;

class ProgressColumn extends Column
{
    protected string $view = 'tables.columns.progress';

    protected string|Closure $color = 'primary';

    protected ?Closure $progress = null;

    public function color(string|Closure $callback): static
    {
        $this->color = $callback;

        return $this;
    }

    public function getColor(): string
    {
        return $this->evaluate($this->color);
    }

    public function progress(Closure $callback): static
    {
        $this->progress = $callback;

        return $this;
    }

    public function getProgress(): int|float
    {
        if ($this->progress === null) {
            return (int) floor($this->getStateFromRecord());
        }

        return $this->evaluate($this->progress);
    }
}
