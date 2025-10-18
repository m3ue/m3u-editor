<?php

namespace App\Infolists\Components;

use Filament\Infolists\Components\Entry;
use Illuminate\Support\Str;

class SeriesPreview extends Entry
{
    protected string $view = 'infolists.components.series-preview';

    public function getFormat(): string
    {
        $episode = $this->getRecord();
        $originalUrl = $episode->url;
        if (Str::endsWith($originalUrl, '.m3u8') || Str::endsWith($originalUrl, '.ts')) {
            $extension = 'ts';
        } else {
            $extension = $episode->container_extension ?? 'ts';
        }
        return $extension;
    }
}
