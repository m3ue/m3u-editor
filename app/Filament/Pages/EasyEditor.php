<?php

namespace App\Filament\Pages;

use App\Models\Playlist;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * A single-screen, drill-down editor for a playlist: pick a playlist (and Live
 * or VOD) up top, browse its groups on the left, then edit and sort the
 * channels for the selected group on the right. Channels are always edited one
 * group at a time - a whole playlist can hold tens of thousands of channels,
 * which is unworkable to render or drag-sort in one list.
 *
 * Composed almost entirely from the existing Channel/Vod/Group Filament pieces
 * so behaviour matches the full editors.
 */
class EasyEditor extends Page
{
    protected string $view = 'filament.pages.easy-editor';

    protected static ?int $navigationSort = 1;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Use the sort buttons on the tables to enable drag-and-drop sorting. Sort groups and channels as needed to define the output order of your playlist.');
    }

    #[Url]
    public ?int $playlistId = null;

    /** "live" or "vod". */
    #[Url]
    public string $contentType = 'live';

    /** Null means no group is selected yet; the channels pane stays hidden. */
    #[Url]
    public ?int $selectedGroupId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Easy Editor');
    }

    public function getTitle(): string
    {
        return __('Easy Editor');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        if (! in_array($this->contentType, ['live', 'vod'], true)) {
            $this->contentType = 'live';
        }

        if ($this->playlistId !== null && ! $this->userPlaylists()->has($this->playlistId)) {
            $this->playlistId = null;
        }

        $this->playlistId ??= $this->userPlaylists()->keys()->first();

        $this->playlistPickerForm->fill([
            'playlistId' => $this->playlistId,
            'contentType' => $this->contentType,
        ]);
    }

    public function playlistPickerForm(Schema $schema): Schema
    {
        return $schema
            ->statePath(null)
            ->components([
                Select::make('playlistId')
                    ->label(__('Playlist'))
                    ->options(fn (): array => $this->userPlaylists()->all())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->selectGroup(null)),
                ToggleButtons::make('contentType')
                    ->label(__('Content'))
                    ->options([
                        'live' => __('Live TV'),
                        'vod' => __('VOD'),
                    ])
                    ->icons([
                        'live' => 'heroicon-o-tv',
                        'vod' => 'heroicon-o-film',
                    ])
                    ->inline()
                    ->live()
                    ->afterStateUpdated(fn () => $this->selectGroup(null)),
            ])
            ->columns(['default' => 1, 'sm' => 2]);
    }

    /**
     * Keep the page's own copy of the selection in sync so the heading and the
     * URL reflect whatever the groups pane last emitted.
     */
    #[On('easy-editor-group-selected')]
    public function syncSelectedGroup(?int $groupId = null): void
    {
        $this->selectedGroupId = $groupId;
    }

    public function selectGroup(?int $groupId): void
    {
        $this->selectedGroupId = $groupId;
        $this->dispatch('easy-editor-group-selected', groupId: $groupId);
    }

    /**
     * @return Collection<int, string>
     */
    protected function userPlaylists(): Collection
    {
        return Playlist::query()
            ->where('user_id', auth()->id())
            ->orderBy('name')
            ->pluck('name', 'id');
    }
}
