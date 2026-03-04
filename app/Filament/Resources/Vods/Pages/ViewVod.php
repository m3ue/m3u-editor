<?php

namespace App\Filament\Resources\Vods\Pages;

use App\Filament\Resources\Vods\VodResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewVod extends ViewRecord
{
    protected static string $resource = VodResource::class;

    protected string $view = 'filament.resources.vods.pages.view-vod';

    public function getTitle(): string|Htmlable
    {
        return $this->record->title_custom ?? $this->record->title ?? $this->record->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $parts = [];

        $info = $this->record->info ?? [];
        $movieData = $this->record->movie_data ?? [];

        if ($this->record->year) {
            $parts[] = $this->record->year;
        } elseif (! empty($info['releasedate']) || ! empty($movieData['info']['releasedate'] ?? null)) {
            $releaseDate = $info['releasedate'] ?? $movieData['info']['releasedate'] ?? null;
            if ($releaseDate) {
                $parts[] = substr($releaseDate, 0, 4);
            }
        }

        if (! empty($info['genre']) || ! empty($movieData['info']['genre'] ?? null)) {
            $parts[] = $info['genre'] ?? $movieData['info']['genre'];
        }

        if (! empty($info['rating']) || ! empty($movieData['info']['rating'] ?? null)) {
            $parts[] = '★ '.($info['rating'] ?? $movieData['info']['rating']);
        }

        return implode(' • ', $parts) ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to VOD')
                ->url(VodResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray'),
            Actions\EditAction::make()
                ->label('Edit VOD')
                ->slideOver()
                ->color('gray')
                ->icon('heroicon-s-pencil'),
            Actions\Action::make('toggle_enabled')
                ->label(fn () => $this->record->enabled ? 'Disable VOD' : 'Enable VOD')
                ->icon(fn () => $this->record->enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn () => $this->record->enabled ? 'danger' : 'success')
                ->action(function () {
                    $this->record->update(['enabled' => ! $this->record->enabled]);
                    $this->refreshFormData(['enabled']);
                })
                ->requiresConfirmation(),
            Actions\Action::make('play')
                ->label('Play')
                ->icon('heroicon-s-play')
                ->color('primary')
                ->dispatch('openFloatingStream', [[
                    'id' => $this->record->id,
                    'title' => $this->record->title_custom ?? $this->record->title ?? $this->record->name,
                    'url' => route('m3u-proxy.channel.player', ['id' => $this->record->id]),
                    'format' => $this->record->container_extension ?? 'ts',
                    'type' => 'channel',
                ]]),
        ];
    }
}
