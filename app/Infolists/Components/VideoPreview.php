<?php

namespace App\Infolists\Components;

use Filament\Infolists\Components\Entry;
use Illuminate\Support\Str;

class VideoPreview extends Entry
{
    protected string $view = 'infolists.components.video-preview';

    protected bool $showDetails = true;
    protected bool $autoPlayEnabled = false;

    public function withDetails(bool $withDetails = true): static
    {
        $this->showDetails = $withDetails;
        return $this;
    }

    public function autoPlay(bool $autoPlay = true): static
    {
        $this->autoPlayEnabled = $autoPlay;
        return $this;
    }

    public function isAutoPlay(): bool
    {
        return (bool) $this->evaluate($this->autoPlayEnabled);
    }

    public function isWithDetails(): bool
    {
        return (bool) $this->evaluate($this->showDetails);
    }

    public function getFormat(): string
    {
        // Determine the channel format based on URL or container extension
        $channel = $this->getRecord();
        $originalUrl = $channel->url_custom ?? $channel->url;
        if (Str::endsWith($originalUrl, '.m3u8') || Str::endsWith($originalUrl, '.ts')) {
            $extension = 'ts';
        } else {
            $extension = $channel->container_extension ?? 'ts';
        }
        return $extension;
    }
}
