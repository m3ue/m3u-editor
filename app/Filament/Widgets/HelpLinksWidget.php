<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class HelpLinksWidget extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.help-links-widget';

    protected function getViewData(): array
    {
        return [
            'links' => array_values(array_filter([
                [
                    'label' => __('Documentation'),
                    'icon' => 'heroicon-m-book-open',
                    'url' => config('dev.docs_url'),
                    'color' => 'gray',
                ],
                [
                    'label' => __('Discord community'),
                    'icon' => 'heroicon-m-chat-bubble-left-right',
                    'url' => config('dev.discord_url'),
                    'color' => 'gray',
                ],
                [
                    'label' => __('GitHub'),
                    'icon' => 'heroicon-m-code-bracket',
                    'url' => config('dev.repo') ? 'https://github.com/'.config('dev.repo') : null,
                    'color' => 'gray',
                ],
                [
                    'label' => __('Support the project'),
                    'icon' => 'heroicon-m-heart',
                    'url' => config('dev.kofi') ?? config('dev.donate'),
                    'color' => 'success',
                ],
            ], fn (array $link): bool => filled($link['url']))),
        ];
    }
}
