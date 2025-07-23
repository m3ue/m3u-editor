<?php

namespace App\Filament\Infolists\Components;

use Filament\Infolists\Components\Component;

class EpgViewer extends Component
{
    protected string $view = 'filament.infolists.components.epg-viewer';

    public static function make(): static
    {
        return app(static::class);
    }

    public function epgUuid(string $uuid): static
    {
        $this->state(['epg_uuid' => $uuid]);
        return $this;
    }
}
